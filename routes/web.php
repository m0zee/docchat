<?php

use App\Http\Controllers\DocsController;
use App\Http\Controllers\WidgetPreviewController;
use App\Http\Controllers\WorkspaceInvitationController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::view('pricing', 'site.pricing')->name('pricing');

// User guide. /docs/api is the API reference (Scramble), so it is not a page here.
Route::get('docs/{page?}', DocsController::class)
    ->where('page', '^(?!api$)[a-z0-9-]+$')
    ->name('docs.show');

Route::get('invitations/{token}', [WorkspaceInvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}/accept', [WorkspaceInvitationController::class, 'accept'])
    ->middleware(['auth', 'verified', 'throttle:10,1'])
    ->name('invitations.accept');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('documents', 'pages::documents.index')->name('documents.index');

    Route::livewire('chat', 'pages::chat.index')->name('chat.index');
    Route::livewire('chat/{conversation}', 'pages::chat.index')->name('chat.show');

    Route::livewire('workspace', 'pages::workspace.general')->name('workspace.general');
    Route::livewire('workspace/members', 'pages::workspace.members')->name('workspace.members');
    Route::livewire('workspace/api-keys', 'pages::workspace.api-keys')->name('workspace.api-keys');
    Route::livewire('workspace/ai-keys', 'pages::workspace.ai-keys')->name('workspace.ai-keys');
    Route::livewire('workspace/usage', 'pages::workspace.usage')->name('workspace.usage');
    Route::livewire('workspace/webhooks', 'pages::workspace.webhooks')->name('workspace.webhooks');
    Route::livewire('workspace/chat-apps', 'pages::workspace.channels')->name('workspace.channels');
    Route::livewire('workspace/widget', 'pages::workspace.widget')->name('workspace.widget');
    Route::get('workspace/widget/preview', WidgetPreviewController::class)->name('workspace.widget.preview');
});

require __DIR__.'/settings.php';
