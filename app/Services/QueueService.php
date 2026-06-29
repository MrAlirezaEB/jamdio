<?php

namespace App\Services;

use App\Events\QueueUpdated;
use App\Events\TrackChanged;
use App\Models\Queue;
use App\Models\Track;
use Illuminate\Support\Facades\DB;

class QueueService
{
    public function __construct(private readonly SkipVoteService $skipVotes)
    {
    }

    /**
     * Advance the queue: mark the current track played and promote the next
     * pending track to "playing". Returns the now-playing Track, or null when
     * the user queue is empty (Liquidsoap then falls back to its playlist).
     */
    public function advance(): ?Track
    {
        return DB::transaction(function () {
            Queue::query()->playing()->lockForUpdate()->get()
                ->each(function (Queue $q) {
                    $q->update(['status' => Queue::STATUS_PLAYED]);
                    // Votes only apply to the track while it is on the deck.
                    $this->skipVotes->clear($q->track_id);
                });

            $next = Queue::query()->pending()->ordered()->lockForUpdate()->first();

            if (! $next) {
                return null;
            }

            $next->update(['status' => Queue::STATUS_PLAYING]);

            return $next->fresh('track')->track;
        });
    }

    /** Append a track to the queue as a pending entry. */
    public function enqueue(Track $track): Queue
    {
        return Queue::create([
            'track_id' => $track->id,
            'status' => Queue::STATUS_PENDING,
        ]);
    }

    /** The queue entry currently playing (with track + uploader eager loaded). */
    public function currentEntry(): ?Queue
    {
        return Queue::query()->playing()->with('track.uploader')->first();
    }

    // ---- Payload builders (shared by controllers and events) ------------

    /** @return array<string, mixed>|null */
    public function currentPayload(): ?array
    {
        $playing = $this->currentEntry();

        if (! $playing || ! $playing->track) {
            return null;
        }

        return [
            'queue_id' => $playing->id,
            'track_id' => $playing->track->id,
            'title' => $playing->track->title,
            'duration' => $playing->track->duration,
            'uploaded_by' => $playing->track->uploader?->nickname,
            'started_at' => $playing->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function pendingPayload(): array
    {
        return Queue::query()
            ->pending()
            ->ordered()
            ->with('track.uploader')
            ->get()
            ->map(fn (Queue $q) => [
                'queue_id' => $q->id,
                'track_id' => $q->track_id,
                'title' => $q->track?->title,
                'duration' => $q->track?->duration,
                'uploaded_by' => $q->track?->uploader?->nickname,
            ])
            ->all();
    }

    // ---- Broadcast helpers ----------------------------------------------

    /** Broadcast the current pending queue to all clients. */
    public function broadcastQueue(): void
    {
        broadcast(new QueueUpdated($this->pendingPayload()));
    }

    /** Broadcast the now-playing track (with a fresh, empty vote tally). */
    public function broadcastNowPlaying(): void
    {
        $current = $this->currentPayload();

        broadcast(new TrackChanged(
            $current,
            $current
                ? $this->skipVotes->status((int) $current['track_id'])
                : ['current' => 0, 'required' => 0],
        ));
    }
}
