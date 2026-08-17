<?php

use App\Models\InstagramAccount;
use App\Models\InstagramPost;
use App\Services\PublishInstagramPostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

/**
 * Gönderinin kendi hesabı: publish akışı istemciyi yalnızca bu kaydın
 * jetonuyla kurar (graph.instagram.com).
 */
function connectAccountFor(InstagramPost $post): InstagramAccount
{
    return InstagramAccount::factory()
        ->for($post->team)
        ->withToken('account-token')
        ->create([
            'ig_user_id' => $post->ig_user_id,
            'api_host' => 'graph.instagram.com',
        ]);
}

it('publishes a draft image post end to end', function () {
    Http::fakeSequence()
        ->push(['data' => [['quota_total' => 100, 'quota_used' => 10]]])
        ->push(['id' => 'ig_container_1'])
        ->push(['id' => 'ig_media_1']);

    $post = InstagramPost::factory()->create([
        'ig_user_id' => '90010177253934',
        'caption' => 'Yeni içerik',
        'alt_text' => 'Kırmızı bir portakal',
        'is_ai_generated' => true,
    ]);

    connectAccountFor($post);

    (new PublishInstagramPostService)->publish($post);

    expect($post->fresh())
        ->status->toBe(InstagramPost::STATUS_PUBLISHED)
        ->container_id->toBe('ig_container_1')
        ->media_id->toBe('ig_media_1')
        ->published_at->not->toBeNull();

    Http::assertSent(function ($request) {
        return str_starts_with($request->url(), 'https://graph.instagram.com/');
    });

    Http::assertSent(function ($request) use ($post) {
        if (! str_contains($request->url(), '/media') || str_contains($request->url(), 'media_publish')) {
            return false;
        }

        return ($request['image_url'] ?? null) === $post->media_url
            && ($request['caption'] ?? null) === 'Yeni içerik'
            && ($request['alt_text']['text'] ?? null) === 'Kırmızı bir portakal'
            && ($request['is_ai_generated'] ?? null) === true;
    });

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/media_publish')
            && $request['creation_id'] === 'ig_container_1';
    });
});

it('creates child containers then a carousel container for carousel posts', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'content_publishing_limit')) {
            return Http::response(['data' => [['quota_total' => 100, 'quota_used' => 10]]]);
        }

        if (isset($request['children'])) {
            return Http::response(['id' => 'ig_carousel_container_1']);
        }

        if (($request['is_carousel_item'] ?? null) === true) {
            return Http::response(['id' => 'ig_child_'.count(Http::recorded())]);
        }

        return Http::response(['id' => 'ig_media_carousel']);
    });

    $post = InstagramPost::factory()->create([
        'media_type' => InstagramPost::MEDIA_TYPE_CAROUSEL,
        'media_url' => null,
        'children' => [
            ['url' => 'https://example.com/images/1.jpg'],
            ['url' => 'https://example.com/images/2.jpg'],
            ['url' => 'https://example.com/videos/3.mp4'],
        ],
    ]);

    connectAccountFor($post);

    (new PublishInstagramPostService)->publish($post);

    Http::assertSent(function ($request) {
        return isset($request['children'])
            && $request['media_type'] === 'CAROUSEL'
            && str_contains($request['children'] ?? '', 'ig_child_');
    });

    Http::assertSent(function ($request) {
        return ($request['is_carousel_item'] ?? null) === true
            && ($request['image_url'] ?? null) === 'https://example.com/images/1.jpg';
    });

    Http::assertSent(function ($request) {
        return ($request['is_carousel_item'] ?? null) === true
            && ($request['video_url'] ?? null) === 'https://example.com/videos/3.mp4';
    });

    expect($post->fresh())->container_id->toBe('ig_carousel_container_1');
});

it('marks the post as failed when instagram rejects the container', function () {
    Http::fake([
        'https://graph.instagram.com/*content_publishing_limit*' => Http::response([
            'data' => [['quota_total' => 100, 'quota_used' => 10]],
        ]),
        'https://graph.instagram.com/*/media' => Http::response([
            'error' => ['message' => 'Invalid media url', 'type' => 'OAuthException'],
        ], 400),
        '*' => Http::response(),
    ]);

    $post = InstagramPost::factory()->create();
    connectAccountFor($post);

    expect(fn () => (new PublishInstagramPostService)->publish($post))
        ->toThrow(RequestException::class);

    assertDatabaseHas('instagram_posts', [
        'id' => $post->id,
        'status' => InstagramPost::STATUS_FAILED,
    ]);

    expect($post->fresh()->error_message)->not->toBeNull();
});

it('refuses to publish when the account is out of quota', function () {
    Http::fake([
        'https://graph.instagram.com/*content_publishing_limit*' => Http::response([
            'data' => [['quota_total' => 100, 'quota_used' => 100]],
        ]),
        '*' => Http::response(),
    ]);

    $post = InstagramPost::factory()->create();
    connectAccountFor($post);

    expect(fn () => (new PublishInstagramPostService)->publish($post))
        ->toThrow(RuntimeException::class);

    expect($post->fresh())
        ->status->toBe(InstagramPost::STATUS_FAILED)
        ->error_message->toBe('Instagram 24 saatlik API yayın limiti doldu.');

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), '/media_publish');
    });
});

it('refuses to publish when the post has no connected account', function () {
    Http::fake(['*' => Http::response()]);

    $post = InstagramPost::factory()->create();

    expect(fn () => (new PublishInstagramPostService)->publish($post))
        ->toThrow(RuntimeException::class);

    expect($post->fresh())
        ->status->toBe(InstagramPost::STATUS_FAILED)
        ->error_message->toBe('Gönderinin bağlı olduğu Instagram hesabı bulunamadı; önce hesabı bağlayın.');

    Http::assertNothingSent();
});

it('refuses to publish when the connected account has no token', function () {
    Http::fake(['*' => Http::response()]);

    $post = InstagramPost::factory()->create();

    InstagramAccount::factory()
        ->for($post->team)
        ->withoutToken()
        ->create(['ig_user_id' => $post->ig_user_id]);

    expect(fn () => (new PublishInstagramPostService)->publish($post))
        ->toThrow(RuntimeException::class);

    expect($post->fresh())->status->toBe(InstagramPost::STATUS_FAILED);

    Http::assertNothingSent();
});
