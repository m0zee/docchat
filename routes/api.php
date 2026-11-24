<?php

use App\Http\Controllers\Api\AskController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

// Every route takes "Authorization: Bearer dc_live_..." and runs in the
// key's workspace. Model ids are looked up inside that workspace.
Route::prefix('v1')->name('api.')->middleware('api.key')->group(function () {
    Route::get('workspace', WorkspaceController::class)->name('workspace');

    Route::middleware('api.ability:ask')->group(function () {
        Route::post('ask', AskController::class)->name('ask');
    });

    Route::middleware('api.ability:documents:read')->group(function () {
        Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::get('documents/{document}', [DocumentController::class, 'show'])->whereNumber('document')->name('documents.show');
    });

    Route::middleware('api.ability:documents:write')->group(function () {
        Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
        Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->whereNumber('document')->name('documents.destroy');
    });
});
