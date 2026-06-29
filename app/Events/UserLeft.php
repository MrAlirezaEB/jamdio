<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class UserLeft implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $userId)
    {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('station');
    }

    public function broadcastAs(): string
    {
        return 'UserLeft';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['userId' => $this->userId];
    }
}
