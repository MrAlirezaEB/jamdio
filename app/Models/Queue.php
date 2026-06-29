<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user-submitted track waiting in (or moving through) the play queue.
 */
#[Fillable(['track_id', 'status'])]
class Queue extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PLAYING = 'playing';
    public const STATUS_PLAYED = 'played';

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    // ---- Scopes ----------------------------------------------------------

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_QUEUED);
    }

    public function scopePlaying(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PLAYING);
    }

    /**
     * Tracks that will play but are not yet audible: those still waiting in
     * line (pending) plus the one already buffered by Liquidsoap (queued).
     * This is what listeners see as "Up next".
     */
    public function scopeUpNext(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_QUEUED]);
    }

    /** FIFO ordering for the queue. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('created_at')->orderBy('id');
    }
}
