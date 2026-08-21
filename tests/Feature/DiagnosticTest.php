<?php

use App\Events\PostPublishFailed;
use App\Filament\App\Resources\InstagramPosts\Pages\CreateInstagramPost;
use App\Models\InstagramAccount;
use App\Models\InstagramPost;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\TelegramSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()
        ->hasAttached($this->user, ['role' => TeamMember::ROLE_OWNER], 'users')
        ->create();
});

test('DEBUG session driver is array in testing', function () {
    dump([
        'getenv APP_ENV' => getenv('APP_ENV'),
        '$_ENV APP_ENV' => $_ENV['APP_ENV'] ?? null,
        '$_SERVER APP_ENV' => $_SERVER['APP_ENV'] ?? null,
        'env() APP_ENV' => env('APP_ENV'),
        'config app.env' => config('app.env'),
        'session.driver' => config('session.driver'),
        'queue.default' => config('queue.default'),
        'cache.default' => config('cache.default'),
    ]);
    expect(config('session.driver'))->toBe('array');
});

test('DEBUG publish-scheduled job runs and dispatches PostPublishFailed', function () {
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
            'data' => [['quota_usage' => 10, 'config' => ['quota_total' => 100]]],
        ]),
        'https://graph.instagram.com/*/media' => Http::response(['error' => ['message' => 'Invalid']], 400),
        'api.telegram.org/*' => Http::response(['ok' => true]),
        '*' => Http::response(),
    ]);

    Event::fake([PostPublishFailed::class]);

    try {
        Artisan::call('instagram:publish-scheduled');
    } catch (Throwable $e) {
        dump(['command threw' => $e::class.': '.$e->getMessage()]);
    }

    $post->refresh();
    dump(['post status after' => $post->status]);

    Event::assertDispatched(PostPublishFailed::class);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'api.telegram.org'));
});

test('DEBUG filament tenant persists through livewire create test', function () {
    $this->actingAs($this->user);

    InstagramAccount::factory()
        ->for($this->team)
        ->withToken('account-token')
        ->create(['ig_user_id' => '90010177253934']);

    Filament::setCurrentPanel('app');
    Filament::setTenant($this->team);
    Filament::bootCurrentPanel();

    dump(['tenant before' => Filament::getTenant()?->id]);

    Livewire::test(CreateInstagramPost::class);

    dump(['tenant after' => Filament::getTenant()?->id]);
    dump(['tenant instagramAccounts' => Filament::getTenant()?->instagramAccounts()->pluck('ig_user_id')]);

    expect(true)->toBeTrue();
});
