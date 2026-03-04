<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// ─── Public Routes ────────────────────────────────────────────────────────────
// Auth routes (login, register, etc.) — add here without auth middleware
// Example: Route::post('/login', [AuthController::class, 'login']);
// Example: Route::post('/register', [AuthController::class, 'register']);


// ─── Authenticated Routes ─────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Current authenticated user
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // ── Real-Time / Messaging ──────────────────────────────────────────────────
    // Routes that trigger broadcasts via Laravel Reverb go here.
    // Example: Route::post('/messages', [MessageController::class, 'store']);
    // Example: Route::get('/messages/{channel}', [MessageController::class, 'index']);

});
