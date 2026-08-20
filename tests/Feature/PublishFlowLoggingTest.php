<?php

use App\Events\PostPublished;
use App\Events\PostPublishFailed;
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

function publishFlowLogPath(): string
{
    return storage_path('logs/publish-flow-test.log');
}

/**
 * 'publish' kanalını test için ayrı bir dosyaya yönlendirir ve
 * önceki içeriği temizler.
 */
function enablePublishFlowLog(): string
{
    $path = publishFlowLogPath();
    @unlink($path);
    config(['logging.channels.publish.path' => $path]);

    return $path;
}

function readPublishFlowLog(): string
{
    clearstatcache();

    $path = publishFlowLogPath();

    return is_file($path) ? (string) file_get_contents($path) : '';
}

function connectAccountForFlowLogs(InstagramPost $post): InstagramAccount
{
    return InstagramAccount::factory()
        ->for($post->team)
        ->withToken('account-token')
        ->create([
            'ig_user_id' => $post->ig_user_id,
            'api_host' => 'graph.instagram.com',
        ]);
}

it('logs every publish flow stage with correlation context without changing behavior', function () {
    enablePublishFlowLog();

    Http::fakeSequence()
        ->push(['data' => [['quota_total' => 100, 'quota_used' => 10]]])
        ->push(['id' => 'ig_container_1'])
        ->push(['id' => 'ig_media_1']);

    $post = InstagramPost::factory()->create([
        'caption' => 'Secret caption content',
    ]);

    connectAccountForFlowLogs($post);

    (new PublishInstagramPostService)->publish($post, 'flow-success');

    $content = readPublishFlowLog();

    // Akışın tüm aşamaları loglandı
    expect($content)
        ->toContain('publish.start')
        ->toContain('publish.claim.ok')
        ->toContain('publish.lock.acquired')
        ->toContain('publish.client.resolved')
        ->toContain('publish.limit.ok')
        ->toContain('publish.media.url')
        ->toContain('publish.container.ready')
        ->toContain('publish.media.published')
        ->toContain('publish.persist')
        ->toContain('event.post_published')
        // Instagram'a gönderilen gerçek medya URL'si logda görünür
        ->toContain('example.com');

    // Korelasyon bağlamı: flow_id, post_id, team_id, ig_user_id
    expect($content)
        ->toContain('"flow_id":"flow-success"')
        ->toContain('"post_id":'.$post->id)
        ->toContain('"team_id":'.$post->team_id)
        ->toContain('"ig_user_id":"'.$post->ig_user_id.'"')
        ->toContain('"trigger":"scheduled"');

    // Hassas veri loglanmaz: caption ve access token hiçbir satırda yok
    expect($content)
        ->not->toContain('Secret caption content')
        ->not->toContain('account-token');

    // Davranış değişmedi: post yayınlandı ve event fırlatıldı
    expect($post->fresh())
        ->status->toBe(InstagramPost::STATUS_PUBLISHED)
        ->media_id->toBe('ig_media_1');

    Event::assertDispatched(PostPublished::class);
});

it('logs the error stage without changing H1 retry behavior', function () {
    enablePublishFlowLog();

    Http::fake([
        'https://graph.instagram.com/*content_publishing_limit*' => Http::response([
            'data' => [['quota_total' => 100, 'quota_used' => 10]],
        ]),
        'https://graph.instagram.com/*/media' => Http::response([
            'error' => ['message' => 'Temporary', 'type' => 'OAuthException'],
        ], 400),
        '*' => Http::response(),
    ]);

    $post = InstagramPost::factory()->create();
    connectAccountForFlowLogs($post);

    expect(fn () => (new PublishInstagramPostService)->publish($post, 'flow-error'))
        ->toThrow(RequestException::class);

    $content = readPublishFlowLog();

    expect($content)
        ->toContain('publish.error')
        ->toContain('"flow_id":"flow-error"')
        ->toContain('"error_class":"'.str_replace('\\', '\\\\', RequestException::class).'"')
        ->toContain('"retryable":true');

    // H1 davranışı korundu: status 'publishing' kaldı, FAILED event yok
    expect($post->fresh())
        ->status->toBe(InstagramPost::STATUS_PUBLISHING)
        ->error_message->not->toBeNull();

    Event::assertNotDispatched(PostPublishFailed::class);
});

