<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [AdminAuthController::class, 'show'])->name('login');
    Route::post('login', [AdminAuthController::class, 'login'])->name('login.attempt');

    Route::middleware('admin')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('dashboard');
        Route::post('logout', [AdminAuthController::class, 'logout'])->name('logout');

        // Station
        Route::post('station/toggle', [AdminController::class, 'toggleStation'])->name('station.toggle');

        // Queue management
        Route::post('queue/reorder', [AdminController::class, 'reorderQueue'])->name('queue.reorder');
        Route::delete('queue/{queue}', [AdminController::class, 'removeFromQueue'])->name('queue.remove');
        Route::post('skip', [AdminController::class, 'forceSkip'])->name('skip');

        // User management
        Route::post('users/{user}/kick', [AdminController::class, 'kickUser'])->name('users.kick');
        Route::post('users/{user}/block', [AdminController::class, 'blockUser'])->name('users.block');
    });
});
