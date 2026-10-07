<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageResource extends JsonResource
{
    /** @return array{id: int, channel_id: int, body: string, created_at: string, read_count: int, user: array{id: int, name: string}} */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'channel_id' => $this->channel_id, 'body' => $this->body, 'created_at' => $this->created_at->toIso8601String(), 'read_count' => (int) $this->reads_count, 'user' => ['id' => $this->user->id, 'name' => $this->user->name]];
    }
}
