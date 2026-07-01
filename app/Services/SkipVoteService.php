<?php

namespace App\Services;

use App\Events\VoteCountUpdated;
use App\Models\SkipVote;
use App\Models\User;

/**
 * Encapsulates skip-vote accounting and the "skip when threshold reached"
 * decision. The actual track switch is performed by Liquidsoap (request.skip),
 * which then polls /api/next-track to advance the queue.
 */
class SkipVoteService
{
    public function __construct(private readonly LiquidsoapClient $liquidsoap)
    {
    }

    /** Count of guests currently considered active/online. */
    public function activeUserCount(): int
    {
        return User::query()->active()->count();
    }

    /**
     * Votes required to skip: strictly more than the threshold fraction
     * of active users (default >50%).
     */
    public function requiredVotes(?int $activeCount = null): int
    {
        $active = $activeCount ?? $this->activeUserCount();
        $threshold = (float) config('radio.skip_threshold', 0.5);

        // "More than X%" => floor(active * threshold) + 1, min 1.
        return max(1, (int) floor($active * $threshold) + 1);
    }

    /** Current votes recorded against a track. */
    public function currentVotes(int $trackId): int
    {
        return SkipVote::query()->where('track_id', $trackId)->count();
    }

    /** Whether a guest currently has a skip vote recorded for a track. */
    public function hasVoted(User $user, int $trackId): bool
    {
        return SkipVote::query()
            ->where('track_id', $trackId)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Vote status payload for a track: { current, required }.
     *
     * @return array{current:int, required:int}
     */
    public function status(int $trackId): array
    {
        return [
            'current' => $this->currentVotes($trackId),
            'required' => $this->requiredVotes(),
        ];
    }

    /**
     * Register a guest's vote to skip a track. Idempotent per (track, user).
     * Broadcasts the new tally and, if the threshold is reached, asks
     * Liquidsoap to skip the current track.
     *
     * @return array{current:int, required:int, skipped:bool}
     */
    public function vote(User $user, int $trackId): array
    {
        SkipVote::query()->firstOrCreate([
            'track_id' => $trackId,
            'user_id' => $user->id,
        ]);

        $status = $this->status($trackId);
        $skipped = false;

        broadcast(new VoteCountUpdated($trackId, $status['current'], $status['required']));

        if ($status['current'] >= $status['required']) {
            $skipped = $this->liquidsoap->skip();
        }

        return [...$status, 'skipped' => $skipped];
    }

    /**
     * Withdraw a guest's vote to skip a track. Broadcasts the new tally.
     * A withdrawal only ever lowers the count, so it can never trigger a skip.
     *
     * @return array{current:int, required:int}
     */
    public function retract(User $user, int $trackId): array
    {
        SkipVote::query()
            ->where('track_id', $trackId)
            ->where('user_id', $user->id)
            ->delete();

        $status = $this->status($trackId);

        broadcast(new VoteCountUpdated($trackId, $status['current'], $status['required']));

        return $status;
    }

    /** Discard recorded votes for a track (called when it leaves the deck). */
    public function clear(int $trackId): void
    {
        SkipVote::query()->where('track_id', $trackId)->delete();
    }
}
