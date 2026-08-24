<?php

use App\Events\PublicationFlagged;
use App\Models\InstagramAccount;
use App\Models\Publication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function upcomingPublication(array $attributes = []): Publication
{
    return Publication::factory()->create([
        ...$attributes,
        'status' => Publication::STATUS_SCHEDULED,
        'scheduled_at' => now()->addMinutes(30),
    ]);
}

beforeEach(function () {
    Event::fake([PublicationFlagged::class]);
    Cache::flush();
});

it('flags publications and warns when the account token is dead', function () {
    Http::fake([
        '*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 400),
    ]);

    $account = InstagramAccount::factory()->create();
    $publication = upcomingPublication(['instagram_account_id' => $account->id]);

    $this->artisan('publications:check-connections')->assertSuccessful();

    expect($publication->fresh())
        ->status->toBe(Publication::STATUS_FLAGGED)
        ->error_message->toContain('sağlık kontrolünde erişilemedi');

    Event::assertDispatchedTimes(PublicationFlagged::class, 1);
});

it('leaves publications untouched when the account is healthy', function () {
    Http::fake([
        '*' => Http::response(['username' => 'healthyuser']),
    ]);

    $account = InstagramAccount::factory()->create();
    $publication = upcomingPublication(['instagram_account_id' => $account->id]);

    $this->artisan('publications:check-connections')->assertSuccessful();

    expect($publication->fresh())->status->toBe(Publication::STATUS_SCHEDULED);
    Event::assertNotDispatched(PublicationFlagged::class);
});

it('does not warn twice for the same account within the cooldown window', function () {
    Http::fake([
        '*' => Http::response(['error' => ['message' => 'dead token']], 400),
    ]);

    $account = InstagramAccount::factory()->create();
    upcomingPublication(['instagram_account_id' => $account->id]);

    $this->artisan('publications:check-connections')->assertSuccessful();

    // Cooldown set edildi — ikinci koşu hiç Graph çağrısı yapmaz.
    $this->artisan('publications:check-connections')->assertSuccessful();

    expect(Cache::has("ig-conn-warning-{$account->id}"))->toBeTrue();
    Http::assertSentCount(1);
    Event::assertDispatchedTimes(PublicationFlagged::class, 1);
});

it('checks each account only once across its publications', function () {
    Http::fake([
        '*' => Http::response(['username' => 'someuser']),
    ]);

    $account = InstagramAccount::factory()->create();
    upcomingPublication(['instagram_account_id' => $account->id]);
    upcomingPublication(['instagram_account_id' => $account->id]);

    $this->artisan('publications:check-connections')->assertSuccessful();

    Http::assertSentCount(1);
});

it('does nothing when there are no upcoming scheduled publications', function () {
    Http::fake(['*' => Http::response()]);

    Publication::factory()->scheduled()->create(); // 2 saat sonrası — pencere dışı

    $this->artisan('publications:check-connections')->assertSuccessful();

    Http::assertNothingSent();
});
