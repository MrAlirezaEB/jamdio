<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The station was switched on or off by an admin.
 */
class StationToggled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $status)
    {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('station');
    }

    public function broadcastAs(): string
    {
        return 'StationToggled';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['status' => $this->status];
    }
}
