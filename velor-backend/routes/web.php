<?php

use App\Http\Controllers\Api\V1\Auth\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ── Google OAuth (must be under web middleware for session support) ────────
// The callback needs StartSession to persist Auth::login() into the cookie.
// These routes intentionally use the /api/v1/auth/google/* URL to keep the
// same URIs configured in Google Cloud Console and the frontend.
Route::prefix('api/v1/auth/google')->name('api.v1.auth.google.')->group(function () {
    Route::get('redirect', [GoogleAuthController::class, 'redirect'])->name('redirect');
    Route::get('callback', [GoogleAuthController::class, 'callback'])->name('callback');
});

