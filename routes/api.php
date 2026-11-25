<?php

use App\Http\Controllers\Api\AskController;
use App\Http\Controllers\Api\AskStreamController;
use App\Http\Controllers\Api\ChannelLinkController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\EvalController;
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
    Route::middleware(['api.key:live,service', 'api.workspace'])->group(function () {
        Route::get('workspace', WorkspaceController::class)->name('workspace');

        Route::middleware('api.ability:ask')->group(function () {
            Route::post('ask', AskController::class)->name('ask');
            Route::match(['get', 'post'], 'ask/stream', AskStreamController::class)->name('ask.stream');
            Route::post('conversations/end', [ConversationController::class, 'end'])->name('conversations.end');
        });

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
