<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'single.session.refresh' => \App\Http\Middleware\RefreshSingleSessionExpiry::class,
        ]);

        // Sanctum: stateful session for SPA (must be first in api group)
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);

        // Never redirect guests. This backend is API-first and must avoid
        // cross-origin 30x redirects that surface as CORS errors in the SPA.
        $middleware->redirectGuestsTo(function (Request $request): ?string {
            return null;
        });

        // CORS for SPA frontend
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $e): bool {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                logger()->warning('api_unauthenticated', [
                    'path' => $request->path(),
                    'origin' => $request->headers->get('origin'),
                    'referer' => $request->headers->get('referer'),
                    'session_cookie_name' => config('session.cookie'),
                    'has_session_cookie' => $request->cookies->has((string) config('session.cookie')),
                    'has_bearer_token' => $request->bearerToken() !== null,
                ]);

                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return null;
        });
    })->create();
