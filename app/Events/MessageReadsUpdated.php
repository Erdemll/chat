<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class MessageReadsUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @param list<array{message_id: int, read_count: int}> $reads */
    public function __construct(public int $channelId, public array $reads) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('company.general');
    }

    public function broadcastAs(): string
    {
        return 'MessageReadsUpdated';
    }

    /** @return array{channel_id: int, reads: list<array{message_id: int, read_count: int}>} */
    public function broadcastWith(): array
    {
        return ['channel_id' => $this->channelId, 'reads' => $this->reads];
    }
}
