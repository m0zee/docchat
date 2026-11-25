<?php

use App\Http\Controllers\DiscordInteractionController;
use App\Http\Controllers\Twilio\TwilioAudioController;
use App\Http\Controllers\Twilio\TwilioVoiceController;
use App\Http\Controllers\VoiceCallScriptController;
use App\Http\Controllers\WidgetScriptController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\AuthenticateCallToken;
use App\Http\Middleware\ForgetCurrentWorkspace;
use App\Http\Middleware\RequireApiAbility;
use App\Http\Middleware\ResolveApiWorkspace;
use App\Http\Middleware\VerifyTwilioSignature;
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
            Route::post('discord/interactions', DiscordInteractionController::class)->middleware('throttle:120,1')->name('discord.interactions');
            Route::get('voice-call.js', VoiceCallScriptController::class)->name('voice-call.script');

            // The Twilio phone line. Outside the web middleware: no session,
            // no CSRF; every request is signed by Twilio instead.
            Route::middleware([VerifyTwilioSignature::class, 'throttle:240,1'])->prefix('twilio/voice')->name('twilio.voice.')->group(function (): void {
                Route::post('inbound', [TwilioVoiceController::class, 'inbound'])->name('inbound');
                Route::post('calls/{call}/answer', [TwilioVoiceController::class, 'answer'])->whereNumber('call')->name('answer');
                Route::post('calls/{call}/gather', [TwilioVoiceController::class, 'gather'])->whereNumber('call')->name('gather');
                Route::post('calls/{call}/status', [TwilioVoiceController::class, 'status'])->whereNumber('call')->name('status');
                Route::post('calls/{call}/reply/{turn}', [TwilioVoiceController::class, 'reply'])->whereNumber('call')->where('turn', '[A-Za-z0-9]{16}')->name('reply');
            });

            // Audio Twilio fetches for <Play>: the hold sound, and Kokoro clips
            // behind our own signature (Twilio doesn't sign media requests).
            Route::middleware('throttle:240,1')->prefix('twilio/voice')->name('twilio.voice.')->group(function (): void {
                Route::get('hold.wav', [TwilioAudioController::class, 'hold'])->name('hold');
                Route::get('audio/{clip}', [TwilioAudioController::class, 'clip'])->where('clip', '(c[0-9]+_)?[a-f0-9]{64}')->name('audio');
            });
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
            'call.token' => AuthenticateCallToken::class,
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
