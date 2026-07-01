<?php

use App\Http\Controllers\GuestSessionController;
use App\Http\Controllers\StationController;
use Illuminate\Support\Facades\Route;

// ---- Public / guest soft-auth ------------------------------------------

Route::get('/', [StationController::class, 'home'])->name('home');
Route::post('/join', [GuestSessionController::class, 'store'])->name('join');
Route::get('/blocked', [StationController::class, 'blocked'])->name('blocked');

Route::middleware('guest.session')->group(function () {
    Route::get('/player', [StationController::class, 'player'])->name('player');
    Route::post('/leave', [GuestSessionController::class, 'destroy'])->name('leave');
    Route::post('/heartbeat', [GuestSessionController::class, 'heartbeat'])->name('heartbeat');

    // Track submission & skip voting (registered in feature steps below).
    require __DIR__.'/features/queue.php';
    require __DIR__.'/features/voting.php';
    require __DIR__.'/features/reactions.php';
});

// ---- Admin control panel (registered in admin step) --------------------
require __DIR__.'/features/admin.php';
