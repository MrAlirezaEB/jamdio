<?php

use App\Http\Controllers\ReactionController;
use Illuminate\Support\Facades\Route;

// Ephemeral emoji reactions. Registered inside the guest.session group.
Route::post('/reactions', [ReactionController::class, 'store'])->name('reactions.store');
