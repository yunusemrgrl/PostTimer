<?php

use App\Http\Middleware\BlockMaliciousRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            BlockMaliciousRequests::class,
            'throttle:120,1',
        ]);

        // Telegram webhook'u CSRF'ten muaftır: istekler bot'tan gelir, CSRF token göndermez.
        // Tek bot → tek endpoint; güvenlik, rastgele `verification_code` eşleşmesiyle sağlanır.
        // MCP JSON-RPC endpoint'i de stateless'tır (token auth production'da eklenecek).
        $middleware->validateCsrfTokens(except: [
            'telegram/webhook',
            'mcp/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
