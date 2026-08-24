<?php

use App\Jobs\PublishScheduledPublication;
use App\Models\Content;
use App\Models\Product;
use App\Models\Publication;
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
    config(['services.telegram.bot_token' => 'test-token:abc']);

    $this->user = User::factory()->create();

    $this->team = Team::factory()
        ->hasAttached($this->user, ['role' => TeamMember::ROLE_OWNER], 'users')
        ->create();
});

it('sends a telegram message when a publication is flagged for stock issues', function () {
    TelegramSetting::factory()->for($this->team)->create();

    $product = Product::factory()->for($this->team)->create();
    $content = Content::factory()->create(['team_id' => $this->team->id, 'product_id' => $product->id, 'caption' => 'Harika ürün!']);
    Publication::factory()->create([
        'team_id' => $this->team->id,
        'content_id' => $content->id,
        'status' => Publication::STATUS_SCHEDULED,
        'scheduled_at' => now()->addMinutes(10),
    ]);

    Http::fake([
        'amazon.com.tr/*' => Http::response('<html><body>Stok tükendi</body></html>'),
        'api.telegram.org/*' => Http::response(['ok' => true]),
        '*' => Http::response(),
    ]);

    Artisan::call('publications:check-stock');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.telegram.org/bot')
            && str_contains($request['text'] ?? '', 'Stok Uyarısı')
            && str_contains($request['text'] ?? '', 'Harika ürün!');
    });
});

it('sends a telegram message when publishing finally fails after retries', function () {
    TelegramSetting::factory()->for($this->team)->create();

    $content = Content::factory()->create(['team_id' => $this->team->id]);
    $publication = Publication::factory()->create([
        'team_id' => $this->team->id,
        'content_id' => $content->id,
        'status' => Publication::STATUS_SCHEDULED,
        'scheduled_at' => now()->addHours(2),
    ]);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true]),
        '*' => Http::response(),
    ]);

    // Retry'lar tamamen tükenince job'ın failed()'ı PublicationPublishFailed'ı
    // tek kez fırlatır → listener → Telegram "Yayın Başarısız" uyarısı.
    (new PublishScheduledPublication($publication))->failed(new RuntimeException('Kalıcı hata'));

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.telegram.org/bot')
            && str_contains($request['text'] ?? '', 'Yayın Başarısız');
    });

    expect($publication->fresh()->status)->toBe(Publication::STATUS_FAILED);
});

it('does not send telegram when settings are not configured', function () {
    $product = Product::factory()->for($this->team)->create();
    $content = Content::factory()->create(['team_id' => $this->team->id, 'product_id' => $product->id]);
    Publication::factory()->create([
        'team_id' => $this->team->id,
        'content_id' => $content->id,
        'status' => Publication::STATUS_SCHEDULED,
        'scheduled_at' => now()->addMinutes(10),
    ]);

    Http::fake([
        'amazon.com.tr/*' => Http::response('<html><body>Stok tükendi</body></html>'),
        '*' => Http::response(),
    ]);

    Artisan::call('publications:check-stock');

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

it('uses the env default bot token when no token is passed', function () {
    config(['services.telegram.bot_token' => '123456:ABCdefault']);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true]),
        '*' => Http::response(),
    ]);

    $service = new TelegramBotService;

    $service->sendMessage(null, 111222333, 'Merhaba PostTimer!');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.telegram.org/bot123456:ABCdefault/sendMessage')
            && $request['chat_id'] === 111222333
            && $request['text'] === 'Merhaba PostTimer!';
    });
});

it('throws when no token is available at all', function () {
    config(['services.telegram.bot_token' => null]);

    $service = new TelegramBotService;

    $service->sendMessage(null, 111222333, 'test');
})->throws(RuntimeException::class);

it('registers a webhook url with setWebhook', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'description' => 'Webhook was set']),
        '*' => Http::response(),
    ]);

    $service = new TelegramBotService;

    $result = $service->setWebhook('123456:ABC', 'https://example.com/telegram/webhook/secret');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.telegram.org/bot123456:ABC/setWebhook')
            && $request['url'] === 'https://example.com/telegram/webhook/secret';
    });

    expect($result)->toMatchArray(['ok' => true]);
});

it('throws when setting a webhook without a token', function () {
    config(['services.telegram.bot_token' => null]);

    $service = new TelegramBotService;

    $service->setWebhook(null, 'https://example.com/telegram/webhook/secret');
})->throws(RuntimeException::class);

it('fetches the current webhook info', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['url' => 'https://example.com/hook']]),
        '*' => Http::response(),
    ]);

    $service = new TelegramBotService;

    $result = $service->getWebhookInfo('123456:ABC');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.telegram.org/bot123456:ABC/getWebhookInfo');
    });

    expect($result['result']['url'])->toBe('https://example.com/hook');
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
        route('telegram.webhook'),
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

it('does not verify when the /start code does not match', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true]),
        '*' => Http::response(),
    ]);

    $setting = TelegramSetting::factory()
        ->for($this->team)
        ->unverified()
        ->create(['verification_code' => 'SECRET123']);

    $this->postJson(route('telegram.webhook'), [
        'message' => [
            'chat' => ['id' => 111222333],
            'text' => '/start WRONGCODE',
        ],
    ])->assertOk();

    expect($setting->fresh())
        ->chat_id->toBeNull()
        ->is_verified->toBeFalse();
});
