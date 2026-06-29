<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class UserJoined implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public User $user)
    {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('station');
    }

    public function broadcastAs(): string
    {
        return 'UserJoined';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'user' => [
                'id' => $this->user->id,
                'nickname' => $this->user->nickname,
                'avatar_path' => $this->user->avatar_url,
            ],
        ];
    }
}
