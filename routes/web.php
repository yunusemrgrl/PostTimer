<?php

use App\Http\Controllers\InstagramConnectController;
use App\Http\Controllers\MediaThumbnailController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/privacy-policy', fn () => view('privacy-policy'))->name('privacy-policy');
Route::get('/data-deletion', fn () => view('data-deletion'))->name('data-deletion');

Route::middleware(['auth', 'throttle:10,1'])->group(function () {
    Route::get('/instagram/connect/{tenant:slug}', [InstagramConnectController::class, 'redirect'])
        ->name('instagram.connect');

    Route::get('/instagram/callback', [InstagramConnectController::class, 'callback'])
        ->name('instagram.callback');
});

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'webhook'])
    ->middleware('throttle:60,1')
    ->name('telegram.webhook');

Route::get('/media/{media:name}/thumbnail', [MediaThumbnailController::class, 'show'])
    ->name('media.thumbnail');

Route::post('/media/{media:name}/thumbnail', [MediaThumbnailController::class, 'store'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('media.thumbnail.store');

Route::get('/media/{media:name}/video', [MediaThumbnailController::class, 'video'])
    ->name('media.video');

// MCP sunucusu (laravel/mcp) � ayr�nt�lar: routes/ai.php
require __DIR__.'/ai.php';
