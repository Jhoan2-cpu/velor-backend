<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
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

    // Focus taskcards (CRUD basico)
    Route::middleware('auth:sanctum')->prefix('focus')->name('focus.')->group(function () {
        Route::get('tasks', [TaskCardController::class, 'index'])->name('tasks.index');
        Route::post('tasks', [TaskCardController::class, 'store'])->name('tasks.store');
        Route::patch('tasks/{taskId}', [TaskCardController::class, 'update'])->name('tasks.update');
        Route::delete('tasks/{taskId}', [TaskCardController::class, 'destroy'])->name('tasks.destroy');
    });

});
