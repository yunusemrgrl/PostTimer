<?php

use App\Domain\Publishing\Services\PublicationPublishingService;
use App\Jobs\PublishScheduledPublication;
use App\Models\InstagramAccount;
use App\Models\Publication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function publicationJobAccount(Publication $publication): void
{
    InstagramAccount::factory()->create([
        'team_id' => $publication->team_id,
        'ig_user_id' => $publication->ig_user_id,
        'access_token' => 'account-token',
        'api_host' => 'graph.instagram.com',
    ]);
}

it('can be dispatched for a scheduled publication', function () {
    Queue::fake();

    $publication = Publication::factory()->scheduled()->create();

    PublishScheduledPublication::dispatch($publication);

    Queue::assertPushed(PublishScheduledPublication::class);
});

it('builds the correct unique id', function () {
    $publication = Publication::factory()->create();

    expect((new PublishScheduledPublication($publication))->uniqueId())
        ->toBe("publish-publication-{$publication->id}");
});

it('does not enqueue a duplicate job for the same publication', function () {
    Queue::fake();

    $publication = Publication::factory()->scheduled()->create();

    PublishScheduledPublication::dispatch($publication);
    PublishScheduledPublication::dispatch($publication);

    Queue::assertPushed(PublishScheduledPublication::class, 1);
});

it('publishes the publication through the publishing service', function () {
    Queue::fake();

    Http::fakeSequence()
        ->push(['data' => [['quota_usage' => 10, 'config' => ['quota_total' => 100]]]])
        ->push(['id' => 'ig_container_1'])
        ->push(['id' => 'ig_media_1'])
        ->push(['id' => 'ig_media_1', 'permalink' => 'https://www.instagram.com/p/abc123/']);

    $publication = Publication::factory()->scheduled()->create();
    publicationJobAccount($publication);

    (new PublishScheduledPublication($publication))
        ->handle(app(PublicationPublishingService::class));

    expect($publication->fresh())
        ->status->toBe(Publication::STATUS_PUBLISHED)
        ->media_id->toBe('ig_media_1');
});

it('keeps retryable error state and lets the queue retry', function () {
    Http::fakeSequence()
        // Kota dolu → RuntimeException (retry edilemez iş kuralı hatası)
        ->push(['data' => [['quota_usage' => 100, 'config' => ['quota_total' => 100]]]]);

    $publication = Publication::factory()->scheduled()->create();
    publicationJobAccount($publication);

    $job = new PublishScheduledPublication($publication);

    expect(fn () => $job->handle(app(PublicationPublishingService::class)))
        ->toThrow(RuntimeException::class);

    // H1: servis status'u 'publishing' bırakır; kalıcı FAILED failed()'da.
    expect($publication->fresh())
        ->status->toBe(Publication::STATUS_PUBLISHING)
        ->error_message->toBe('Instagram 24 saatlik API yayın limiti doldu.');

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([30, 120, 300]);
});

it('marks the publication as permanently failed when all retries are exhausted', function () {
    $publication = Publication::factory()->scheduled()->create();

    $job = new PublishScheduledPublication($publication);

    $job->failed(new RuntimeException('Tüm retry\'lar tükendi'));

    expect($publication->fresh())
        ->status->toBe(Publication::STATUS_FAILED)
        ->error_message->toBe('Tüm retry\'lar tükendi');
});

it('does not overwrite an already published publication in failed()', function () {
    $publication = Publication::factory()->published()->create([
        'media_id' => 'ig_media_existing',
    ]);

    (new PublishScheduledPublication($publication))->failed(new RuntimeException('gecikmiş hata'));

    expect($publication->fresh())
        ->status->toBe(Publication::STATUS_PUBLISHED)
        ->error_message->toBeNull();
});

it('skips cancelled publications', function () {
    Http::fake(['*' => Http::response()]);

    $publication = Publication::factory()->cancelled()->create();

    (new PublishScheduledPublication($publication))
        ->handle(app(PublicationPublishingService::class));

    Http::assertNothingSent();

    expect($publication->fresh())->status->toBe(Publication::STATUS_CANCELLED);
});

it('skips published publications', function () {
    Http::fake(['*' => Http::response()]);

    $publication = Publication::factory()->published()->create();

    (new PublishScheduledPublication($publication))
        ->handle(app(PublicationPublishingService::class));

    Http::assertNothingSent();

    expect($publication->fresh())->status->toBe(Publication::STATUS_PUBLISHED);
});

it('skips flagged publications', function () {
    Http::fake(['*' => Http::response()]);

    $publication = Publication::factory()->flagged()->create();

    (new PublishScheduledPublication($publication))
        ->handle(app(PublicationPublishingService::class));

    Http::assertNothingSent();

    expect($publication->fresh())->status->toBe(Publication::STATUS_FLAGGED);
});

it('keeps its retry and uniqueness configuration', function () {
    $publication = Publication::factory()->create();
    $job = new PublishScheduledPublication($publication);

    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(420)
        ->and($job->backoff())->toBe([30, 120, 300])
        // Kilit, en kötü toplam çalışma süresini (≈30 dk) kapsamalı.
        ->and($job->uniqueFor)->toBe(1800)
        ->and($job->uniqueId())->toBe('publish-publication-'.$publication->id);
});
