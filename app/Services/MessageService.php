<?php

namespace App\Services;

use App\Events\MessageCreated;
use App\Exceptions\MessageRejectedException;
use App\Http\Resources\MessageResource;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Services\Moderation\MessageModerationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MessageService
{
    public function __construct(private readonly MessageModerationService $moderation, private readonly MessageMentionService $mentions, private readonly UserActivityService $activity) {}

    /** @return array{data: array<int, array<string, mixed>>, has_more: bool, before_id: ?int, after_id: ?int} */
    public function history(Channel $channel, ?int $beforeId = null, ?int $afterId = null): array
    {
        $limit = (int) config('chat.page_size');
        $rows = $channel->messages()->with(['user:id,name', 'mentionedUsers:users.id,name'])->withCount('reads')
            ->when($beforeId !== null, fn ($query) => $query->where('id', '<', $beforeId))
            ->when($afterId !== null, fn ($query) => $query->where('id', '>', $afterId))
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
        $result = $this->moderation->moderate($body);

        if ($result->blocked()) {
            Log::notice('Message blocked by moderation', [
                'user_id' => $user->id,
                'category' => $result->category,
                'exception_type' => MessageRejectedException::class,
            ]);

            throw new MessageRejectedException($result);
        }

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
}
