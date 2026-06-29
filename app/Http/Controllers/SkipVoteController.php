<?php

namespace App\Http\Controllers;

use App\Services\QueueService;
use App\Services\SkipVoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SkipVoteController extends Controller
{
    public function __construct(
        private readonly SkipVoteService $skipVotes,
        private readonly QueueService $queue,
    ) {
    }

    /** Register the current guest's vote to skip the playing track. */
    public function store(Request $request): RedirectResponse
    {
        $guest = $request->attributes->get('guest_user');
        $current = $this->queue->currentEntry();

        if (! $current) {
            return back()->with('error', 'Nothing is playing right now.');
        }

        $result = $this->skipVotes->vote($guest, (int) $current->track_id);

        return back()->with(
            'success',
            $result['skipped']
                ? 'Track skipped!'
                : "Vote counted ({$result['current']}/{$result['required']}).",
        );
    }
}
