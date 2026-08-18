<?php

use App\Models\InstagramAccount;
use App\Models\InstagramPost;
use App\Models\Product;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\TelegramSetting;
use App\Models\User;
use App\Services\TelegramBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();

    $this->team = Team::factory()
        ->hasAttached($this->user, ['role' => TeamMember::ROLE_OWNER], 'users')
        ->create();
});

it('sends a telegram message when a post is flagged for stock issues', function () {
    TelegramSetting::factory()->for($this->team)->create();

    $product = Product::factory()->for($this->team)->create();

    $post = InstagramPost::factory()->for($this->team)->create([
        'product_id' => $product->id,
        'status' => InstagramPost::STATUS_SCHEDULED,
        'scheduled_at' => now()->addMinutes(10),
        'caption' => 'Harika ürün!',
    ]);

    Http::fake([
        'amazon.com.tr/*' => Http::response('<html><body>Stok tükendi</body></html>'),
        'api.telegram.org/*' => Http::response(['ok' => true]),
        '*' => Http::response(),
    ]);

    Artisan::call('instagram:check-stock');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.telegram.org/bot')
            && str_contains($request['text'] ?? '', 'Stok Uyarısı')
            && str_contains($request['text'] ?? '', 'Harika ürün!');
    });

    expect($post->fresh()->status)->toBe(InstagramPost::STATUS_FLAGGED);
});

it('sends a telegram message when publishing fails', function () {
    TelegramSetting::factory()->for($this->team)->create();

    $account = InstagramAccount::factory()
        ->for($this->team)
        ->withToken('test-token')
        ->create(['api_host' => 'graph.instagram.com']);

    $post = InstagramPost::factory()->for($this->team)->create([
        'ig_user_id' => $account->ig_user_id,
        'status' => InstagramPost::STATUS_SCHEDULED,
        'scheduled_at' => now()->subMinute(),
        'caption' => 'Test gönderisi',
    ]);

    Http::fake([
        'https://graph.instagram.com/*content_publishing_limit*' => Http::response([
            'data' => [['quota_total' => 100, 'quota_used' => 10]],
        ]),
        'https://graph.instagram.com/*/media' => Http::response(['error' => ['message' => 'Invalid']], 400),
        'api.telegram.org/*' => Http::response(['ok' => true]),
        '*' => Http::response(),
    ]);

    // sync queue'da job exception yayılır — bekleniyor
    try {
        Artisan::call('instagram:publish-scheduled');
    } catch (Throwable) {
        // Yayın başarısız oldu — beklenen davranış
    }

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.telegram.org/bot')
            && str_contains($request['text'] ?? '', 'Yayın Başarısız');
    });
});

it('does not send telegram when settings are not configured', function () {
    $product = Product::factory()->for($this->team)->create();

    $post = InstagramPost::factory()->for($this->team)->create([
        'product_id' => $product->id,
        'status' => InstagramPost::STATUS_SCHEDULED,
        'scheduled_at' => now()->addMinutes(10),
    ]);

    Http::fake([
        'amazon.com.tr/*' => Http::response('<html><body>Stok tükendi</body></html>'),
        '*' => Http::response(),
    ]);

    Artisan::call('instagram:check-stock');

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'api.telegram.org');
    });
});

it('parses telegram webhook update correctly', function () {
    $service = new TelegramBotService;

    $result = $service->parseUpdate([
        'message' => [
            'chat' => ['id' => 123456789],
            'text' => '/start ABC123',
        ],
    ]);

    expect($result)
        ->chat_id->toBe(123456789)
        ->text->toBe('/start ABC123');
});

it('verifies telegram chat id via webhook', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true]),
        '*' => Http::response(),
    ]);

    $setting = TelegramSetting::factory()
        ->for($this->team)
        ->unverified()
        ->create(['verification_code' => 'SECRET123']);

    $response = $this->postJson(
        route('telegram.webhook', ['token' => $setting->webhook_secret]),
        [
            'message' => [
                'chat' => ['id' => 987654321],
                'text' => '/start SECRET123',
            ],
        ]
    );

    $response->assertOk();

    expect($setting->fresh())
        ->chat_id->toBe(987654321)
        ->is_verified->toBeTrue()
        ->verification_code->toBeNull();
});
