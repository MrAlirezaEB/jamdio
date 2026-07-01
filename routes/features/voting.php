<?php

use App\Http\Controllers\SkipVoteController;
use Illuminate\Support\Facades\Route;

// Skip-vote routes. Registered inside the guest.session group.
Route::post('/skip', [SkipVoteController::class, 'store'])->name('skip');
Route::delete('/skip', [SkipVoteController::class, 'destroy'])->name('skip.retract');
