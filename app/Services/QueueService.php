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
     * Hand the next track to Liquidsoap to buffer (prefetch). Marks the first
     * pending row "queued" and returns its Track, or null when nothing is
     * waiting (Liquidsoap then falls back to its playlist).
     *
     * This is intentionally NOT where "now playing" advances: Liquidsoap
     * prefetches the next track well before the current one ends, so promoting
     * it to "playing" here would make the deck run one track ahead of the
     * audio. The real promotion happens in markStarted(), driven by
     * Liquidsoap's on_track callback.
     */
    public function reserveNext(): ?Track
    {
        return DB::transaction(function () {
            $next = Queue::query()->pending()->ordered()->lockForUpdate()->first();

            if (! $next) {
                return null;
            }

            $next->update(['status' => Queue::STATUS_QUEUED]);

            return $next->fresh('track')->track;
        });
    }

    /**
     * A track has actually started on air (signalled by Liquidsoap's on_track
     * callback with the file it just began). Demote the outgoing track to
     * "played", promote the matching buffered row to "playing", and return the
     * now-playing Track — or null when a fallback/non-queue track started.
     */
    public function markStarted(string $file): ?Track
    {
        return DB::transaction(function () use ($file) {
            $basename = basename($file);

            // The freshly buffered row whose file matches what just started.
            $started = Queue::query()
                ->queued()
                ->ordered()
                ->lockForUpdate()
                ->get()
                ->first(fn (Queue $q) => basename((string) $q->track?->file_path) === $basename);

            // Already promoted (e.g. duplicate callback) — nothing to do.
            if (! $started && Queue::query()->playing()->whereHas('track', fn ($t) => $t->where('file_path', 'like', '%'.$basename))->exists()) {
                return Queue::query()->playing()->with('track')->first()?->track;
            }

            // Outgoing track leaves the deck; its votes no longer apply.
            Queue::query()->playing()->lockForUpdate()->get()
                ->each(function (Queue $q) {
                    $q->update(['status' => Queue::STATUS_PLAYED]);
                    $this->skipVotes->clear($q->track_id);
                });

            if (! $started) {
                // A fallback (non-queue) track is now on air.
                return null;
            }

            $started->update(['status' => Queue::STATUS_PLAYING]);

            return $started->fresh('track')->track;
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
            ->upNext()
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
