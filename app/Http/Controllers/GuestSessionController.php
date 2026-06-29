<?php

namespace App\Http\Controllers;

use App\Events\UserJoined;
use App\Events\UserLeft;
use App\Http\Requests\JoinStationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class GuestSessionController extends Controller
{
    /**
     * Join the station: create a guest record and bind it to the session.
     */
    public function store(JoinStationRequest $request): RedirectResponse
    {
        $guest = User::create([
            'nickname' => $request->string('nickname'),
            'avatar_path' => $request->string('avatar_path'),
            'session_id' => $request->session()->getId(),
            'ip_address' => $request->ip(),
            'is_blocked' => false,
            'last_active_at' => Carbon::now(),
        ]);

        $request->session()->put('guest_id', $guest->id);

        broadcast(new UserJoined($guest));

        return redirect()->route('player');
    }

    /**
     * Leave the station: announce departure and clear the session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $guestId = $request->session()->pull('guest_id');

        if ($guestId) {
            broadcast(new UserLeft((int) $guestId));
        }

        return redirect()->route('home');
    }

    /**
     * Lightweight heartbeat endpoint (TrackActivity does the actual touch).
     */
    public function heartbeat(Request $request)
    {
        return response()->json([
            'ok' => (bool) $request->attributes->get('guest_user'),
        ]);
    }
}
