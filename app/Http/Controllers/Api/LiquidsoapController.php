<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\QueueService;
use Illuminate\Http\Request;
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
     * Hand Liquidsoap the next track to buffer.
     *
     * Returns the absolute file path as plain text, or an empty body when the
     * station is off or the user queue is empty (Liquidsoap then falls back to
     * its default playlist / offline loop).
     *
     * This only reserves the track; "now playing" does not advance until the
     * track actually goes on air (see trackStarted). No broadcast is emitted
     * here because the up-next list is unchanged — the reserved track stays
     * visible until it starts.
     */
    public function nextTrack(): Response
    {
        if (Setting::get('station_status', 'on') !== 'on') {
            return $this->plain('');
        }

        $track = $this->queue->reserveNext();

        if (! $track) {
            return $this->plain('');
        }

        return $this->plain($track->absolutePath());
    }

    /**
     * Liquidsoap reports that a track just started on air (on_track callback,
     * `file` = the absolute path it began playing). This is the real track
     * boundary: promote it to "playing", retire the previous track, and
     * announce the change.
     */
    public function trackStarted(Request $request): Response
    {
        $file = (string) $request->input('file', '');

        if ($file === '') {
            return $this->plain('');
        }

        $this->queue->markStarted($file);

        // Announce the new now-playing track and the (now shorter) up-next list.
        $this->queue->broadcastNowPlaying();
        $this->queue->broadcastQueue();

        return $this->plain('OK');
    }

    private function plain(string $body): Response
    {
        return response($body, 200)->header('Content-Type', 'text/plain');
    }
}
