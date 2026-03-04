<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\GoogleAuthController;
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

        // Google OAuth
        Route::get('google/redirect', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
        Route::get('google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');

        // Protected
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('me', [AuthController::class, 'me'])->name('me');
        });
    });

});
