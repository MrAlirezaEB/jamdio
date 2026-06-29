<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The pending queue changed (track added, removed, or re-ordered).
 */
class QueueUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /** @param array<int, array<string, mixed>> $tracks */
    public function __construct(public array $tracks)
    {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('station');
    }

    public function broadcastAs(): string
    {
        return 'QueueUpdated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['queue' => $this->tracks];
    }
}
