<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the request comes from a guest who has joined the station.
 * Relies on TrackActivity having populated the "guest_user" attribute.
 */
class RequireGuestSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->attributes->get('guest_user')) {
            if ($request->expectsJson()) {
                abort(401, 'You must join the station first.');
            }

            return redirect()->route('home');
        }

        return $next($request);
    }
}
