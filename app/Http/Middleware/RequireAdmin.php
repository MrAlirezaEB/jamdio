<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the admin control panel behind the simple session admin flag set by
 * AdminAuthController.
 */
class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get('is_admin', false)) {
            if ($request->expectsJson()) {
                abort(403, 'Admin access required.');
            }

            return redirect()->route('admin.login');
        }

        return $next($request);
    }
}
