<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Targeted at a single guest: their session should be cleared and they should
 * be redirected away. Broadcast on the public station channel; the client
 * compares the userId against its own and acts only if it matches.
 */
class UserKicked implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $userId,
        public string $reason = 'kicked',
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('station');
    }

    public function broadcastAs(): string
    {
        return 'UserKicked';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'userId' => $this->userId,
            'reason' => $this->reason,
        ];
    }
}
