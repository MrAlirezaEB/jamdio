<?php

use App\Http\Controllers\Api\LiquidsoapController;
use Illuminate\Support\Facades\Route;

// ---- Internal Liquidsoap integration -----------------------------------
// Guarded by a shared bearer token (see LiquidsoapAuth middleware).
Route::middleware('liquidsoap')->group(function () {
    Route::get('/next-track', [LiquidsoapController::class, 'nextTrack'])->name('api.next-track');
    Route::post('/track-started', [LiquidsoapController::class, 'trackStarted'])->name('api.track-started');
});

// Liveness probe.
Route::get('/ping', fn () => response()->json(['pong' => true]));
