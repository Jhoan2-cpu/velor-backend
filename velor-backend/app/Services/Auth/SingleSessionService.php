<?php

namespace App\Services\Auth;

use App\Exceptions\SingleSessionConflictException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SingleSessionService
{
    public function assertCanLogin(User $user): void
    {
        DB::transaction(function () use ($user): void {
            /** @var User|null $lockedUser */
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (!$lockedUser) {
                return;
            }

            $expiresAt = $lockedUser->active_session_expires_at;
            if ($expiresAt !== null && $expiresAt->isFuture()) {
                throw new SingleSessionConflictException(data: [
                    'active_session_expires_at' => $expiresAt->toISOString(),
                ]);
            }

            $lockedUser->active_session_expires_at = $this->nextExpiry();
            $lockedUser->save();
        });
    }

    public function refresh(User $user): void
    {
        User::query()
            ->whereKey($user->id)
            ->update(['active_session_expires_at' => $this->nextExpiry()]);
    }

    public function clear(User $user): void
    {
        User::query()
            ->whereKey($user->id)
            ->update(['active_session_expires_at' => null]);
    }

    private function nextExpiry(): CarbonImmutable
    {
        $minutes = max(1, (int) config('session.lifetime', 120));

        return CarbonImmutable::now('UTC')->addMinutes($minutes);
    }
}

