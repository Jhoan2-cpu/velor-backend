<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\SingleSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RefreshSingleSessionExpiry
{
    public function __construct(
        private readonly SingleSessionService $singleSessionService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user !== null) {
            $this->singleSessionService->refresh($user);
        }

        return $next($request);
    }
}

