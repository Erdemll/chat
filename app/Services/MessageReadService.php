<?php

namespace App\Services;

use App\Events\MessageReadsUpdated;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class MessageReadService
{
    /**
     * @param  list<int>  $messageIds
     * @return list<array{message_id: int, read_count: int}>
     */
    public function markAsRead(User $user, array $messageIds): array
    {
        return DB::transaction(function () use ($user, $messageIds): array {
            $eligibleIds = Message::query()->visibleTo($user)
                ->whereIn('id', $messageIds)
                ->where('user_id', '!=', $user->id)
                ->whereDoesntHave('reads', fn (Builder $reads) => $reads->where('user_id', $user->id))
                ->orderBy('id')->lockForUpdate()->get(['id'])->pluck('id');

            if ($eligibleIds->isEmpty()) {
                return [];
            }

            $readAt = now();
            $rows = $eligibleIds->map(fn (int $messageId): array => [
                'message_id' => $messageId,
                'user_id' => $user->id,
                'read_at' => $readAt,
            ])->all();

            if (MessageRead::query()->insertOrIgnore($rows) === 0) {
                return [];
            }

            $messages = Message::query()->select(['id', 'channel_id'])
                ->whereIn('id', $eligibleIds)->withCount('reads')->orderBy('id')->get();
            $updates = array_values($messages->map(fn (Message $message): array => [
                'message_id' => $message->id,
                'read_count' => $message->reads_count,
            ])->all());

            if ($messages->isNotEmpty()) {
                MessageReadsUpdated::dispatch($messages->first()->channel_id, $updates);
            }

            return $updates;
        });
    }

    /** @return Collection<int, MessageRead> */
    public function readers(Message $message): Collection
    {
        return $message->reads()->select(['id', 'message_id', 'user_id', 'read_at'])
            ->with('user:id,name')
            ->orderBy('read_at')->orderBy('id')->get();
    }
}
