<?php

namespace App\Services;

use App\Events\MessageCreated;
use App\Http\Resources\MessageResource;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MessageService
{
    /** @return array{data: array<int, array<string, mixed>>, has_more: bool, before_id: ?int, after_id: ?int} */
    public function history(Channel $channel, ?int $beforeId = null, ?int $afterId = null): array
    {
        $limit = (int) config('chat.page_size');
        $rows = $channel->messages()->with('user:id,name')
            ->when($beforeId !== null, fn ($query) => $query->where('id', '<', $beforeId))
            ->when($afterId !== null, fn ($query) => $query->where('id', '>', $afterId))
            ->orderBy('id', $afterId === null ? 'desc' : 'asc')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $afterId === null ? $rows->take($limit)->reverse()->values() : $rows->take($limit)->values();

        return ['data' => MessageResource::collection($rows)->resolve(), 'has_more' => $hasMore, 'before_id' => $rows->first()?->id, 'after_id' => $rows->last()?->id];
    }

    /** @return array{message: Message, realtime: bool} */
    public function send(User $user, Channel $channel, string $body): array
    {
        $message = DB::transaction(function () use ($user, $channel, $body): Message {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($user->is_active, 403);
            $message = new Message(['body' => $body]);
            $message->user()->associate($user);
            $message->channel()->associate($channel);
            $message->save();

            return $message->load('user:id,name');
        });
        try {
            event(new MessageCreated($message));
        } catch (Throwable $exception) {
            Log::error('Message broadcast failed', ['message_id' => $message->id, 'exception_type' => $exception::class]);

            return ['message' => $message, 'realtime' => false];
        }

        return ['message' => $message, 'realtime' => config('broadcasting.default') === 'reverb'];
    }
}
