<?php

use App\Models\InstagramAccount;
use App\Models\Team;
use App\Services\InstagramPublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('creates an image media container with alt text and AI disclosure', function () {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response([
            'id' => 'ig_container_123',
        ], 200),
    ]);

    $service = new InstagramPublishingService(
        token: 'test-token',
        apiVersion: 'v25.0',
        host: 'graph.facebook.com',
    );

    $response = $service->createMediaContainer('90010177253934', [
        'image_url' => 'https://example.com/images/bronz-fonz.jpg',
        'caption' => 'Yeni içerik',
        'media_type' => 'IMAGE',
        'is_ai_generated' => true,
        'alt_text' => ['text' => 'Kırmızı bir portakal ve gökyüzü'],
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://graph.facebook.com/v25.0/90010177253934/media'
            && $request['access_token'] === 'test-token'
            && $request['image_url'] === 'https://example.com/images/bronz-fonz.jpg'
            && $request['caption'] === 'Yeni içerik'
            && $request['media_type'] === 'IMAGE'
            && $request['is_ai_generated'] === true
            && $request['alt_text']['text'] === 'Kırmızı bir portakal ve gökyüzü';
    });

    expect($response)->toMatchArray([
        'id' => 'ig_container_123',
    ]);
});

it('creates a carousel container with up to 10 children', function () {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['id' => 'ig_carousel_container_1']),
    ]);

    $service = new InstagramPublishingService(token: 'test-token');

    $response = $service->createCarouselContainer('90010177253934', [
        'ig_container_1',
        'ig_container_2',
        'ig_container_3',
    ], [
        'caption' => 'Fruit candies',
        'is_ai_generated' => true,
    ]);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://graph.facebook.com/v25.0/90010177253934/media'
            && $request['media_type'] === 'CAROUSEL'
            && $request['children'] === 'ig_container_1,ig_container_2,ig_container_3'
            && $request['caption'] === 'Fruit candies'
            && $request['is_ai_generated'] === true;
    });

    expect($response)->toMatchArray(['id' => 'ig_carousel_container_1']);
});

it('creates a resumable upload session container', function () {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['id' => 'ig_container_resumable']),
    ]);

    $service = new InstagramPublishingService(token: 'test-token');

    $service->createResumableUploadSession('90010177253934', 'REELS');

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request['media_type'] === 'REELS'
            && $request['upload_type'] === 'resumable';
    });
});

it('uploads a hosted video to a resumable container via rupload', function () {
    Http::fake([
        'https://rupload.facebook.com/*' => Http::response(['success' => true, 'message' => 'Upload successful.']),
    ]);

    $service = new InstagramPublishingService(token: 'test-token');

    $response = $service->uploadVideoFromUrl(
        'ig_container_resumable',
        'https://example.com/videos/bronz-fonz.mp4',
    );

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://rupload.facebook.com/ig-api-upload/v25.0/ig_container_resumable'
            && $request->hasHeader('Authorization', 'OAuth test-token')
            && $request->hasHeader('file_url', 'https://example.com/videos/bronz-fonz.mp4');
    });

    expect($response)->toMatchArray(['success' => true]);
});

it('uploads a local video file with offset and file size headers', function () {
    Http::fake([
        'https://rupload.facebook.com/*' => Http::response(['success' => true, 'message' => 'Upload successful.']),
    ]);

    $filePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ig-test-video.mp4';
    file_put_contents($filePath, 'video');

    $service = new InstagramPublishingService(token: 'test-token');

    $response = $service->uploadVideoFile('ig_container_resumable', $filePath);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://rupload.facebook.com/ig-api-upload/v25.0/ig_container_resumable'
            && $request->hasHeader('Authorization', 'OAuth test-token')
            && $request->hasHeader('offset', '0')
            && $request->hasHeader('file_size', '5');
    });

    expect($response)->toMatchArray(['success' => true]);

    unlink($filePath);
});

it('polls the container status until it is finished', function () {
    Http::fakeSequence()
        ->push(['status_code' => 'IN_PROGRESS'])
        ->push(['status_code' => 'FINISHED']);

    $service = new InstagramPublishingService(token: 'test-token', statusSleepMs: 0);

    $status = $service->waitForContainerToFinish('ig_container_1');

    expect($status)->toMatchArray(['status_code' => 'FINISHED']);
});

it('detects when the 24-hour publishing quota is exhausted', function () {
    Http::fake([
        'https://graph.facebook.com/*content_publishing_limit*' => Http::response([
            'data' => [
                ['quota_total' => 100, 'quota_used' => 100],
            ],
        ]),
        '*' => Http::response(),
    ]);

    $service = new InstagramPublishingService(token: 'test-token');

    expect($service->isWithinPublishingLimit('90010177253934'))->toBeFalse();
});

it('builds the client strictly from account credentials without fallback', function () {
    $team = Team::factory()->create();

    $account = InstagramAccount::factory()
        ->for($team)
        ->withToken('account-token')
        ->create(['api_host' => 'graph.instagram.com']);

    $client = InstagramPublishingService::forAccount($account);

    expect($client->getToken())->toBe('account-token');

    Http::fake(['*' => Http::response(['username' => 'bronzfonz'])]);

    $client->getAccount('90010177253934');

    Http::assertSent(function ($request) {
        return str_starts_with($request->url(), 'https://graph.instagram.com/');
    });
});

it('refuses to build a client for an account without a token', function () {
    $team = Team::factory()->create();

    $account = InstagramAccount::factory()
        ->for($team)
        ->withoutToken()
        ->create();

    InstagramPublishingService::forAccount($account);
})->throws(RuntimeException::class);
