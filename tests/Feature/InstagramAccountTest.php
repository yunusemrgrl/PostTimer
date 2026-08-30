<?php

use App\Domain\Instagram\Services\InstagramAccountService;
use App\Domain\Instagram\Services\InstagramPublishingService;
use App\Models\InstagramAccount;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();

    $this->team = Team::factory()
        ->hasAttached($this->user, ['role' => TeamMember::ROLE_OWNER], 'users')
        ->create();
});

it('fetches account profile fields from the graph api', function () {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response([
            'username' => 'bronzfonz',
            'account_type' => 'BUSINESS',
            'followers_count' => 1234,
            'media_count' => 42,
        ]),
        '*' => Http::response(),
    ]);

    $service = new InstagramPublishingService(token: 'test-token');

    $account = $service->getAccount('90010177253934');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/90010177253934')
            && str_contains($request->url(), 'fields=');
    });

    expect($account)->toMatchArray([
        'username' => 'bronzfonz',
        'account_type' => 'BUSINESS',
        'followers_count' => 1234,
    ]);
});

it('fetches published media for an account', function () {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response([
            'data' => [
                ['id' => 'ig_media_1', 'media_type' => 'IMAGE'],
            ],
        ]),
        '*' => Http::response(),
    ]);

    $service = new InstagramPublishingService(token: 'test-token');

    $media = $service->getAccountMedia('90010177253934', 10);

    expect($media['data'])->toHaveCount(1);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/90010177253934/media')
            && str_contains($request->url(), 'limit=10');
    });
});

it('syncs account profile data into the database', function () {
    Http::fake([
        'https://graph.instagram.com/*' => Http::response([
            'username' => 'bronzfonz',
            'name' => 'Bronz Fonz',
            'account_type' => 'MEDIA_CREATOR',
            'biography' => 'Selam!',
            'website' => 'https://example.com',
            'followers_count' => 4321,
            'media_count' => 99,
            'profile_picture_url' => 'https://example.com/pp.jpg',
        ]),
        '*' => Http::response(),
    ]);

    $account = InstagramAccount::factory()
        ->for($this->team)
        ->withToken('test-token')
        ->create([
            'ig_user_id' => '90010177253934',
            'api_host' => 'graph.instagram.com',
        ]);

    app(InstagramAccountService::class)->sync($account);

    assertDatabaseHas('instagram_accounts', [
        'id' => $account->id,
        'username' => 'bronzfonz',
        'account_type' => 'MEDIA_CREATOR',
        'followers_count' => 4321,
        'media_count' => 99,
    ]);

    expect($account->fresh()->last_synced_at)->not->toBeNull();
});

it('refuses to sync an account without its own token', function () {
    $account = InstagramAccount::factory()
        ->for($this->team)
        ->withoutToken()
        ->create();

    expect(fn () => app(InstagramAccountService::class)->sync($account))
        ->toThrow(RuntimeException::class);
});

it('stores the team access token encrypted', function () {
    $account = InstagramAccount::factory()->for($this->team)->withToken('secret-token')->create();

    $raw = DB::table('instagram_accounts')->where('id', $account->id)->value('access_token');

    expect($raw)->not->toBe('secret-token')
        ->and($account->fresh()->access_token)->toBe('secret-token');
});
