<?php

namespace App\Services;

use App\Models\Message;
use App\Models\MessageMention;
use App\Models\User;
use Illuminate\Support\Collection;

class MessageMentionService
{
    /** @param list<int> $userIds */
    public function sync(Message $message, User $actor, array $userIds): void
    {
        abort_unless($actor->is_active && $message->user_id === $actor->id, 403);
        $eligibleIds = $this->eligibleUserIds($actor, $userIds);
        $message->mentions()->whereNotIn('user_id', $eligibleIds)->delete();
        $this->insertMentions($message, $eligibleIds);
    }

    /** @param list<int> $userIds */
    public function attach(Message $message, User $actor, array $userIds): void
    {
        abort_unless($actor->is_active && $message->user_id === $actor->id, 403);
        if ($userIds === []) {
            return;
        }

        $this->insertMentions($message, $this->eligibleUserIds($actor, $userIds));
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, int>
     */
    private function eligibleUserIds(User $actor, array $userIds): Collection
    {
        return User::query()->whereIn('id', array_unique($userIds))
            ->where('is_active', true)->where('id', '!=', $actor->id)->pluck('id');
    }

    /** @param Collection<int, int> $eligibleIds */
    private function insertMentions(Message $message, Collection $eligibleIds): void
    {
        if ($eligibleIds->isEmpty()) {
            return;
        }

        $createdAt = now();
        $rows = $eligibleIds->map(fn (int $userId): array => [
            'message_id' => $message->id, 'user_id' => $userId, 'created_at' => $createdAt,
        ])->all();
        MessageMention::query()->insertOrIgnore($rows);
    }
}
