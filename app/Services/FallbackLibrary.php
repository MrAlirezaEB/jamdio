<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * Manages the fallback music directory that Liquidsoap plays whenever the user
 * queue is empty. Liquidsoap watches this directory (reload_mode="watch") and
 * hot-reloads its randomized playlist, so adding/removing a file here takes
 * effect on the next track boundary with no restart.
 */
class FallbackLibrary
{
    public function __construct(
        private readonly AudioMetadata $metadata,
    ) {}

    /** Absolute path to the fallback directory as this container sees it. */
    public function directory(): string
    {
        return (string) config('radio.liquidsoap.fallback_app_dir', base_path('docker/fallback'));
    }

    /**
     * The fallback tracks currently on disk, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $dir = $this->directory();

        if (! is_dir($dir)) {
            return [];
        }

        $files = Finder::create()->files()->in($dir)->name('*.mp3')->sortByModifiedTime();

        $tracks = [];
        foreach ($files as $file) {
            $tracks[] = [
                'name' => $file->getFilename(),
                'size' => $file->getSize(),
                'duration' => $this->metadata->durationSeconds($file->getPathname()),
            ];
        }

        // Finder sorts oldest-first; show the most recently added at the top.
        return array_reverse($tracks);
    }

    /**
     * Store an uploaded MP3 in the fallback directory under a filesystem-safe,
     * collision-free name. Returns the stored filename.
     */
    public function store(UploadedFile $file): string
    {
        $dir = $this->directory();

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $name = $this->uniqueName($dir, $file->getClientOriginalName());
        $file->move($dir, $name);

        return $name;
    }

    /**
     * Delete a fallback track by filename. Returns true if a file was removed.
     * The name is reduced to its basename so it can never escape the directory.
     */
    public function delete(string $name): bool
    {
        $name = basename($name);
        $path = $this->directory().DIRECTORY_SEPARATOR.$name;

        if (str_ends_with(strtolower($name), '.mp3') && is_file($path)) {
            return unlink($path);
        }

        return false;
    }

    /** Build a sanitized, non-colliding "<slug>.mp3" name within $dir. */
    private function uniqueName(string $dir, string $originalName): string
    {
        $base = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
        $base = $base !== '' ? $base : 'track';

        $name = $base.'.mp3';
        $i = 1;
        while (file_exists($dir.DIRECTORY_SEPARATOR.$name)) {
            $name = $base.'-'.$i.'.mp3';
            $i++;
        }

        return $name;
    }
}
