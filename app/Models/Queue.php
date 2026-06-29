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

    public function scopePlaying(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PLAYING);
    }

    /** FIFO ordering for the queue. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('created_at')->orderBy('id');
    }
}
