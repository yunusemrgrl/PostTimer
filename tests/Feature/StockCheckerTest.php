<?php

use App\Events\PostFlagged;
use App\Models\InstagramPost;
use App\Models\Product;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\AmazonStockChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();

    $this->team = Team::factory()
        ->hasAttached($this->user, ['role' => TeamMember::ROLE_OWNER], 'users')
        ->create();
});

it('detects out of stock products', function () {
    Http::fake([
        'amazon.com.tr/*' => Http::response(
            '<html><body>Şu anda stokta yok</body></html>'
        ),
        '*' => Http::response(),
    ]);

    $product = Product::factory()->for($this->team)->create();

    $result = (new AmazonStockChecker)->check($product);

    expect($result)
        ->status->toBe('out_of_stock')
        ->message->toContain('stokta yok');
});

it('detects 404 page not found', function () {
    Http::fake([
        'amazon.com.tr/*' => Http::response(
            '<html><head><title>404 - Sayfa Bulunamadı</title></head></html>'
        ),
        '*' => Http::response(),
    ]);

    $product = Product::factory()->for($this->team)->create();

    $result = (new AmazonStockChecker)->check($product);

    expect($result)
        ->status->toBe('not_found')
        ->message->toContain('bulunamadı');
});

it('returns in_stock for available products', function () {
    Http::fake([
        'amazon.com.tr/*' => Http::response(
            '<html><body>Stokta var — 2-3 iş günü</body></html>'
        ),
        '*' => Http::response(),
    ]);

    $product = Product::factory()->for($this->team)->create();

    $result = (new AmazonStockChecker)->check($product);

    expect($result)->status->toBe('in_stock');
});

it('handles network errors gracefully', function () {
    Http::fake([
        'amazon.com.tr/*' => Http::failedConnection(),
        '*' => Http::response(),
    ]);

    $product = Product::factory()->for($this->team)->create();

    $result = (new AmazonStockChecker)->check($product);

    expect($result)
        ->status->toBe('error')
        ->message->toContain('erişilemedi');
});

it('flags scheduled posts with out-of-stock products', function () {
    Event::fake([PostFlagged::class]);

    Http::fake([
        'amazon.com.tr/*' => Http::response(
            '<html><body>Stok tükendi</body></html>'
        ),
        '*' => Http::response(),
    ]);

    $product = Product::factory()->for($this->team)->create();

    $post = InstagramPost::factory()->for($this->team)->create([
        'product_id' => $product->id,
        'status' => InstagramPost::STATUS_SCHEDULED,
        'scheduled_at' => now()->addMinutes(10),
    ]);

    Artisan::call('instagram:check-stock');

    assertDatabaseHas('instagram_posts', [
        'id' => $post->id,
        'status' => InstagramPost::STATUS_FLAGGED,
    ]);

    expect($post->fresh()->error_message)->toContain('stokta yok');

    Event::assertDispatched(PostFlagged::class);
});

it('does not flag posts with in-stock products', function () {
    Http::fake([
        'amazon.com.tr/*' => Http::response(
            '<html><body>Stokta var</body></html>'
        ),
        '*' => Http::response(),
    ]);

    $product = Product::factory()->for($this->team)->create();

    $post = InstagramPost::factory()->for($this->team)->create([
        'product_id' => $product->id,
        'status' => InstagramPost::STATUS_SCHEDULED,
        'scheduled_at' => now()->addMinutes(10),
    ]);

    Artisan::call('instagram:check-stock');

    expect($post->fresh()->status)->toBe(InstagramPost::STATUS_SCHEDULED);
});

it('ignores posts more than 20 minutes away', function () {
    Http::fake(['*' => Http::response()]);

    $product = Product::factory()->for($this->team)->create();

    $post = InstagramPost::factory()->for($this->team)->create([
        'product_id' => $product->id,
        'status' => InstagramPost::STATUS_SCHEDULED,
        'scheduled_at' => now()->addHours(2),
    ]);

    Artisan::call('instagram:check-stock');

    expect($post->fresh()->status)->toBe(InstagramPost::STATUS_SCHEDULED);

    Http::assertNothingSent();
});

it('ignores posts without a product link', function () {
    Http::fake(['*' => Http::response()]);

    $post = InstagramPost::factory()->for($this->team)->create([
        'product_id' => null,
        'status' => InstagramPost::STATUS_SCHEDULED,
        'scheduled_at' => now()->addMinutes(10),
    ]);

    Artisan::call('instagram:check-stock');

    expect($post->fresh()->status)->toBe(InstagramPost::STATUS_SCHEDULED);

    Http::assertNothingSent();
});
