<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\QueueService;
use App\Services\SkipVoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StationController extends Controller
{
    public function __construct(
        private readonly QueueService $queue,
        private readonly SkipVoteService $skipVotes,
    ) {
    }

    /** Landing page with the "join the station" modal. */
    public function home(Request $request): Response|RedirectResponse
    {
        if ($request->attributes->get('guest_user')) {
            return redirect()->route('player');
        }

        return Inertia::render('Home', [
            'avatars' => array_map(
                fn (string $path) => [
                    'path' => $path,
                    'url' => asset('storage/'.$path),
                ],
                config('radio.avatars'),
            ),
        ]);
    }

    /** The live player dashboard. */
    public function player(): Response
    {
        $current = $this->queue->currentPayload();

        return Inertia::render('Player', [
            'nowPlaying' => $current,
            'queue' => $this->queue->pendingPayload(),
            'onlineUsers' => $this->onlineUsersPayload(),
            'voteStatus' => $current
                ? $this->skipVotes->status((int) $current['track_id'])
                : ['current' => 0, 'required' => 0],
        ]);
    }

    /** Shown to blocked / kicked guests. */
    public function blocked(): Response
    {
        return Inertia::render('Blocked');
    }

    /** @return array<int, array<string, mixed>> */
    public function onlineUsersPayload(): array
    {
        return User::query()
            ->active()
            ->orderBy('nickname')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'nickname' => $u->nickname,
                'avatar_path' => $u->avatar_url,
            ])
            ->all();
    }
}
