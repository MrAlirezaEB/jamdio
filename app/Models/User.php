<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A "soft auth" guest listener. Created on join; tracked by session_id.
 */
#[Fillable(['nickname', 'avatar_path', 'session_id', 'ip_address', 'is_blocked', 'last_active_at'])]
class User extends Model
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_blocked' => 'boolean',
            'last_active_at' => 'datetime',
        ];
    }

    // ---- Relations -------------------------------------------------------

    /** Tracks this guest uploaded. */
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class, 'uploaded_by');
    }

    /** Skip votes this guest has cast. */
    public function skipVotes(): HasMany
    {
        return $this->hasMany(SkipVote::class);
    }

    // ---- Scopes ----------------------------------------------------------

    /** Guests considered "online" (active within the configured window). */
    public function scopeActive(Builder $query): Builder
    {
        $window = (int) config('radio.active_window', 30);

        return $query->where('is_blocked', false)
            ->where('last_active_at', '>=', Carbon::now()->subSeconds($window));
    }

    // ---- Accessors / helpers --------------------------------------------

    /** Public URL for the guest's avatar. */
    public function getAvatarUrlAttribute(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        return Storage::disk('public')->url($this->avatar_path);
    }

    /** Bump the heartbeat timestamp. */
    public function touchActivity(): void
    {
        $this->forceFill(['last_active_at' => Carbon::now()])->saveQuietly();
    }
}
