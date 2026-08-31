<?php

use App\Domain\Video\Services\GeminiVideoTranslationService;
use Illuminate\Support\Facades\Http;

it('rejects download errors with the source url in the message', function () {
    Http::fake([
        'example.com/*' => Http::response('nope', 404),
    ]);

    $service = new GeminiVideoTranslationService;

    (new ReflectionMethod($service, 'downloadVideo'))
        ->invoke($service, 'https://example.com/videos/x.mp4');
})->throws(RuntimeException::class, 'Video indirilemedi (HTTP 404): https://example.com/videos/x.mp4');

it('rejects empty downloads', function () {
    Http::fake([
        'example.com/*' => Http::response(''),
    ]);

    $service = new GeminiVideoTranslationService;

    (new ReflectionMethod($service, 'downloadVideo'))
        ->invoke($service, 'https://example.com/videos/empty.mp4');
})->throws(RuntimeException::class, 'Video indirme bos dondu.');

it('rejects videos above the 20MB inline limit', function () {
    $service = new GeminiVideoTranslationService;

    $guard = new ReflectionMethod($service, 'ensureWithinInlineLimit');

    $guard->invoke($service, 21 * 1024 * 1024, 'https://example.com/videos/big.mp4');
})->throws(RuntimeException::class, 'Video inline analiz limitini asiyo (21MB > 20MB)');

it('accepts videos at the 20MB inline limit boundary', function () {
    $service = new GeminiVideoTranslationService;

    $guard = new ReflectionMethod($service, 'ensureWithinInlineLimit');

    $guard->invoke($service, 20 * 1024 * 1024, 'https://example.com/videos/ok.mp4');

    expect(true)->toBeTrue();
});
