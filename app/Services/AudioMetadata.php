<?php

namespace App\Services;

use getID3;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Extracts metadata (currently just duration) from uploaded audio files.
 */
class AudioMetadata
{
    /** Best-effort track duration in whole seconds (0 if undetectable). */
    public function durationSeconds(string $absolutePath): int
    {
        try {
            $info = (new getID3())->analyze($absolutePath);

            return (int) round($info['playtime_seconds'] ?? 0);
        } catch (Throwable $e) {
            Log::warning('Could not read audio duration', [
                'path' => $absolutePath,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
