<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageResource extends JsonResource
{
    /** @return array{id: int, channel_id: int, body: string, created_at: string, edited_at: ?string, edit_expires_at: string, can_edit: bool, can_delete: bool, read_count: int, user: array{id: int, name: string}, mentions: array<int, array{id: int, name: string}>} */
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $isOwner = $actor?->is_active && $actor->id === $this->user_id;

        return ['id' => $this->id, 'channel_id' => $this->channel_id, 'body' => $this->body, 'created_at' => $this->created_at->toIso8601String(),
            'edited_at' => $this->edited_at?->toIso8601String(), 'edit_expires_at' => $this->editExpiresAt()->toIso8601String(),
            'can_edit' => $isOwner && $this->isWithinEditWindow(), 'can_delete' => $isOwner || (bool) $actor?->isAdmin(),
            'read_count' => (int) $this->reads_count, 'user' => ['id' => $this->user->id, 'name' => $this->user->name], 'mentions' => MentionableUserResource::collection($this->mentionedUsers)->resolve($request)];
    }
}
