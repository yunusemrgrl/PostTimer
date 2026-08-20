<?php

use App\Models\InstagramAccount;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\TelegramSetting;
use App\Models\User;
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

function expiryWarningCount(): int
{
    return collect(Http::recorded())
        ->filter(fn (array $pair) => str_contains($pair[0]['text'] ?? '', 'Jeton Süresi Doluyor'))
        ->count();
}

it('notifies the team telegram when a token expires within 7 days', function () {
    TelegramSetting::factory()->for($this->team)->create(['chat_id' => 123456789]);

    $account = InstagramAccount::factory()->for($this->team)->create([
        'username' => 'yunusemre',
        'token_expires_at' => now()->addDays(3),
    ]);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true]),
        '*' => Http::response(),
    ]);

    Artisan::call('instagram:notify-expiring-tokens');

    expect(expiryWarningCount())->toBe(1);
    expect($account->fresh()->token_expiry_notified_at)->not->toBeNull();
});

it('does not send the expiry warning repeatedly for the same window', function () {
    TelegramSetting::factory()->for($this->team)->create(['chat_id' => 123456789]);

    InstagramAccount::factory()->for($this->team)->create([
        'token_expires_at' => now()->addDays(3),
    ]);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true]),
        '*' => Http::response(),
    ]);

    Artisan::call('instagram:notify-expiring-tokens');
    Artisan::call('instagram:notify-expiring-tokens');

    expect(expiryWarningCount())->toBe(1);
});

it('does not notify when the token expires later than 7 days', function () {
    TelegramSetting::factory()->for($this->team)->create(['chat_id' => 123456789]);

    InstagramAccount::factory()->for($this->team)->create([
        'token_expires_at' => now()->addDays(30),
    ]);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true]),
        '*' => Http::response(),
    ]);

    Artisan::call('instagram:notify-expiring-tokens');

    expect(expiryWarningCount())->toBe(0);
});

it('does not notify team A when only team B has an expiring token', function () {
    $otherTeam = Team::factory()->create();

    TelegramSetting::factory()->for($this->team)->create(['chat_id' => 111111]);

    InstagramAccount::factory()->for($otherTeam)->create([
        'username' => 'ahmet',
        'token_expires_at' => now()->addDays(3),
    ]);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true]),
        '*' => Http::response(),
    ]);

    Artisan::call('instagram:notify-expiring-tokens');

    expect(expiryWarningCount())->toBe(0);
});
