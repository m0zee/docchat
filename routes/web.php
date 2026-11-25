<?php

use App\Http\Controllers\CallLinkController;
use App\Http\Controllers\WidgetPreviewController;
use App\Http\Controllers\WorkspaceInvitationController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('invitations/{token}', [WorkspaceInvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}/accept', [WorkspaceInvitationController::class, 'accept'])
    ->middleware(['auth', 'verified', 'throttle:10,1'])
    ->name('invitations.accept');

// Outbound call links: no account needed, the token in the link is the key.
Route::get('call/{token}', [CallLinkController::class, 'show'])->middleware('throttle:30,1')->name('calls.link');
Route::post('call/{token}/opt-out', [CallLinkController::class, 'optOut'])->middleware('throttle:10,1')->name('calls.opt-out');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('documents', 'pages::documents.index')->name('documents.index');

    Route::livewire('chat', 'pages::chat.index')->name('chat.index');
    Route::livewire('chat/{conversation}', 'pages::chat.index')->name('chat.show');

    Route::livewire('workspace', 'pages::workspace.general')->name('workspace.general');
    Route::livewire('workspace/members', 'pages::workspace.members')->name('workspace.members');
    Route::livewire('workspace/api-keys', 'pages::workspace.api-keys')->name('workspace.api-keys');
    Route::livewire('workspace/usage', 'pages::workspace.usage')->name('workspace.usage');
    Route::livewire('workspace/webhooks', 'pages::workspace.webhooks')->name('workspace.webhooks');
    Route::livewire('workspace/chat-apps', 'pages::workspace.channels')->name('workspace.channels');
    Route::livewire('workspace/widget', 'pages::workspace.widget')->name('workspace.widget');
    Route::livewire('workspace/calls', 'pages::workspace.calls')->name('workspace.calls');
    Route::get('workspace/widget/preview', WidgetPreviewController::class)->name('workspace.widget.preview');
});

require __DIR__.'/settings.php';
