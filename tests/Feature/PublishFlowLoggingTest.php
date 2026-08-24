<?php

use App\Events\PublicationPublished;
use App\Events\PublicationPublishFailed;
use App\Models\Content;
use App\Models\InstagramAccount;
use App\Models\Publication;
use App\Services\PublicationPublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([PublicationPublished::class, PublicationPublishFailed::class]);
    Queue::fake();
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

function publicationWithAccount(array $attributes = []): Publication
{
    $defaults = Content::factory()->create();
    $publication = Publication::factory()->create([
        'team_id' => $defaults->team_id,
        'content_id' => $defaults->id,
        'status' => Publication::STATUS_PUBLISHING,
        ...$attributes,
    ]);

    InstagramAccount::factory()->create([
        'team_id' => $publication->team_id,
        'ig_user_id' => $publication->ig_user_id,
        'access_token' => 'account-token',
        'api_host' => 'graph.instagram.com',
    ]);

    return $publication;
}

it('logs every publish flow stage with correlation context without changing behavior', function () {
    $path = enablePublishFlowLog();

    Http::fakeSequence()
        ->push(['data' => [['quota_usage' => 10, 'config' => ['quota_total' => 100]]]])
        ->push(['id' => 'ctr_1'])
        ->push(['id' => 'med_1'])
        ->push(['id' => 'med_1', 'permalink' => 'https://instagram.com/p/x/', 'timestamp' => '2026-01-01T10:00:00+0000']);

    $publication = publicationWithAccount([
        'status' => Publication::STATUS_SCHEDULED,
        'scheduled_at' => now()->subMinute(),
    ]);
    InstagramAccount::query()->first(); // eager yok — servis kendisi çözer

    (new PublicationPublishingService)->publish($publication, 'flow-abc');

    $log = readPublishFlowLog();

    expect($log)
        ->toContain('publish.start')
        ->toContain('flow-abc')
        ->toContain('publish.claim.ok')
        ->toContain('publish.limit.ok')
        ->toContain('publish.media.url')
        ->toContain('publish.container.ready')
        ->toContain('publish.media.published')
        ->toContain('"persist":"published"');
});

it('logs the error stage without changing H1 retry behavior', function () {
    enablePublishFlowLog();

    Http::fakeSequence()
        ->push(['data' => [['quota_usage' => 10, 'config' => ['quota_total' => 100]]]])
        ->push(['error' => ['message' => 'boom']], 500);

    $publication = publicationWithAccount();

    expect(fn () => (new PublicationPublishingService)->publish($publication))
        ->toThrow(RequestException::class);

    // H1: status publishing'te kalır, sadece uyarı loglanır.
    expect($publication->fresh())
        ->status->toBe(Publication::STATUS_PUBLISHING)
        ->and(readPublishFlowLog())
        ->toContain('publish.error')
        ->toContain('retryable');
});

it('logs the skip stage for an already published publication', function () {
    enablePublishFlowLog();

    Http::fake(['*' => Http::response()]);

    $publication = Publication::factory()->published()->create();

    (new PublicationPublishingService)->publish($publication);

    Http::assertNothingSent();

    expect(readPublishFlowLog())
        ->toContain('publish.skip')
        ->toContain('already_published');
});

it('warns when the media url is not publicly reachable', function () {
    $path = enablePublishFlowLog();

    $content = Content::factory()->create([
        'media_url' => 'http://localhost/storage/media/image.jpg',
    ]);
    $publication = publicationWithAccount(['content_id' => $content->id]);

    Http::fakeSequence()
        ->push(['data' => [['quota_usage' => 10, 'config' => ['quota_total' => 100]]]])
        ->push(['id' => 'ctr_local_1'])
        ->push(['id' => 'med_local_1'])
        ->push(['id' => 'med_local_1', 'permalink' => 'https://instagram.com/p/y/', 'timestamp' => '2026-01-01T10:00:00+0000']);

    $published = (new PublicationPublishingService)->publish($publication);

    expect($published)->status->toBe(Publication::STATUS_PUBLISHED);
    expect(readPublishFlowLog())->toContain('publish.media.url.not_public');
});
