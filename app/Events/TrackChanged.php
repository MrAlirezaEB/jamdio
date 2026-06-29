<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new track has started playing (or playback stopped, when $track is null).
 */
class TrackChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, mixed>|null  $track
     * @param  array{current:int, required:int}  $voteStatus
     */
    public function __construct(
        public ?array $track,
        public array $voteStatus = ['current' => 0, 'required' => 0],
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('station');
    }

    public function broadcastAs(): string
    {
        return 'TrackChanged';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'nowPlaying' => $this->track,
            'voteStatus' => $this->voteStatus,
        ];
    }
}
