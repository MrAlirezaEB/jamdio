<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitTrackRequest;
use App\Models\Track;
use App\Services\AudioMetadata;
use App\Services\QueueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TrackUploadController extends Controller
{
    public function __construct(
        private readonly QueueService $queue,
        private readonly AudioMetadata $metadata,
    ) {
    }

    /**
     * Handle a guest track submission: store the MP3, create the track and
     * append it to the queue as pending.
     */
    public function store(SubmitTrackRequest $request): RedirectResponse
    {
        $guest = $request->attributes->get('guest_user');
        $file = $request->file('track');

        $path = $file->store('tracks', 'public');

        // Read duration from the stored file (the temp upload is gone post-store).
        $duration = $this->metadata->durationSeconds(Storage::disk('public')->path($path));
        $title = $this->resolveTitle($request, $file->getClientOriginalName());

        $track = Track::create([
            'title' => $title,
            'file_path' => $path,
            'duration' => $duration,
            'uploaded_by' => $guest?->id,
        ]);

        $this->queue->enqueue($track);
        $this->queue->broadcastQueue();

        return back()->with('success', "“{$title}” added to the queue.");
    }

    private function resolveTitle(Request $request, string $originalName): string
    {
        $title = trim((string) $request->input('title'));

        if ($title !== '') {
            return $title;
        }

        // Derive a readable title from the uploaded filename.
        $base = pathinfo($originalName, PATHINFO_FILENAME);

        return $base !== '' ? $base : 'Untitled track';
    }
}
