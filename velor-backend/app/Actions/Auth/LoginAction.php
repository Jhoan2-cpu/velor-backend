<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class LoginAction
{
    /**
     * Attempt login and regenerate the session.
     *
     * @param  array{email: string, password: string}  $credentials
     * @throws \Illuminate\Validation\ValidationException
     */
    public function execute(array $credentials, bool $remember = false): ?User
    {
        if (!Auth::attempt($credentials, $remember)) {
            return null;
        }

        // Regenerate session if one is available (not present in JSON/API test context)
        if (request()->hasSession()) {
            request()->session()->regenerate();
        }

        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
