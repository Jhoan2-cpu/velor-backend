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

            $query = http_build_query([
                'auth_error' => 'google',
                'stage' => 'callback',
                'status' => 'failed',
                'code' => $this->resolveOAuthErrorCode($e),
            ]);

            return redirect(rtrim((string) config('app.frontend_url'), '/') . '/login?' . $query);
        }
    }

    private function resolveOAuthErrorCode(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'redirect_uri_mismatch')) {
            return 'REDIRECT_URI_MISMATCH';
        }

        if (str_contains($message, 'invalid_client')) {
            return 'INVALID_CLIENT';
        }

        if (str_contains($message, 'invalid_grant')) {
            return 'INVALID_GRANT';
        }

        if (str_contains($message, 'access_denied')) {
            return 'ACCESS_DENIED';
        }

        return 'GOOGLE_OAUTH_FAILED';
    }
}
