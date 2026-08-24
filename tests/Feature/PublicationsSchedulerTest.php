<?php

use App\Jobs\PublishScheduledPublication;
use App\Models\Content;
use App\Models\Product;
use App\Models\Publication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

function duePublication(array $attributes = []): Publication
{
    return Publication::factory()->create([
        ...$attributes,
        'status' => Publication::STATUS_SCHEDULED,
        'scheduled_at' => now()->subMinutes(2),
    ]);
}

it('dispatches due scheduled publications', function () {
    $publication = duePublication();

    $this->artisan('publications:publish-scheduled')->assertSuccessful();

    Queue::assertPushed(PublishScheduledPublication::class, 1);
    Queue::assertPushed(function (PublishScheduledPublication $job) use ($publication) {
        return $job->publication->is($publication);
    });
});

it('does not dispatch publications scheduled in the future', function () {
    Publication::factory()->create([
        'status' => Publication::STATUS_SCHEDULED,
        'scheduled_at' => now()->addHours(2),
    ]);

    $this->artisan('publications:publish-scheduled')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('does not dispatch publications that are not scheduled', function () {
    Publication::factory()->published()->create();
    Publication::factory()->failed()->create();
    Publication::factory()->flagged()->create();
    Publication::factory()->cancelled()->create();
    Publication::factory()->create(); // draft

    $this->artisan('publications:publish-scheduled')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('dispatches multiple due publications', function () {
    duePublication();
    duePublication();
    duePublication();

    $this->artisan('publications:publish-scheduled')->assertSuccessful();

    Queue::assertPushed(PublishScheduledPublication::class, 3);
});

it('handles chunked scanning without losing publications', function () {
    // Chunk boyutu 100 — sınırı aşan sayıda yayın üretilir.
    duePublication();
    foreach (range(1, 104) as $i) {
        duePublication(['ig_user_id' => (string) (90000000000000 + $i)]);
    }

    $this->artisan('publications:publish-scheduled')->assertSuccessful();

    Queue::assertPushed(PublishScheduledPublication::class, 105);
});

it('does not flag a publication when the product is in stock', function () {
    Http::fake([
        'amazon.com.tr/*' => Http::response('<html><body>Stokta mevcut - Sepete Ekle</span></body></html>'),
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

    expect($publication->fresh())
        ->status->toBe(Publication::STATUS_SCHEDULED)
        ->error_message->toBeNull();
});

it('flags the publication when the product is out of stock', function () {
    Http::fake([
        'amazon.com.tr/*' => Http::response('<html><body>Üzgünüz! Bu ürün stok tükendi.</body></html>'),
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

    expect($publication->fresh())
        ->status->toBe(Publication::STATUS_FLAGGED)
        ->error_message->toContain('stok');
});

it('skips publications without a product without failing', function () {
    $content = Content::factory()->withoutProduct()->create();
    $publication = Publication::factory()->create([
        'team_id' => $content->team_id,
        'content_id' => $content->id,
        'status' => Publication::STATUS_SCHEDULED,
        'scheduled_at' => now()->addMinutes(10),
    ]);

    $this->artisan('publications:check-stock')->assertSuccessful();

    expect($publication->fresh())->status->toBe(Publication::STATUS_SCHEDULED);
});

it('checks each product only once across its publications', function () {
    Http::fake([
        'amazon.com.tr/*' => Http::response('<html><body>Sepete Ekle</body></html>'),
    ]);

    $product = Product::factory()->create();
    $content = Content::factory()->create(['team_id' => $product->team_id, 'product_id' => $product->id]);

    // Aynı ürünü kullanan iki farklı yayın.
    Publication::factory()->count(2)->create([
        'team_id' => $product->team_id,
        'content_id' => $content->id,
        'status' => Publication::STATUS_SCHEDULED,
        'scheduled_at' => now()->addMinutes(10),
    ]);

    $this->artisan('publications:check-stock')->assertSuccessful();

    // Aynı ürün için yalnızca tek Amazon isteği yapılmış olmalı.
    Http::assertSentCount(1);
});

it('excludes flagged publications from the publish scheduler', function () {
    Queue::fake();

    Http::fake([
        'amazon.com.tr/*' => Http::response('<html><body>stokta yok</body></html>'),
    ]);

    $product = Product::factory()->create();
    $content = Content::factory()->create(['team_id' => $product->team_id, 'product_id' => $product->id]);
    $publication = Publication::factory()->create([
        'team_id' => $product->team_id,
        'content_id' => $content->id,
        'status' => Publication::STATUS_SCHEDULED,
        'scheduled_at' => now()->addMinutes(10), // JIT kontrol penceresinde
    ]);

    $this->artisan('publications:check-stock')->assertSuccessful();

    expect($publication->fresh()->status)->toBe(Publication::STATUS_FLAGGED);

    $this->artisan('publications:publish-scheduled')->assertSuccessful();

    Queue::assertNothingPushed();
});
