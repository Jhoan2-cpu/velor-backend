<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Models\UserOauthIdentity;
use App\Models\UserSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Two\User as SocialiteUser;

class GoogleOAuthAction
{
    /**
     * Find or create a user from a Socialite Google user, then log them in.
     */
    public function execute(SocialiteUser $socialUser): User
    {
        return DB::transaction(function () use ($socialUser) {
            // Try to find by existing OAuth identity
            $identity = UserOauthIdentity::where('provider', 'google')
                ->where('provider_user_id', $socialUser->getId())
                ->first();

            if ($identity) {
                // Update tokens. If legacy encrypted payload cannot be decrypted (APP_KEY changed),
                // fall back to a raw DB update using fresh encrypted token values.
                try {
                    $identity->update([
                        'access_token' => $socialUser->token,
                        'refresh_token' => $socialUser->refreshToken,
                        'token_expires_at' => $socialUser->expiresIn
                            ? now()->addSeconds($socialUser->expiresIn)
                            : null,
                        'provider_email' => $socialUser->getEmail(),
                        'avatar_url' => $socialUser->getAvatar(),
                    ]);
                } catch (DecryptException $exception) {
                    report($exception);

                    DB::table('user_oauth_identities')
                        ->where('id', $identity->id)
                        ->update([
                            'access_token' => $socialUser->token !== null
                                ? Crypt::encryptString((string) $socialUser->token)
                                : null,
                            'refresh_token' => $socialUser->refreshToken !== null
                                ? Crypt::encryptString((string) $socialUser->refreshToken)
                                : null,
                            'token_expires_at' => $socialUser->expiresIn
                                ? now()->addSeconds($socialUser->expiresIn)
                                : null,
                            'provider_email' => $socialUser->getEmail(),
                            'avatar_url' => $socialUser->getAvatar(),
                            'updated_at' => now(),
                        ]);
                }

                $user = $identity->user;
            } else {
                // Try to find by email (user registered via email then links Google)
                $user = User::where('email', $socialUser->getEmail())->first();

                if (!$user) {
                    // Brand new user via Google
                    $user = User::create([
                        'display_name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'User',
                        'email' => $socialUser->getEmail(),
                        'password_hash' => null,
                        'locale' => 'es',
                        'email_verified_at' => now(),
                    ]);

                    UserSetting::create([
                        'user_id' => $user->id,
                        'locale' => 'es',
                        'time_zone_name' => 'America/Lima',
                    ]);
                }

                UserOauthIdentity::create([
                    'user_id' => $user->id,
                    'provider' => 'google',
                    'provider_user_id' => $socialUser->getId(),
                    'provider_email' => $socialUser->getEmail(),
                    'avatar_url' => $socialUser->getAvatar(),
                    'access_token' => $socialUser->token,
                    'refresh_token' => $socialUser->refreshToken,
                    'token_expires_at' => $socialUser->expiresIn
                        ? now()->addSeconds($socialUser->expiresIn)
                        : null,
                ]);
            }

            Auth::login($user);

            return $user;
        });
    }
}
