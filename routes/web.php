<?php

use App\Http\Controllers\TelegramWebhookController;
use App\Livewire\Inbox;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::post('/webhooks/telegram', TelegramWebhookController::class);

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::get('/inbox', Inbox::class)->middleware(['auth'])->name('inbox');

require __DIR__.'/auth.php';
