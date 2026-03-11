<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\LoginAction;
use App\Actions\Auth\RegisterAction;
use App\Exceptions\SingleSessionConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Http\Resources\UserSettingResource;
use App\Services\Auth\SingleSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function __construct(
        private RegisterAction $registerAction,
        private LoginAction $loginAction,
        private SingleSessionService $singleSessionService,
    ) {
    }

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

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $user = $this->loginAction->execute([
                'email' => $request->input('email'),
                'password' => $request->input('password'),
            ]);
        } catch (SingleSessionConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
                'data' => $exception->data,
            ], 409);
        }

        if (!$user) {
            return response()->json(['message' => 'Credenciales inválidas.'], 401);
        }

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($request->user() !== null) {
            $this->singleSessionService->clear($request->user());
        }

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

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()),
        ]);
    }
}
