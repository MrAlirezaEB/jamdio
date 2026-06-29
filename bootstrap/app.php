<?php

use App\Http\Middleware\BlockListMiddleware;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LiquidsoapAuth;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\RequireGuestSession;
use App\Http\Middleware\TrackActivity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            BlockListMiddleware::class,
            TrackActivity::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'guest.session' => RequireGuestSession::class,
            'admin' => RequireAdmin::class,
            'liquidsoap' => LiquidsoapAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
