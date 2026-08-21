<?php

use App\Http\Controllers\InstagramConnectController;
use App\Http\Controllers\MediaThumbnailController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;
use App\Models\InstagramPost;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/privacy-policy', fn () => view('privacy-policy'))->name('privacy-policy');
Route::get('/data-deletion', fn () => view('data-deletion'))->name('data-deletion');

Route::middleware(['auth', 'throttle:10,1'])->group(function () {
    Route::get('/instagram/connect/{tenant:slug}', [InstagramConnectController::class, 'redirect'])
        ->name('instagram.connect');

    Route::get('/instagram/callback', [InstagramConnectController::class, 'callback'])
        ->name('instagram.callback');
});

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'webhook'])
    ->name('telegram.webhook');

Route::get('/media/{media:name}/thumbnail', [MediaThumbnailController::class, 'show'])
    ->name('media.thumbnail');

Route::get('/media/{media:name}/video', [MediaThumbnailController::class, 'video'])
    ->name('media.video');

Route::get('/debug/instagram-story', function () {
    $post = InstagramPost::query()
        ->where('media_product_type', InstagramPost::PRODUCT_TYPE_STORY)
        ->latest('id')
        ->firstOrFail();

    $url = $post->media_url;

    $response = Http::timeout(30)
        ->connectTimeout(10)
        ->get($url);

    return response()->json([
        'post_id' => $post->id,
        'status' => $response->status(),
        'url' => $url,
        'content_type' => $response->header('Content-Type'),
        'content_length' => $response->header('Content-Length'),
        'bytes_received' => strlen($response->body()),
        'first_32_bytes_hex' => bin2hex(substr($response->body(), 0, 32)),
    ]);
});
