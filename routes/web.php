<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('documents', 'pages::documents.index')->name('documents.index');

    Route::livewire('chat', 'pages::chat.index')->name('chat.index');
    Route::livewire('chat/{conversation}', 'pages::chat.index')->name('chat.show');
});

require __DIR__.'/settings.php';
