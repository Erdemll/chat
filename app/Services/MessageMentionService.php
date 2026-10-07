<?php

namespace App\Services;

use App\Models\Message;
use App\Models\MessageMention;
use App\Models\User;

class MessageMentionService
{
    /** @param list<int> $userIds */
    public function attach(Message $message, User $actor, array $userIds): void
    {
        abort_unless($actor->is_active && $message->user_id === $actor->id, 403);
        if ($userIds === []) {
            return;
        }

        $eligibleIds = User::query()->whereIn('id', array_unique($userIds))
            ->where('is_active', true)->where('id', '!=', $actor->id)->pluck('id');
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
