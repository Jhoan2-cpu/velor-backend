<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\LoginAction;
use App\Actions\Auth\RegisterAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Http\Resources\UserSettingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function __construct(
        private RegisterAction $registerAction,
        private LoginAction $loginAction,
    ) {
    }

    // ─── POST /api/v1/auth/register ─────────────────────────────────────────────

    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $user = $this->registerAction->execute($request->validated());

            return response()->json([
                'data' => [
                    'user' => new UserResource($user),
                    'settings' => new UserSettingResource($user->settings),
                ],
            ], 201);
        } catch (\Throwable) {
            return response()->json(['message' => 'Register failed.'], 500);
        }
    }

    // ─── POST /api/v1/auth/login ─────────────────────────────────────────────────

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = [
            'email' => $request->input('email'),
            'password_hash' => $request->input('password'), // mapped to the model column
        ];

        $user = $this->loginAction->execute([
            'email' => $request->input('email'),
            'password' => $request->input('password'),
        ]);

        if (!$user) {
            return response()->json(['message' => 'Credenciales inválidas.'], 401);
        }

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
            ],
        ]);
    }

    // ─── POST /api/v1/auth/logout ────────────────────────────────────────────────

    public function logout(Request $request): JsonResponse
    {
        // Explicitly use the session-based web guard (not the Sanctum RequestGuard
        // which lacks a logout() method).
        $webGuard = Auth::guard('web');

        if ($webGuard->check()) {
            $webGuard->logout();
        }

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logged out.']);
    }

    // ─── GET /api/v1/auth/me ─────────────────────────────────────────────────────

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()),
        ]);
    }
}
