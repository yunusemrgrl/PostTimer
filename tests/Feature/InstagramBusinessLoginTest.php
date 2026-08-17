<?php

use App\Models\InstagramAccount;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('instagram.client_id', '990602627938098');
    config()->set('instagram.client_secret', 'a1b2C3D4');

    $this->user = User::factory()->create();

    $this->team = Team::factory()
        ->hasAttached($this->user, ['role' => TeamMember::ROLE_OWNER], 'users')
        ->create();

    $this->actingAs($this->user);
});

it('redirects to the instagram authorization window with state', function () {
    $response = $this->get(route('instagram.connect', ['tenant' => $this->team->slug]));

    $response->assertRedirect();

    $target = $response->headers->get('Location');

    expect($target)
        ->toStartWith('https://www.instagram.com/oauth/authorize')
        ->toContain('client_id=990602627938098')
        ->toContain('response_type=code')
        ->toContain('instagram_business_basic')
        ->toContain('instagram_business_content_publish');

    expect(session('instagram_oauth_state'))->not->toBeEmpty();
});

it('exchanges the authorization code and connects the account', function () {
    Http::fake([
        'https://api.instagram.com/oauth/access_token' => Http::response([
            'data' => [
                [
                    'access_token' => 'short-lived-token',
                    'user_id' => '90010177253934',
                    'permissions' => 'instagram_business_basic,instagram_business_content_publish',
                ],
            ],
        ]),
        'https://graph.instagram.com/access_token*' => Http::response([
            'access_token' => 'long-lived-token',
            'token_type' => 'bearer',
            'expires_in' => 5184000,
        ]),
        'https://graph.instagram.com/*' => Http::response([
            'username' => 'bronzfonz',
            'account_type' => 'BUSINESS',
            'followers_count' => 1500,
            'media_count' => 30,
        ]),
        '*' => Http::response(),
    ]);

    $state = Str::random(40);
    session(['instagram_oauth_state' => $state, 'instagram_oauth_team' => $this->team->id]);

    $response = $this->get(route('instagram.callback', [
        'code' => 'AQBx-hBsH3',
        'state' => $state,
    ]));

    $response->assertRedirect();

    assertDatabaseHas('instagram_accounts', [
        'team_id' => $this->team->id,
        'ig_user_id' => '90010177253934',
        'api_host' => 'graph.instagram.com',
        'username' => 'bronzfonz',
    ]);

    $account = InstagramAccount::query()->first();

    expect($account)
        ->access_token->toBe('long-lived-token')
        ->token_expires_at->not->toBeNull()
        ->token_expires_at->greaterThan(now()->addDays(59));
});

it('rejects the callback when the state does not match', function () {
    session(['instagram_oauth_state' => 'expected-state', 'instagram_oauth_team' => $this->team->id]);

    $this->get(route('instagram.callback', [
        'code' => 'AQBx-hBsH3',
        'state' => 'forged-state',
    ]))->assertRedirect();

    expect(InstagramAccount::query()->count())->toBe(0);

    Http::assertNothingSent();
});

it('handles a denied authorization gracefully', function () {
    session(['instagram_oauth_state' => 'state', 'instagram_oauth_team' => $this->team->id]);

    $this->get(route('instagram.callback', [
        'error' => 'access_denied',
        'error_reason' => 'user_denied',
    ]))->assertRedirect();

    expect(InstagramAccount::query()->count())->toBe(0);
});

it('blocks connecting an instagram account to a foreign team', function () {
    $foreignTeam = Team::factory()->create();

    $this->get(route('instagram.connect', ['tenant' => $foreignTeam->slug]))
        ->assertForbidden();
});

it('refreshes an expiring long lived token', function () {
    $account = InstagramAccount::factory()
        ->for($this->team)
        ->withToken('old-long-lived-token')
        ->create([
            'token_expires_at' => now()->addDays(3),
        ]);

    Http::fake([
        'https://graph.instagram.com/refresh_access_token*' => Http::response([
            'access_token' => 'new-long-lived-token',
            'token_type' => 'bearer',
            'expires_in' => 5184000,
        ]),
        '*' => Http::response(),
    ]);

    $this->artisan('instagram:refresh-tokens')->assertExitCode(0);

    expect($account->fresh())
        ->access_token->toBe('new-long-lived-token')
        ->token_expires_at->greaterThan(now()->addDays(59));
});