it('logs the skip stage for an already published post', function () {
    enablePublishFlowLog();

    Http::fake(['*' => Http::response()]);

    $post = InstagramPost::factory()->published()->create([
        'media_id' => 'ig_media_existing',
    ]);

    connectAccountForFlowLogs($post);

    (new PublishInstagramPostService)->publish($post, 'flow-skip');

    $content = readPublishFlowLog();

    expect($content)
        ->toContain('publish.start')
        ->toContain('publish.skip')
        ->toContain('"reason":"already_published"')
        ->toContain('"flow_id":"flow-skip"')
        ->not->toContain('publish.media.published');

    Http::assertNothingSent();
    Event::assertDispatched(PostPublished::class);
});

it('warns when the media url is not publicly reachable', function () {
    enablePublishFlowLog();

    Http::fakeSequence()
        ->push(['data' => [['quota_total' => 100, 'quota_used' => 10]]])
        ->push(['id' => 'ig_container_1'])
        ->push(['id' => 'ig_media_1']);

    // http şeması + .test hostu: Instagram bunu asla erişemez
    $post = InstagramPost::factory()->create([
        'media_url' => 'http://posttimer.test/media/foto.jpg',
    ]);

    connectAccountForFlowLogs($post);

    (new PublishInstagramPostService)->publish($post, 'flow-local');

    $content = readPublishFlowLog();

    expect($content)
        ->toContain('publish.media.url')
        ->toContain('publish.media.url.not_public')
        ->toContain('"url_scheme":"http"')
        ->toContain('"url_host":"posttimer.test"');

    // Yalnızca gözlem: publish davranışı değişmez
    expect($post->fresh())->status->toBe(InstagramPost::STATUS_PUBLISHED);
});

it('warns when the media url points at a storage api host instead of a public host', function () {
    enablePublishFlowLog();

    Http::fakeSequence()
        ->push(['data' => [['quota_total' => 100, 'quota_used' => 10]]])
        ->push(['id' => 'ig_container_1'])
        ->push(['id' => 'ig_media_1']);

    // R2 API endpoint host'u: Instagram buradan public içerik çekemez;
    // disk config'indeki public url (R2_URL) kullanılmalıdır.
    $post = InstagramPost::factory()->create([
        'media_url' => 'https://bucket.1234567890.r2.cloudflarestorage.com/tenants/x/foto.jpg',
    ]);

    connectAccountForFlowLogs($post);

    (new PublishInstagramPostService)->publish($post, 'flow-r2');

    $content = readPublishFlowLog();

    expect($content)
        ->toContain('publish.media.url')
        ->toContain('publish.media.url.not_public')
        ->toContain('"reason":"storage_api_host"');
});

it('propagates the manual flow id from publishNow into the publish stages', function () {
    enablePublishFlowLog();

    Http::fakeSequence()
        ->push(['data' => [['quota_total' => 100, 'quota_used' => 10]]])
        ->push(['id' => 'ig_container_1'])
        ->push(['id' => 'ig_media_1']);

    $post = InstagramPost::factory()->create([
        'status' => InstagramPost::STATUS_SCHEDULED,
        'scheduled_at' => now()->addHour(),
    ]);

    connectAccountForFlowLogs($post);

    (new PublishInstagramPostService)->publishNow($post, 'flow-manual');

    $content = readPublishFlowLog();

    expect($content)
        ->toContain('"flow_id":"flow-manual"')
        ->toContain('"trigger":"manual"')
        ->toContain('publish.start')
        ->toContain('publish.persist');

    expect($post->fresh())
        ->status->toBe(InstagramPost::STATUS_PUBLISHED)
        ->scheduled_at->toBeNull();

    Event::assertDispatched(PostPublished::class);
});
