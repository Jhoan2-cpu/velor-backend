<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\App\AppBootstrapController;
use App\Http\Controllers\Api\V1\Focus\FocusDailyLogController;
use App\Http\Controllers\Api\V1\Focus\FocusRuntimeController;
use App\Http\Controllers\Api\V1\Focus\TaskCardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes  — /api prefix is applied automatically by bootstrap/app.php
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->name('v1.')->group(function () {

    // ─── Auth ────────────────────────────────────────────────────────────────
    Route::prefix('auth')->name('auth.')->group(function () {

        // Public
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('login', [AuthController::class, 'login'])->name('login');


        // Protected
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('me', [AuthController::class, 'me'])->name('me');
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('app/bootstrap', [AppBootstrapController::class, 'show'])->name('app.bootstrap');

        // Canonical endpoints for TaskCards contract.
        Route::get('tasks', [TaskCardController::class, 'index'])->name('tasks.index');
        Route::post('tasks', [TaskCardController::class, 'store'])->name('tasks.store');
        Route::patch('tasks/{taskId}', [TaskCardController::class, 'update'])->name('tasks.update');
        Route::delete('tasks/{taskId}', [TaskCardController::class, 'destroy'])->name('tasks.destroy');

        // Focus runtime commands (canonical).
        Route::prefix('focus-sessions')->name('focus-sessions.')->group(function () {
            Route::get('active', [FocusRuntimeController::class, 'active'])->name('active');
            Route::post('start', [FocusRuntimeController::class, 'start'])->name('start');
            Route::post('pause', [FocusRuntimeController::class, 'pause'])->name('pause');
            Route::post('resume', [FocusRuntimeController::class, 'resume'])->name('resume');
            Route::post('stop', [FocusRuntimeController::class, 'stop'])->name('stop');
            Route::post('reset', [FocusRuntimeController::class, 'reset'])->name('reset');
            Route::post('switch-task', [FocusRuntimeController::class, 'switchTask'])->name('switch-task');
            Route::post('heartbeat', [FocusRuntimeController::class, 'heartbeat'])->name('heartbeat');
        });

        // Backward-compatible alias under /focus/tasks.
        Route::prefix('focus')->name('focus.')->group(function () {
            Route::get('tasks', [TaskCardController::class, 'index'])->name('tasks.index');
            Route::post('tasks', [TaskCardController::class, 'store'])->name('tasks.store');
            Route::patch('tasks/{taskId}', [TaskCardController::class, 'update'])->name('tasks.update');
            Route::delete('tasks/{taskId}', [TaskCardController::class, 'destroy'])->name('tasks.destroy');

            Route::get('daily-log', [FocusDailyLogController::class, 'show'])->name('daily-log.show');

            // Optional compatibility alias for runtime namespace.
            Route::prefix('sessions')->name('sessions.')->group(function () {
                Route::get('active', [FocusRuntimeController::class, 'active'])->name('active');
                Route::post('start', [FocusRuntimeController::class, 'start'])->name('start');
                Route::post('pause', [FocusRuntimeController::class, 'pause'])->name('pause');
                Route::post('resume', [FocusRuntimeController::class, 'resume'])->name('resume');
                Route::post('stop', [FocusRuntimeController::class, 'stop'])->name('stop');
                Route::post('reset', [FocusRuntimeController::class, 'reset'])->name('reset');
                Route::post('switch-task', [FocusRuntimeController::class, 'switchTask'])->name('switch-task');
                Route::post('heartbeat', [FocusRuntimeController::class, 'heartbeat'])->name('heartbeat');
            });
        });
    });

});
