<?php

use App\Http\Middleware\CheckPermission;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Cloudflare Tunnel, cloudflared runs on this machine and connects
        // from loopback: only it may tell us the visitor's IP (X-Forwarded-For,
        // whose last entry Cloudflare's edge sets) and scheme. Any other client
        // — LAN PCs hitting :8001 directly — has its forwarded headers ignored,
        // so a forged X-Forwarded-For can't dodge the per-IP login limits.
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1'],
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Office network only while storage/framework/public-access-off exists (stockwise:public on|off)
        $middleware->prepend(\App\Http\Middleware\BlockPublicTunnel::class);

        $middleware->alias([
            'permission' => CheckPermission::class,
            // Sync Agent token check (App\Console\Commands\Accurate\AgentTokenCommand) —
            // distinct from human RBAC's `permission:` alias above.
            'abilities' => CheckAbilities::class,
            // Rejects ['*'] tokens (human logins) that `abilities:` alone would let through.
            'agent.token' => \App\Http\Middleware\EnsureAgentToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // API requests are always stateless JSON: never redirect a guest to a
        // (non-existent) login route.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        // Laravel's ValidationException hardcodes its top-level "message" to
        // the literal string "The given data was invalid." (not a real
        // translation key, so lang/id/validation.php can't reach it) — swap
        // it for an Indonesian summary. The per-field "errors" (the part the
        // frontend actually shows the user, see apiErrorMessage()) already
        // come out localized via lang/id/validation.php untouched here.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Data yang dikirim tidak valid. Periksa kembali isian yang ditandai.',
                    'errors' => $e->errors(),
                ], $e->status);
            }
        });
    })->create();
