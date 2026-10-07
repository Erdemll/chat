<?php

namespace App\Http\Resources;

use App\Models\MessageRead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MessageRead */
class MessageReadResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{id: int, name: string, read_at: string}
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->user->id, 'name' => $this->user->name, 'read_at' => $this->read_at->toIso8601String()];
    }
}
