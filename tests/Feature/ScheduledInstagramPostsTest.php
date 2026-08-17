<?php

use App\Filament\App\Resources\InstagramPosts\Pages\CreateInstagramPost;
use App\Filament\App\Resources\InstagramPosts\Pages\ListInstagramPosts;
use App\Models\InstagramAccount;
use App\Models\InstagramPost;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();

    $this->team = Team::factory()
        ->hasAttached($this->user, ['role' => TeamMember::ROLE_OWNER], 'users')
        ->create();

    $this->actingAs($this->user);
});

function bootTenantPanelFor(Team $team): void
{
    Filament::setCurrentPanel('app');
    Filament::setTenant($team);
    Filament::bootCurrentPanel();
}

/**
 * Takımın token'lı hesabını oluşturur (publish akışı bunu gerektirir).
 */
function connectInstagramAccount(Team $team, string $igUserId = '90010177253934'): InstagramAccount
{
    return InstagramAccount::factory()
        ->for($team)
        ->withToken('account-token')
        ->create([
            'ig_user_id' => $igUserId,
            'api_host' => 'graph.instagram.com',
        ]);
}

it('schedules a post when a future date is chosen in the form', function () {
    bootTenantPanelFor($this->team);

    Livewire::test(CreateInstagramPost::class)
        ->fillForm([
            'ig_user_id' => '90010177253934',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/images/bronz-fonz.jpg',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $post = InstagramPost::query()->first();

    expect($post)
        ->status->toBe(InstagramPost::STATUS_SCHEDULED)
        ->scheduled_at->not->toBeNull();

    Http::assertNothingSent();
});

it('keeps a post as draft when no date is chosen', function () {
    bootTenantPanelFor($this->team);

    Livewire::test(CreateInstagramPost::class)
        ->fillForm([
            'ig_user_id' => '90010177253934',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/images/bronz-fonz.jpg',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(InstagramPost::query()->first())
        ->status->toBe(InstagramPost::STATUS_DRAFT)
        ->scheduled_at->toBeNull();
});

it('publishes due scheduled posts and leaves future ones alone', function () {
    $due = InstagramPost::factory()->for($this->team)->due()->create();
    $future = InstagramPost::factory()->for($this->team)->scheduled()->create();

    connectInstagramAccount($this->team, $due->ig_user_id);

    Http::fake([
        'https://graph.instagram.com/*content_publishing_limit*' => Http::response([
            'data' => [['quota_total' => 100, 'quota_used' => 10]],
        ]),
        'https://graph.instagram.com/*/media' => Http::response(['id' => 'ig_container_due']),
        'https://graph.instagram.com/*/media_publish' => Http::response(['id' => 'ig_media_due']),
        '*' => Http::response(),
    ]);

    Artisan::call('instagram:publish-scheduled');

    expect($due->fresh())
        ->status->toBe(InstagramPost::STATUS_PUBLISHED)
        ->media_id->toBe('ig_media_due')
        ->scheduled_at->toBeNull();

    expect($future->fresh())
        ->status->toBe(InstagramPost::STATUS_SCHEDULED)
        ->scheduled_at->not->toBeNull();
});

it('can publish a scheduled post immediately, skipping the queue', function () {
    $post = InstagramPost::factory()->for($this->team)->scheduled()->create();

    connectInstagramAccount($this->team, $post->ig_user_id);

    Http::fake([
        'https://graph.instagram.com/*content_publishing_limit*' => Http::response([
            'data' => [['quota_total' => 100, 'quota_used' => 10]],
        ]),
        'https://graph.instagram.com/*/media' => Http::response(['id' => 'ig_container_1']),
        'https://graph.instagram.com/*/media_publish' => Http::response(['id' => 'ig_media_1']),
        '*' => Http::response(),
    ]);

    bootTenantPanelFor($this->team);

    Livewire::test(ListInstagramPosts::class)
        ->callAction(TestAction::make('publish')->table($post))
        ->assertNotified();

    assertDatabaseHas('instagram_posts', [
        'id' => $post->id,
        'status' => InstagramPost::STATUS_PUBLISHED,
        'media_id' => 'ig_media_1',
    ]);
});

it('can cancel a schedule and return the post to draft', function () {
    $post = InstagramPost::factory()->for($this->team)->scheduled()->create();

    bootTenantPanelFor($this->team);

    Livewire::test(ListInstagramPosts::class)
        ->callAction(TestAction::make('unschedule')->table($post))
        ->assertNotified();

    assertDatabaseHas('instagram_posts', [
        'id' => $post->id,
        'status' => InstagramPost::STATUS_DRAFT,
        'scheduled_at' => null,
    ]);
});
