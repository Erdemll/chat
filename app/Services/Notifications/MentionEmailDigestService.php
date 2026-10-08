<?php

namespace App\Services\Notifications;

use App\Models\MessageMention;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\MentionDigestNotification;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

class MentionEmailDigestService
{
    /** @return array{users_processed: int, emails_sent: int, mentions_processed: int, failed: int} */
    public function run(): array
    {
        $summary = ['users_processed' => 0, 'emails_sent' => 0, 'mentions_processed' => 0, 'failed' => 0];
        $cutoff = now()->subMinutes(max(0, (int) config('notifications.mention_email.delay_minutes')));
        $users = User::query()->where('is_active', true)
            ->whereIn('id', $this->eligibleMentions($cutoff)->select('message_mentions.user_id'))
            ->lazyById(100);

        foreach ($users as $user) {
            $token = (string) Str::uuid();
            $failuresBeforeUser = $summary['failed'];

            try {
                if (! $this->claimQuery($user->id)->insertOrIgnore([
                    'user_id' => $user->id,
                    'type' => NotificationDelivery::TYPE_MENTION,
                    'channel' => NotificationDelivery::CHANNEL_EMAIL,
                    'token' => $token,
                    'created_at' => now(),
                ])) {
                    continue;
                }

                $summary['users_processed']++;
                $this->sendForUser($user, $cutoff, $summary);
            } catch (Throwable $exception) {
                $summary['failed']++;
                $this->logFailure($user->id, 0, $exception);
            }

            try {
                if (! $this->deliveriesFor($user->id)->where('status', NotificationDelivery::STATUS_PENDING)->exists()) {
                    $this->claimQuery($user->id)->where('token', $token)->delete();
                }
            } catch (Throwable $exception) {
                if ($summary['failed'] === $failuresBeforeUser) {
                    $summary['failed']++;
                }
                $this->logFailure($user->id, 0, $exception);
            }
        }

        return $summary;
    }

    /** @return Builder<MessageMention> */
    private function unreadMentions(CarbonInterface $cutoff): Builder
    {
        return MessageMention::query()
            ->where('message_mentions.created_at', '<=', $cutoff)
            ->whereHas('user', fn (Builder $users) => $users->where('is_active', true))
            ->whereHas('message')
            ->whereNotExists(function (QueryBuilder $reads): void {
                $reads->selectRaw('1')->from('message_reads')
                    ->whereColumn('message_reads.message_id', 'message_mentions.message_id')
                    ->whereColumn('message_reads.user_id', 'message_mentions.user_id');
            });
    }

    /** @return Builder<MessageMention> */
    private function eligibleMentions(CarbonInterface $cutoff): Builder
    {
        return $this->unreadMentions($cutoff)->whereNotExists(function (QueryBuilder $deliveries): void {
            $deliveries->selectRaw('1')->from('notification_deliveries')
                ->whereColumn('notification_deliveries.user_id', 'message_mentions.user_id')
                ->whereColumn('notification_deliveries.reference_id', 'message_mentions.message_id')
                ->where('type', NotificationDelivery::TYPE_MENTION)
                ->where('channel', NotificationDelivery::CHANNEL_EMAIL)
                ->where('reference_type', NotificationDelivery::REFERENCE_MESSAGE)
                ->where(function (QueryBuilder $blocked): void {
                    $blocked->where('status', '!=', NotificationDelivery::STATUS_FAILED)
                        ->orWhere('attempt_count', '>=', max(1, (int) config('notifications.mention_email.max_attempts')));
                });
        });
    }

