<?php

namespace App\Services;

use App\Events\MessageCreated;
use App\Events\MessageDeleted;
use App\Events\MessageUpdated;
use App\Exceptions\MessageRejectedException;
use App\Http\Resources\MessageResource;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Services\Moderation\MessageModerationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class MessageService
{
    public function __construct(private readonly MessageModerationService $moderation, private readonly MessageMentionService $mentions, private readonly UserActivityService $activity) {}

    /**
     * @param  list<int>|null  $messageIds
     * @return array{data: array<int, array<string, mixed>>, has_more: bool, before_id: ?int, after_id: ?int}
     */
    public function history(Channel $channel, ?int $beforeId = null, ?int $afterId = null, ?array $messageIds = null): array
    {
        $limit = $messageIds === null ? (int) config('chat.page_size') : count($messageIds);
        $rows = $channel->messages()->with(['user:id,name', 'mentionedUsers:users.id,name'])->withCount('reads')
            ->when($beforeId !== null, fn ($query) => $query->where('id', '<', $beforeId))
            ->when($afterId !== null, fn ($query) => $query->where('id', '>', $afterId))
            ->when($messageIds !== null, fn ($query) => $query->whereIn('id', $messageIds))
            ->orderBy('id', $afterId === null ? 'desc' : 'asc')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $afterId === null ? $rows->take($limit)->reverse()->values() : $rows->take($limit)->values();

        return ['data' => MessageResource::collection($rows)->resolve(), 'has_more' => $hasMore, 'before_id' => $rows->first()?->id, 'after_id' => $rows->last()?->id];
    }

    /**
     * @param  list<int>  $mentionIds
     * @return array{message: Message, realtime: bool}
     */
    public function send(User $user, Channel $channel, string $body, array $mentionIds = []): array
    {
        $this->ensureAcceptableBody($user, $body);

        $message = DB::transaction(function () use ($user, $channel, $body, $mentionIds): Message {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($user->is_active, 403);
            $message = new Message(['body' => $body]);
            $message->user()->associate($user);
            $message->channel()->associate($channel);
            $message->save();
            $this->mentions->attach($message, $user, $mentionIds);
            $this->activity->record($user);

            return $message->load(['user:id,name', 'mentionedUsers:users.id,name'])->loadCount('reads');
        });
        try {
            event(new MessageCreated($message));
        } catch (Throwable $exception) {
            Log::error('Message broadcast failed', ['message_id' => $message->id, 'exception_type' => $exception::class]);

            return ['message' => $message, 'realtime' => false];
        }

        return ['message' => $message, 'realtime' => config('broadcasting.default') === 'reverb'];
    }

    /** @return array{realtime: bool} */
    public function delete(User $user, Message $message): array
    {
        $realtime = config('broadcasting.default') === 'reverb';
        DB::transaction(function () use ($user, $message, &$realtime): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($user->is_active, 403);
            $message = Message::query()->lockForUpdate()->findOrFail($message->id);
            Gate::forUser($user)->authorize('delete', $message);
            $message->delete();
            $message->mentions()->delete();
            $message->reads()->delete();
            $this->broadcastAfterCommit(new MessageDeleted($message->id, $message->channel_id), $realtime);
        });

        return ['realtime' => $realtime];
    }

    /**
     * @param  list<int>  $mentionIds
     * @return array{message: Message, realtime: bool}
     */
    public function update(User $user, Message $message, string $body, array $mentionIds = [], ?string $expectedBody = null): array
    {
        $realtime = config('broadcasting.default') === 'reverb';
        $message = DB::transaction(function () use ($user, $message, $body, $mentionIds, $expectedBody, &$realtime): Message {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($user->is_active, 403);
            $message = Message::query()->lockForUpdate()->findOrFail($message->id);
            Gate::forUser($user)->authorize('update', $message);
            if ($expectedBody !== null && $expectedBody !== $message->body) {
                throw ValidationException::withMessages([
                    'body' => 'Mesaj değişti. Güncel mesajı kontrol edip yeniden düzenleyin.',
                ])->status(409);
            }
            $this->ensureAcceptableBody($user, $body);
            $message->body = $body;
            $message->edited_at = now();
            $message->save();
            $this->mentions->sync($message, $user, $mentionIds);
            $this->broadcastAfterCommit(new MessageUpdated($message->id, $message->channel_id), $realtime);

            return $message->load(['user:id,name', 'mentionedUsers:users.id,name'])->loadCount('reads');
        });

        return ['message' => $message, 'realtime' => $realtime];
    }

    private function broadcastAfterCommit(MessageDeleted|MessageUpdated $event, bool &$realtime): void
    {
        DB::afterCommit(function () use ($event, &$realtime): void {
            try {
                event($event);
            } catch (Throwable $exception) {
                $realtime = false;
                Log::error('Message broadcast failed', ['message_id' => $event->messageId, 'exception_type' => $exception::class]);
            }
        });
    }

    private function ensureAcceptableBody(User $user, string $body): void
    {
        $result = $this->moderation->moderate($body);

        if ($result->blocked()) {
            Log::notice('Message blocked by moderation', [
                'user_id' => $user->id,
                'category' => $result->category,
                'exception_type' => MessageRejectedException::class,
            ]);

            throw new MessageRejectedException($result);
        }
    }
}
