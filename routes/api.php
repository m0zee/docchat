<?php

use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.')->middleware('api.key')->group(function () {
    Route::get('workspace', WorkspaceController::class)->name('workspace');
});
