<?php

use App\Models\Content;
use App\Models\InstagramAccount;
use App\Models\Publication;
use App\Services\PublicationPublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function carouselPublication(): Publication
{
    $content = Content::factory()->carousel()->create();

    $publication = Publication::factory()->create([
        'team_id' => $content->team_id,
        'content_id' => $content->id,
        'status' => Publication::STATUS_PUBLISHING,
    ]);

    InstagramAccount::factory()->create([
        'team_id' => $publication->team_id,
        'ig_user_id' => $publication->ig_user_id,
        'access_token' => 'account-token',
        'api_host' => 'graph.instagram.com',
    ]);

    return $publication;
}

function quotaResponse(): array
{
    return ['data' => [['quota_usage' => 10, 'config' => ['quota_total' => 100]]]];
}

it('checkpoints each carousel child container as it is created', function () {
    Queue::fake();

    Http::fakeSequence()
        ->push(quotaResponse())
        // 1. çocuk
        ->push(['id' => 'child_1'])
        // 2. çocukta hata — checkpoint'in kaydedildiğini doğrulamak için
        ->push(['error' => ['message' => 'boom']], 500);

    $publication = carouselPublication();

    expect(fn () => (new PublicationPublishingService)->publish($publication))
        ->toThrow(RequestException::class);

    expect($publication->fresh()->carousel_child_container_ids)->toBe(['child_1']);
});

it('reuses checkpointed child containers on retry', function () {
    Queue::fake();

    // İlk denemede 2. çocukta hata; retry'ta yalnızca 2. çocuk + karusel oluşturulur.
    Http::fakeSequence()
        ->push(quotaResponse())
        ->push(['id' => 'child_1'])
        ->push(['error' => ['message' => 'boom']], 500)
        // Retry: kota + 2. çocuk + karusel container + publish + getMedia
        ->push(quotaResponse())
        ->push(['id' => 'child_2'])
        ->push(['id' => 'carousel_1'])
        ->push(['id' => 'ig_media_1'])
        ->push(['id' => 'ig_media_1', 'permalink' => 'https://www.instagram.com/p/xyz/', 'timestamp' => '2026-01-01T10:00:00+0000']);

    $publication = carouselPublication();
    $service = new PublicationPublishingService;

    expect(fn () => $service->publish($publication))->toThrow(RequestException::class);
    expect($publication->fresh()->carousel_child_container_ids)->toBe(['child_1']);

    $published = $service->publish($publication->fresh());

    expect($published)
        ->status->toBe(Publication::STATUS_PUBLISHED)
        ->media_id->toBe('ig_media_1')
        // Checkpoint başarıda temizlenir.
        ->carousel_child_container_ids->toBeNull();

    // Çocuk container oluşturma istekleri ('children' anahtarı YOK,
    // /media_publish hariç): child_1 (ilk deneme) + child_2 (hatalı) +
    // child_2 (retry) = 3. child_1 retry'ta yeniden oluşturulSAYDI bu
    // sayı 4 olurdu.
    $itemCreations = collect(Http::recorded())->filter(
        fn (array $pair): bool => ! str_contains($pair[0]->url(), 'media_publish')
            && str_contains($pair[0]->url(), '/media')
            && ! isset($pair[0]['children']),
    );

    expect($itemCreations)->toHaveCount(3);

    // Retry'taki tek çocuk oluşturma isteği child_1'in payload'ıyla aynıysa
    // checkpoint işe yaramamış demektir; ilk iki istek farklı çocuklara
    // ait olmalı (farklı image_url).
    $itemUrls = $itemCreations->map(fn (array $pair): ?string => $pair[0]['image_url'] ?? null)->values();
    expect($itemUrls[0])->not->toBe($itemUrls[2]);
});

it('clears the carousel checkpoint after successful publish', function () {
    Queue::fake();

    Http::fakeSequence()
        ->push(quotaResponse())
        ->push(['id' => 'child_1'])
        ->push(['id' => 'child_2'])
        ->push(['id' => 'carousel_1'])
        ->push(['id' => 'ig_media_1'])
        ->push(['id' => 'ig_media_1', 'permalink' => 'https://www.instagram.com/p/abc/', 'timestamp' => '2026-01-01T10:00:00+0000']);

    $publication = carouselPublication();

    $published = (new PublicationPublishingService)->publish($publication);

    expect($published)
        ->status->toBe(Publication::STATUS_PUBLISHED)
        ->container_id->toBe('carousel_1')
        ->carousel_child_container_ids->toBeNull();
});
