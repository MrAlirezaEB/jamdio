<?php

use App\Http\Controllers\TrackUploadController;
use Illuminate\Support\Facades\Route;

// Track upload & queue submission.
// Registered inside the guest.session group (see routes/web.php).
Route::post('/tracks', [TrackUploadController::class, 'store'])->name('tracks.store');
