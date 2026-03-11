<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Models\UserSetting;
use App\Services\Auth\SingleSessionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegisterAction
{
    public function __construct(
        private readonly SingleSessionService $singleSessionService,
    ) {
    }

    /**
     * Create a new user with settings and log them in, atomically.
     *
     * @param  array{display_name: string, email: string, password: string, locale: string, time_zone_name: string}  $data
     */
    public function execute(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'display_name' => $data['display_name'],
                'email' => $data['email'],
                'password_hash' => Hash::make($data['password']),
                'locale' => $data['locale'],
            ]);

            UserSetting::create([
                'user_id' => $user->id,
                'locale' => $data['locale'],
                'time_zone_name' => $data['time_zone_name'],
            ]);

            Auth::login($user);
            $this->singleSessionService->refresh($user);

            return $user->load('settings');
        });
    }
}
