<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Denies access to guests (or IPs) that an admin has blocked.
 *
 * Runs on every web request so a blocked party can neither use an existing
 * session nor re-join from the same IP address.
 */
class BlockListMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Never block the admin login/area or the blocked page itself,
        // otherwise an admin could lock themselves out from a shared IP.
        if ($request->is('admin*') || $request->is('blocked')) {
            return $next($request);
        }

        $ip = $request->ip();
        $guestId = $request->session()->get('guest_id');

        $blocked = User::query()
            ->where('is_blocked', true)
            ->where(function ($q) use ($ip, $guestId) {
                $q->where('ip_address', $ip);
                if ($guestId) {
                    $q->orWhere('id', $guestId);
                }
            })
            ->exists();

        if ($blocked) {
            $request->session()->forget(['guest_id']);

            if ($request->expectsJson()) {
                abort(403, 'You have been blocked from this station.');
            }

            return redirect()->route('blocked');
        }

        return $next($request);
    }
}
