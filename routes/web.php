<?php

use App\Http\Controllers\WorkspaceInvitationController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

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
});

require __DIR__.'/settings.php';