    /**
     * @param  array{users_processed: int, emails_sent: int, mentions_processed: int, failed: int}  $summary
     */
    private function sendForUser(User $user, CarbonInterface $cutoff, array &$summary): void
    {
        $messageIds = array_values($this->eligibleMentions($cutoff)->where('user_id', $user->id)
            ->pluck('message_id')->map(fn (int $messageId): int => $messageId)->all());
        if ($messageIds === []) {
            return;
        }

        $this->reserveDeliveries($user->id, $messageIds);
        $recipient = User::query()->where('is_active', true)->find($user->id);
        $mentions = $this->unreadMentions($cutoff)->where('user_id', $user->id)
            ->whereIn('message_id', $messageIds)
            ->with(['message:id,user_id,body,created_at', 'message.user:id,name'])
            ->orderBy('message_mentions.created_at')->orderBy('message_mentions.id')->get();
        $remainingIds = $recipient === null ? [] : array_values($mentions->map(fn (MessageMention $mention): int => $mention->message_id)->all());
        $this->releaseUnsentDeliveries($user->id, array_values(array_diff($messageIds, $remainingIds)));

        if ($recipient === null || $mentions->isEmpty()) {
            return;
        }

        $deliveries = $this->deliveriesFor($user->id)->whereIn('reference_id', $remainingIds)
            ->where('status', NotificationDelivery::STATUS_PENDING);
        $deliveries->update([
            'attempt_count' => DB::raw('attempt_count + 1'),
            'last_attempt_at' => now(),
            'updated_at' => now(),
        ]);
        $unreadIds = array_values($this->unreadMentions($cutoff)->where('user_id', $user->id)
            ->whereIn('message_id', $remainingIds)->pluck('message_id')->map(fn (int $messageId): int => $messageId)->all());
        $removedIds = array_values(array_diff($remainingIds, $unreadIds));
        $this->releaseUnsentDeliveries($user->id, $removedIds, attemptReserved: true);
        $mentions = $mentions->whereIn('message_id', $unreadIds)->values();
        if ($mentions->isEmpty()) {
            return;
        }

        $deliveries = $this->deliveriesFor($user->id)->whereIn('reference_id', $unreadIds)
            ->where('status', NotificationDelivery::STATUS_PENDING);
        $summary['mentions_processed'] += $mentions->count();

        try {
            Notification::sendNow($recipient, new MentionDigestNotification($mentions));
        } catch (Throwable $exception) {
            $deliveries->update([
                'status' => NotificationDelivery::STATUS_FAILED,
                'failed_at' => now(),
                'last_error' => $this->safeExceptionType($exception),
                'updated_at' => now(),
            ]);
            $summary['failed']++;
            $this->logFailure($user->id, $mentions->count(), $exception);

            return;
        }

        $deliveries->update([
            'status' => NotificationDelivery::STATUS_SENT,
            'sent_at' => now(),
            'failed_at' => null,
            'last_error' => null,
            'updated_at' => now(),
        ]);
        $summary['emails_sent']++;
        Log::info('Mention digest sent', ['user_id' => $user->id, 'mention_count' => $mentions->count()]);
    }

    /** @param list<int> $messageIds */
    private function reserveDeliveries(int $userId, array $messageIds): void
    {
        DB::transaction(function () use ($userId, $messageIds): void {
            $createdAt = now();
            $rows = array_map(fn (int $messageId): array => [
                'user_id' => $userId,
                'type' => NotificationDelivery::TYPE_MENTION,
                'channel' => NotificationDelivery::CHANNEL_EMAIL,
                'reference_type' => NotificationDelivery::REFERENCE_MESSAGE,
                'reference_id' => $messageId,
                'status' => NotificationDelivery::STATUS_PENDING,
                'attempt_count' => 0,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ], $messageIds);

            foreach (array_chunk($rows, 100) as $chunk) {
                NotificationDelivery::query()->insertOrIgnore($chunk);
            }

            $this->deliveriesFor($userId)->whereIn('reference_id', $messageIds)
                ->where('status', NotificationDelivery::STATUS_FAILED)
                ->where('attempt_count', '<', max(1, (int) config('notifications.mention_email.max_attempts')))
                ->update(['status' => NotificationDelivery::STATUS_PENDING, 'updated_at' => $createdAt]);
        });
    }

    /** @param list<int> $messageIds */
    private function releaseUnsentDeliveries(int $userId, array $messageIds, bool $attemptReserved = false): void
    {
        if ($messageIds === []) {
            return;
        }

        DB::transaction(function () use ($userId, $messageIds, $attemptReserved): void {
            $pending = $this->deliveriesFor($userId)->whereIn('reference_id', $messageIds)
                ->where('status', NotificationDelivery::STATUS_PENDING);
            if ($attemptReserved) {
                $pending->update([
                    'attempt_count' => DB::raw('attempt_count - 1'),
                    'last_attempt_at' => DB::raw('failed_at'),
                    'updated_at' => now(),
                ]);
            }
            (clone $pending)->where('attempt_count', 0)->delete();
            $pending->where('attempt_count', '>', 0)
                ->update(['status' => NotificationDelivery::STATUS_FAILED, 'updated_at' => now()]);
        });
    }

    /** @return Builder<NotificationDelivery> */
    private function deliveriesFor(int $userId): Builder
    {
        return NotificationDelivery::query()->where('user_id', $userId)
            ->where('type', NotificationDelivery::TYPE_MENTION)
            ->where('channel', NotificationDelivery::CHANNEL_EMAIL)
            ->where('reference_type', NotificationDelivery::REFERENCE_MESSAGE);
    }

    /**
     * A durable claim has no lease expiry: a crashed process may already have sent
     * mail. Uncertain pending deliveries need reconciliation before claim removal.
     */
    private function claimQuery(int $userId): QueryBuilder
    {
        return DB::table('notification_delivery_claims')->where('user_id', $userId)
            ->where('type', NotificationDelivery::TYPE_MENTION)
            ->where('channel', NotificationDelivery::CHANNEL_EMAIL);
    }

    /** Persist exception types only; provider messages can contain credentials. */
    private function safeExceptionType(Throwable $exception): string
    {
        $class = $exception::class;

        return Str::limit(str_contains($class, '@anonymous') ? 'AnonymousException' : $class, 255, '');
    }

    private function logFailure(int $userId, int $mentionCount, Throwable $exception): void
    {
        Log::warning('Mention digest failed', [
            'user_id' => $userId,
            'mention_count' => $mentionCount,
            'exception_type' => $this->safeExceptionType($exception),
        ]);
    }
}
