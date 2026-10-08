<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class MessageUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $messageId, public int $channelId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('company.general');
    }

    public function broadcastAs(): string
    {
        return 'MessageUpdated';
    }

    /** @return array{message_id: int, channel_id: int} */
    public function broadcastWith(): array
    {
        return ['message_id' => $this->messageId, 'channel_id' => $this->channelId];
    }
}
