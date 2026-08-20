<?php

use App\Events\PostPublished;
use App\Events\PostPublishFailed;
use App\Jobs\PublishScheduledPost;
use App\Models\InstagramAccount;
use App\Models\InstagramPost;
use App\Services\PublishInstagramPostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([PostPublished::class, PostPublishFailed::class]);
});

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

    Event::assertDispatched(PostPublished::class);

    Http::assertSent(function ($request) {
        return str_starts_with($request->url(), 'https://graph.instagram.com/');
    });
});

it('does not re-publish an already published post (idempotency)', function () {
    Http::fake(['*' => Http::response()]);

    $post = InstagramPost::factory()->published()->create([
        'media_id' => 'ig_media_existing',
    ]);

    connectAccountFor($post);

    (new PublishInstagramPostService)->publish($post);

    // Hiç API isteği gitmemeli — zaten yayınlanmış
    Http::assertNothingSent();

    expect($post->fresh()->status)->toBe(InstagramPost::STATUS_PUBLISHED);

    Event::assertDispatched(PostPublished::class);
});

it('resumes from existing container_id on retry (idempotency)', function () {
    Http::fakeSequence()
        ->push(['data' => [['quota_total' => 100, 'quota_used' => 10]]])
        ->push(['id' => 'ig_media_1']);

    $post = InstagramPost::factory()->create([
        'status' => InstagramPost::STATUS_DRAFT,
        'container_id' => 'ig_container_existing', // container zaten oluşturulmuş
    ]);

    connectAccountFor($post);

    (new PublishInstagramPostService)->publish($post);

    expect($post->fresh())
        ->container_id->toBe('ig_container_existing') // container değişmedi
        ->media_id->toBe('ig_media_1')
        ->status->toBe(InstagramPost::STATUS_PUBLISHED);

    // Sadece limit kontrolü + media_publish çağrıldı, /media (container create) çağrılmadı
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), '/media')
            && ! str_contains($request->url(), 'media_publish')
            && ! str_contains($request->url(), 'content_publishing_limit');
    });
});

it('leaves a failed publish retryable (publishing) without dispatching the failure event', function () {
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

    // H1: geçici hata post'u 'publishing'de bırakır (retry yeniden claim edebilir)...
    expect($post->fresh())
        ->status->toBe(InstagramPost::STATUS_PUBLISHING)
        ->error_message->not->toBeNull();

    // ...ve henüz kalıcı olmadığı için PostPublishFailed FIRLATILMAZ.
    Event::assertNotDispatched(PostPublishFailed::class);
});

it('re-publishes after a transient failure via retry reclaim', function () {
    // Tek sıra: 1. deneme (limit + /media hatası) → 2. deneme (limit + container + publish)
    Http::fakeSequence()
        ->push(['data' => [['quota_total' => 100, 'quota_used' => 10]]])
        ->push(['error' => ['message' => 'Temporary', 'type' => 'OAuthException']], 400)
        ->push(['data' => [['quota_total' => 100, 'quota_used' => 10]]])
        ->push(['id' => 'ig_container_1'])
        ->push(['id' => 'ig_media_1'])
        ->dontFailWhenEmpty();

    $post = InstagramPost::factory()->create();
    connectAccountFor($post);

    // Deneme 1: geçici hata → post 'publishing'de kalır, FAILED/event olmaz
    expect(fn () => (new PublishInstagramPostService)->publish($post))
        ->toThrow(RequestException::class);

    expect($post->fresh()->status)->toBe(InstagramPost::STATUS_PUBLISHING);

    // Deneme 2: retry — aynı post 'publishing'den yeniden claim edilip başarılı olur
    $result = (new PublishInstagramPostService)->publish($post->fresh());

    expect($result)
        ->status->toBe(InstagramPost::STATUS_PUBLISHED)
        ->media_id->toBe('ig_media_1');

    Event::assertDispatched(PostPublished::class);
    Event::assertNotDispatched(PostPublishFailed::class);
});

it('marks the post failed and dispatches the event once when the job permanently fails', function () {
    $post = InstagramPost::factory()->create();
    connectAccountFor($post);

    $job = new PublishScheduledPost($post);
    $job->failed(new RuntimeException('Kalıcı hata'));

    expect($post->fresh())
        ->status->toBe(InstagramPost::STATUS_FAILED)
        ->error_message->toBe('Kalıcı hata');

    Event::assertDispatched(PostPublishFailed::class, 1);
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

    // H1: servis status'u 'publishing' bırakır; kalıcı FAILED job'ın failed()'ında.
    expect($post->fresh())
        ->status->toBe(InstagramPost::STATUS_PUBLISHING)
        ->error_message->toBe('Instagram 24 saatlik API yayın limiti doldu.');

    Event::assertNotDispatched(PostPublishFailed::class);
});

it('refuses to publish when the post has no connected account', function () {
    Http::fake(['*' => Http::response()]);

    $post = InstagramPost::factory()->create();

    expect(fn () => (new PublishInstagramPostService)->publish($post))
        ->toThrow(RuntimeException::class);

    expect($post->fresh())->status->toBe(InstagramPost::STATUS_PUBLISHING);

    Event::assertNotDispatched(PostPublishFailed::class);
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

    expect($post->fresh())->status->toBe(InstagramPost::STATUS_PUBLISHING);

    Event::assertNotDispatched(PostPublishFailed::class);
    Http::assertNothingSent();
});
