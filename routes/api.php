<?php

use App\Http\Controllers\Api\AskController;
use App\Http\Controllers\Api\AskStreamController;
use App\Http\Controllers\Api\CallController;
use App\Http\Controllers\Api\CallSessionController;
use App\Http\Controllers\Api\ChannelLinkController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DeviceAskController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\EvalController;
use App\Http\Controllers\Api\VoiceController;
use App\Http\Controllers\Api\WidgetController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.')->group(function () {
    // Shared bots: link chats to workspaces with a platform service key.
    Route::middleware(['api.key:service', 'api.ability:channels:link'])
        ->prefix('channels/{channel}')
        ->whereIn('channel', ['telegram', 'whatsapp', 'discord'])
        ->name('channels.')
        ->group(function () {
            Route::post('link', [ChannelLinkController::class, 'link'])->name('link');
            Route::post('resolve', [ChannelLinkController::class, 'resolve'])->name('resolve');
            Route::post('unlink', [ChannelLinkController::class, 'unlink'])->name('unlink');
        });

    // Every route below takes "Authorization: Bearer dc_live_..." and runs in
    // the key's workspace, or a service key plus "X-DocChat-Link" and runs in
    // the linked chat's workspace. Model ids are looked up inside it.
    // Asking also works with the website widget's public key (dc_pub_...),
    // from the origins listed for it, in widget conversations only.
    Route::middleware(['api.key:live,service,public', 'api.workspace', 'api.ability:ask'])->group(function () {
        Route::post('ask', AskController::class)->name('ask');
        Route::match(['get', 'post'], 'ask/stream', AskStreamController::class)->name('ask.stream');
        Route::get('widget', WidgetController::class)->name('widget');
        Route::post('ask/device', [DeviceAskController::class, 'prepare'])->name('ask.device');
        Route::post('ask/device/complete', [DeviceAskController::class, 'complete'])->name('ask.device.complete');
        Route::post('calls', [CallController::class, 'store'])->name('calls.store');
    });

    // A browser call in progress: "Authorization: Bearer dc_call_..." from
    // POST /calls or an outbound call link. Works only for that one call.
    Route::middleware(['call.token', 'throttle:calls'])->prefix('call')->name('call.')->group(function () {
        Route::post('answer', [CallSessionController::class, 'answer'])->name('answer');
        Route::post('turns', [CallSessionController::class, 'turn'])->name('turns');
        Route::post('end', [CallSessionController::class, 'end'])->name('end');
    });

    Route::middleware(['api.key:live,service', 'api.workspace'])->group(function () {
        Route::get('workspace', WorkspaceController::class)->name('workspace');

        Route::middleware('api.ability:ask')->prefix('voice')->name('voice.')->group(function () {
            Route::post('ask', [VoiceController::class, 'ask'])->name('ask');
            Route::post('speak', [VoiceController::class, 'speak'])->name('speak');
        });

        Route::post('conversations/end', [ConversationController::class, 'end'])->middleware('api.ability:ask')->name('conversations.end');

        Route::middleware('api.ability:documents:read')->group(function () {
            Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
            Route::get('documents/{document}', [DocumentController::class, 'show'])->whereNumber('document')->name('documents.show');
        });

        Route::middleware('api.ability:documents:write')->group(function () {
            Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
            Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->whereNumber('document')->name('documents.destroy');
        });

        Route::middleware('api.ability:evals:run')->group(function () {
            Route::post('evals/run', [EvalController::class, 'store'])->name('evals.run');
            Route::get('evals/{evalRun}', [EvalController::class, 'show'])->whereNumber('evalRun')->name('evals.show');
        });
    });
});
