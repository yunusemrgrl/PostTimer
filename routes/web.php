<?php

use App\Http\Controllers\InstagramConnectController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/privacy-policy', fn () => view('privacy-policy'))->name('privacy-policy');
Route::get('/data-deletion', fn () => view('data-deletion'))->name('data-deletion');

/*
 * Business Login for Instagram akışı. State (CSRF) oturumda
 * tutulduğu için bu rotalar web (session) middleware'indedir.
 */
Route::middleware(['auth', 'throttle:10,1'])->group(function () {
    Route::get('/instagram/connect/{tenant:slug}', [InstagramConnectController::class, 'redirect'])
        ->name('instagram.connect');

    Route::get('/instagram/callback', [InstagramConnectController::class, 'callback'])
        ->name('instagram.callback');
});

// Domain 4 — Telegram webhook (auth yok, Telegram'dan gelir)
Route::post('/telegram/webhook/{token}', [TelegramWebhookController::class, 'webhook'])
    ->name('telegram.webhook');
