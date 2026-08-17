<?php

use App\Http\Controllers\InstagramConnectController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Business Login for Instagram akışı. State (CSRF) oturumda
 * tutulduğu için bu rotalar web (session) middleware'indedir.
 */
Route::middleware('auth')->group(function () {
    Route::get('/instagram/connect/{tenant:slug}', [InstagramConnectController::class, 'redirect'])
        ->name('instagram.connect');

    Route::get('/instagram/callback', [InstagramConnectController::class, 'callback'])
        ->name('instagram.callback');
});
