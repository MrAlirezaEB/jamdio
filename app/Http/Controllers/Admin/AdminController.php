<?php

namespace App\Http\Controllers\Admin;

use App\Events\StationToggled;
use App\Events\UserKicked;
use App\Events\UserLeft;
use App\Http\Controllers\Controller;
use App\Models\Queue;
use App\Models\Setting;
use App\Models\User;
use App\Services\LiquidsoapClient;
use App\Services\QueueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function __construct(
        private readonly QueueService $queue,
        private readonly LiquidsoapClient $liquidsoap,
    ) {
    }

    /** The admin control panel. */
    public function index(): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'stationStatus' => Setting::get('station_status', 'on'),
            'nowPlaying' => $this->queue->currentPayload(),
            'queue' => $this->queue->pendingPayload(),
            'users' => $this->usersPayload(),
        ]);
    }

    /** Toggle the station on/off. */
    public function toggleStation(Request $request): RedirectResponse
    {
        $current = Setting::get('station_status', 'on');
        $next = $current === 'on' ? 'off' : 'on';

        Setting::set('station_status', $next);
        broadcast(new StationToggled($next));

        // When switching off, stop the current track promptly; Liquidsoap will
        // get an empty /next-track response and fall back to its offline loop.
        if ($next === 'off') {
            $this->liquidsoap->skip();
        }

        return back()->with('success', "Station turned {$next}.");
    }

    /** Re-order the pending queue (drag-and-drop). */
    public function reorderQueue(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $base = Carbon::now();

        DB::transaction(function () use ($validated, $base) {
            foreach ($validated['order'] as $index => $queueId) {
                Queue::query()
                    ->where('id', $queueId)
                    ->where('status', Queue::STATUS_PENDING)
                    ->update(['created_at' => $base->copy()->addSeconds($index)]);
            }
        });

        $this->queue->broadcastQueue();

        return back()->with('success', 'Queue re-ordered.');
    }

    /** Remove a track from the queue. */
    public function removeFromQueue(Queue $queue): RedirectResponse
    {
        $queue->delete();
        $this->queue->broadcastQueue();

        return back()->with('success', 'Track removed from queue.');
    }

    /** Force-skip the currently playing track. */
    public function forceSkip(): RedirectResponse
    {
        $this->liquidsoap->skip();

        return back()->with('success', 'Skip sent to the stream.');
    }

    /** Kick a guest: clear their server session and notify their client. */
    public function kickUser(User $user): RedirectResponse
    {
        $this->terminateSession($user);

        broadcast(new UserKicked($user->id, 'kicked'));
        broadcast(new UserLeft($user->id));

        return back()->with('success', "{$user->nickname} was kicked.");
    }

    /** Block a guest (and their IP) from re-joining. */
    public function blockUser(User $user): RedirectResponse
    {
        $user->forceFill(['is_blocked' => true])->save();
        $this->terminateSession($user);

        broadcast(new UserKicked($user->id, 'blocked'));
        broadcast(new UserLeft($user->id));

        return back()->with('success', "{$user->nickname} was blocked.");
    }

    /** Lift a block so the guest (and their IP) can re-join. */
    public function unblockUser(User $user): RedirectResponse
    {
        $user->forceFill(['is_blocked' => false])->save();

        return back()->with('success', "{$user->nickname} was unblocked.");
    }

    /**
     * Permanently remove a guest: disconnect them and delete the record.
     * Their skip votes cascade away; uploaded tracks are kept (uploaded_by
     * is nulled by the foreign key).
     */
    public function removeUser(User $user): RedirectResponse
    {
        $nickname = $user->nickname;

        $this->terminateSession($user);

        broadcast(new UserKicked($user->id, 'removed'));
        broadcast(new UserLeft($user->id));

        $user->delete();

        return back()->with('success', "{$nickname} was removed.");
    }

    // ---- Helpers ---------------------------------------------------------

    /** Invalidate the guest's stored server-side session row. */
    private function terminateSession(User $user): void
    {
        if ($user->session_id) {
            DB::table('sessions')->where('id', $user->session_id)->delete();
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function usersPayload(): array
    {
        $window = (int) config('radio.active_window', 30);
        $threshold = Carbon::now()->subSeconds($window);

        return User::query()
            ->orderByDesc('last_active_at')
            ->limit(200)
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'nickname' => $u->nickname,
                'avatar_path' => $u->avatar_url,
                'ip_address' => $u->ip_address,
                'is_blocked' => $u->is_blocked,
                'is_online' => ! $u->is_blocked
                    && $u->last_active_at !== null
                    && $u->last_active_at->greaterThanOrEqualTo($threshold),
                'joined_at' => $u->created_at?->toIso8601String(),
                'last_active_at' => $u->last_active_at?->toIso8601String(),
            ])
            ->all();
    }
}
