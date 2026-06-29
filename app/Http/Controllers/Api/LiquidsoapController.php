<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\QueueService;
use Illuminate\Http\Response;

/**
 * Internal API consumed by Liquidsoap. Guarded by the "liquidsoap" middleware
 * (shared bearer token). Responses are plain text so the .liq script can use
 * the body directly as a request URI.
 */
class LiquidsoapController extends Controller
{
    public function __construct(private readonly QueueService $queue)
    {
    }

    /**
     * Pop the next track for Liquidsoap to play.
     *
     * Returns the absolute file path as plain text, or an empty body when the
     * station is off or the user queue is empty (Liquidsoap then falls back to
     * its default playlist / offline loop).
     */
    public function nextTrack(): Response
    {
        if (Setting::get('station_status', 'on') !== 'on') {
            return $this->plain('');
        }

        $track = $this->queue->advance();

        if (! $track) {
            return $this->plain('');
        }

        // Announce the new track and the (now shorter) pending queue.
        $this->queue->broadcastNowPlaying();
        $this->queue->broadcastQueue();

        return $this->plain($track->absolutePath());
    }

    private function plain(string $body): Response
    {
        return response($body, 200)->header('Content-Type', 'text/plain');
    }
}
