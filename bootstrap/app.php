<?php

use App\Http\Controllers\WidgetScriptController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\ForgetCurrentWorkspace;
use App\Http\Middleware\RequireApiAbility;
use App\Http\Middleware\ResolveApiWorkspace;
use App\Support\Api\ApiError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::get('widget.js', WidgetScriptController::class)->name('widget.script');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // In production only Caddy can reach the app container (it has no
        // host port), so trust the proxy headers it sets: HTTPS scheme and
        // client IP. Without this, Laravel builds http:// URLs behind Caddy.
        $middleware->trustProxies(at: '*');

        $middleware->prepend(ForgetCurrentWorkspace::class);

        $middleware->alias([
            'api.key' => AuthenticateApiKey::class,
            'api.ability' => RequireApiAbility::class,
            'api.workspace' => ResolveApiWorkspace::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // One error shape for the public API.
        $exceptions->render(function (Throwable $exception, Request $request) {
            return $request->is('api/*') ? ApiError::fromException($exception) : null;
        });
    })->create();
