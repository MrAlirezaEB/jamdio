<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

#[Fillable(['title', 'file_path', 'duration', 'uploaded_by'])]
class Track extends Model
{
    protected function casts(): array
    {
        return [
            'duration' => 'integer',
        ];
    }

    // ---- Relations -------------------------------------------------------

    /** The guest who uploaded this track (null for fallback/system tracks). */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** All queue entries for this track. */
    public function queueEntries(): HasMany
    {
        return $this->hasMany(Queue::class);
    }

    /** The most recent queue entry for this track. */
    public function queue(): HasOne
    {
        return $this->hasOne(Queue::class)->latestOfMany();
    }

    /** Skip votes recorded against this track. */
    public function skipVotes(): HasMany
    {
        return $this->hasMany(SkipVote::class);
    }

    // ---- Helpers ---------------------------------------------------------

    /** Absolute filesystem path Liquidsoap should read. */
    public function absolutePath(): string
    {
        return Storage::disk('public')->path($this->file_path);
    }

    /** Public URL for the audio file. */
    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->file_path);
    }
}
