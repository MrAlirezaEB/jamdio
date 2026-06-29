<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current guest from the session and refreshes their
 * last_active_at heartbeat, exposing the model as a request attribute
 * ("guest_user") for controllers and the Inertia share layer.
 */
class TrackActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $guestId = $request->session()->get('guest_id');

        if ($guestId) {
            $guest = User::query()->find($guestId);

            if ($guest && ! $guest->is_blocked) {
                $guest->touchActivity();
                $request->attributes->set('guest_user', $guest);
            } else {
                // Stale or blocked session reference — drop it.
                $request->session()->forget('guest_id');
            }
        }

        return $next($request);
    }
}
