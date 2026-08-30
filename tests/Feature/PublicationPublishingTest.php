<?php

use App\Domain\Publishing\Services\PublicationPublishingService;
use App\Models\Content;
use App\Models\InstagramAccount;
use App\Models\Publication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function publicationAccount(Publication $publication): InstagramAccount
{
    return InstagramAccount::factory()
        ->create([
            'team_id' => $publication->team_id,
            'ig_user_id' => $publication->ig_user_id,
            'access_token' => 'account-token',
            'api_host' => 'graph.instagram.com',
        ]);
}

it('publishes a scheduled publication end to end', function () {
    // Başarı publish PublicationPublished event'ini tetikler; B1 insight
    // job'ını inline koşmamaya Queue::fake — bu test publish'i yalnızca ölçir.
    Queue::fake();

    Http::fakeSequence()
        ->push(['data' => [['quota_usage' => 10, 'config' => ['quota_total' => 100]]]])
        ->push(['id' => 'ig_container_1'])
        ->push(['id' => 'ig_media_1'])
        ->push(['id' => 'ig_media_1', 'permalink' => 'https://www.instagram.com/p/abc123/', 'timestamp' => '2026-01-01 10:00:00+0000']);

    $publication = Publication::factory()->scheduled()->create();

    publicationAccount($publication);

    $published = (new PublicationPublishingService)->publish($publication);

    expect($published)
        ->status->toBe(Publication::STATUS_PUBLISHED)
        ->container_id->toBe('ig_container_1')
        ->media_id->toBe('ig_media_1')
        ->published_at->not->toBeNull()
        ->scheduled_at->toBeNull()
        ->error_message->toBeNull();

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://graph.instagram.com/'));
});

it('does not re-publish an already published publication', function () {
    Http::fake(['*' => Http::response()]);

    $publication = Publication::factory()->published()->create([
        'media_id' => 'ig_media_existing',
    ]);

    publicationAccount($publication);

    (new PublicationPublishingService)->publish($publication);

    // Hiç API isteği gitmemeli — zaten yayınlanmış
    Http::assertNothingSent();

    expect($publication->fresh())
        ->status->toBe(Publication::STATUS_PUBLISHED)
        ->media_id->toBe('ig_media_existing');
});

it('marks the publication as failed on manual publish error', function () {
    Http::fakeSequence()
        // Kota dolu → RuntimeException (retry edilemez iş kuralı hatası)
        ->push(['data' => [['quota_usage' => 100, 'config' => ['quota_total' => 100]]]]);

    $publication = Publication::factory()->scheduled()->create();
    publicationAccount($publication);

    expect(fn () => (new PublicationPublishingService)->publishNow($publication))
        ->toThrow(RuntimeException::class);

    expect($publication->fresh())
        ->status->toBe(Publication::STATUS_FAILED)
        ->error_message->toBe('Instagram 24 saatlik API yayın limiti doldu.');
});

it('uses caption override over content caption', function () {
    Queue::fake();

    Http::fakeSequence()
        ->push(['data' => [['quota_usage' => 10, 'config' => ['quota_total' => 100]]]])
        ->push(['id' => 'ig_container_1'])
        ->push(['id' => 'ig_media_1'])
        ->push(['id' => 'ig_media_1', 'permalink' => 'https://www.instagram.com/p/abc123/']);

    $publication = Publication::factory()->scheduled()->create([
        'caption_override' => 'Hesaba özel caption',
    ]);

    publicationAccount($publication);

    (new PublicationPublishingService)->publish($publication);

    // Container oluşturma isteğinde override edilmiş caption gitmeli
    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/media')
            && ! str_contains($request->url(), 'media_publish')
            && ($request['caption'] ?? null) === 'Hesaba özel caption';
    });

    // Content caption'ı değişmemeli
    expect($publication->content->fresh()->caption)->not->toBe('Hesaba özel caption');
});

it('falls back to content caption when no override exists', function () {
    Queue::fake();

    Http::fakeSequence()
        ->push(['data' => [['quota_usage' => 10, 'config' => ['quota_total' => 100]]]])
        ->push(['id' => 'ig_container_1'])
        ->push(['id' => 'ig_media_1'])
        ->push(['id' => 'ig_media_1', 'permalink' => 'https://www.instagram.com/p/abc123/']);

    $content = Content::factory()->create(['caption' => 'Varsayılan content caption']);
    $publication = Publication::factory()->scheduled()->create([
        'content_id' => $content->id,
        'team_id' => $content->team_id,
        'caption_override' => null,
    ]);

    publicationAccount($publication);

    (new PublicationPublishingService)->publish($publication);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/media')
            && ! str_contains($request->url(), 'media_publish')
            && ($request['caption'] ?? null) === 'Varsayılan content caption';
    });
});

it('refuses to publish when no connected account matches', function () {
    Http::fake(['*' => Http::response()]);

    $publication = Publication::factory()->create();

    expect(fn () => (new PublicationPublishingService)->publish($publication))
        ->toThrow(RuntimeException::class);

    expect($publication->fresh())->status->toBe(Publication::STATUS_PUBLISHING);
});
