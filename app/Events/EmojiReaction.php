<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A listener flung an emoji onto the player. Ephemeral — nothing is persisted;
 * the payload just tells every other client which bubble to float, and whose
 * avatar to tuck in its corner.
 */
class EmojiReaction implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public User $user,
        public string $emoji,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('station');
    }

    public function broadcastAs(): string
    {
        return 'EmojiReaction';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'emoji' => $this->emoji,
            'user' => [
                'id' => $this->user->id,
                'nickname' => $this->user->nickname,
                'avatar_path' => $this->user->avatar_url,
            ],
        ];
    }
}
