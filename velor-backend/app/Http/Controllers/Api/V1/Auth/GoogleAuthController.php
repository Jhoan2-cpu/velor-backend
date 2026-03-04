<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\GoogleOAuthAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;

class GoogleAuthController extends Controller
{
    public function __construct(private GoogleOAuthAction $googleOAuthAction)
    {
    }

    // ─── GET /api/v1/auth/google/redirect ───────────────────────────────────────

    public function redirect(): RedirectResponse
    {
        /** @var GoogleProvider $driver */
        $driver = Socialite::driver('google');

        return $driver->stateless()->redirect();
    }

    // ─── GET /api/v1/auth/google/callback ───────────────────────────────────────

    public function callback(): RedirectResponse
    {
        try {
            /** @var GoogleProvider $driver */
            $driver = Socialite::driver('google');
            /** @var \Laravel\Socialite\Two\User $socialUser */
            $socialUser = $driver->stateless()->user();

            $this->googleOAuthAction->execute($socialUser);

            return redirect(config('app.frontend_url') . '/app');
        } catch (\Throwable $e) {
            report($e);

            return redirect(config('app.frontend_url') . '/login?auth_error=google');
        }
    }
}
