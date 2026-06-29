<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the internal Liquidsoap API endpoints with a shared bearer token.
 */
class LiquidsoapAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('radio.liquidsoap.api_token');
        $provided = $request->bearerToken() ?? $request->query('token');

        if (! $expected || ! is_string($provided) || ! hash_equals($expected, $provided)) {
            Log::warning('Rejected Liquidsoap API request', ['ip' => $request->ip()]);
            abort(401, 'Invalid Liquidsoap token.');
        }

        return $next($request);
    }
}
