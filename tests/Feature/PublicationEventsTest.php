<?php

use App\Domain\Publishing\Services\PublicationPublishingService;
use App\Events\PublicationFlagged;
use App\Events\PublicationPublished;
use App\Events\PublicationPublishFailed;
use App\Jobs\PublishScheduledPublication;
use App\Models\Content;
use App\Models\InstagramAccount;
use App\Models\Product;
use App\Models\Publication;
use App\Models\TelegramSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function publicationEventAccount(Publication $publication): void
{
    InstagramAccount::factory()->create([
        'team_id' => $publication->team_id,
        'ig_user_id' => $publication->ig_user_id,
        'access_token' => 'account-token',
        'api_host' => 'graph.instagram.com',
    ]);
}

it('dispatches PublicationPublished on successful publish', function () {
    Event::fake([PublicationPublished::class]);

    Http::fakeSequence()
        ->push(['data' => [['quota_usage' => 10, 'config' => ['quota_total' => 100]]]])
        ->push(['id' => 'ig_container_1'])
        ->push(['id' => 'ig_media_1'])
        ->push(['id' => 'ig_media_1']);

    $publication = Publication::factory()->scheduled()->create();
    publicationEventAccount($publication);

    (new PublicationPublishingService)->publish($publication);

    Event::assertDispatched(PublicationPublished::class, 1);
});

it('does not dispatch PublicationPublished when republish is skipped by media id guard', function () {
    Event::fake([PublicationPublished::class]);

    Http::fake(['*' => Http::response()]);

    $publication = Publication::factory()->published()->create([
        'media_id' => 'ig_media_existing',
    ]);

    publicationEventAccount($publication);

    (new PublicationPublishingService)->publish($publication);

    Http::assertNothingSent();
    Event::assertNotDispatched(PublicationPublished::class);
});

it('does not dispatch PublicationPublishFailed during retryable failures', function () {
    Event::fake([PublicationPublishFailed::class]);

    Http::fakeSequence()
        // Kota dolu → RuntimeException; retry edilebilir akışta event YOK
        ->push(['data' => [['quota_usage' => 100, 'config' => ['quota_total' => 100]]]]);

    $publication = Publication::factory()->scheduled()->create();
    publicationEventAccount($publication);

    expect(fn () => (new PublishScheduledPublication($publication))->handle(app(PublicationPublishingService::class)))
        ->toThrow(RuntimeException::class);

    Event::assertNotDispatched(PublicationPublishFailed::class);
});

it('dispatches PublicationPublishFailed only once on permanent failure', function () {
    Event::fake([PublicationPublishFailed::class]);

    $publication = Publication::factory()->scheduled()->create();

    $job = new PublishScheduledPublication($publication);
    $job->failed(new RuntimeException('Kalıcı hata'));
    // Çıkış senaryolarına karşı çift çağrı — event tekrar gönderilmemeli
    $job->failed(new RuntimeException('Kalıcı hata'));

    Event::assertDispatched(PublicationPublishFailed::class, 1);

    expect($publication->fresh())
        ->status->toBe(Publication::STATUS_FAILED)
        ->error_message->toBe('Kalıcı hata');
});

it('dispatches PublicationFlagged when stock check flags a publication', function () {
    Event::fake([PublicationFlagged::class]);

    Http::fake([
        'amazon.com.tr/*' => Http::response('<html><body>Bu ürün stok tükendi</body></html>'),
    ]);

    $product = Product::factory()->create();
    $content = Content::factory()->create(['team_id' => $product->team_id, 'product_id' => $product->id]);
    $publication = Publication::factory()->create([
        'team_id' => $product->team_id,
        'content_id' => $content->id,
        'status' => Publication::STATUS_SCHEDULED,
        'scheduled_at' => now()->addMinutes(10),
    ]);

    $this->artisan('publications:check-stock')->assertSuccessful();

    Event::assertDispatched(function (PublicationFlagged $event) use ($publication) {
        return $event->publication->is($publication)
            && str_contains($event->reason, 'stok');
    }, 1);
});

it('sends a telegram notification with publication details when published', function () {
    // Next publication publish, bağımsız biraz B1 insights sync job'ını da
    // dispatch eder; test ortamda pending dispatch'ları sync edirip Http
    // fakeSequence'e ekstra request yaramamalı. Bu test Telegram yalnızca ölçir.
    Queue::fake();

    config(['services.telegram.bot_token' => 'test-token:abc']);

    $publication = Publication::factory()->scheduled()->create();

    TelegramSetting::factory()->for($publication->team)->create();

    publicationEventAccount($publication);

    Http::fakeSequence()
        ->push(['data' => [['quota_usage' => 10, 'config' => ['quota_total' => 100]]]])
        ->push(['id' => 'ig_container_1'])
        ->push(['id' => 'ig_media_1'])
        ->push(['id' => 'ig_media_1'])
        ->push(['ok' => true]); // Telegram

    (new PublicationPublishingService)->publish($publication);

    Http::assertSent(function ($request) use ($publication) {
        return str_contains($request->url(), 'api.telegram.org/bot')
            && str_contains($request['text'] ?? '', 'Gönderi Yayınlandı')
            && str_contains($request['text'] ?? '', (string) $publication->effectiveCaption())
            && str_contains($request['text'] ?? '', '@'.$publication->instagramAccount->username);
    });
});
