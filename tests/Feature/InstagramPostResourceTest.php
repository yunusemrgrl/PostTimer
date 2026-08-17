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
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

/**
 * Test verisi (factory) her zaman panel boot edilmeden ÖNCE oluşturulmalı;
 * aksi halde Filament'in `creating` dinleyicisi kayıtları aktif tenant'a
 * yeniden ilişkilendirir.
 */
function bootTenantPanel(Team $team): void
{
    Filament::setCurrentPanel('app');
    Filament::setTenant($team);
    Filament::bootCurrentPanel();
}

beforeEach(function () {
    $this->user = User::factory()->create();

    $this->team = Team::factory()
        ->hasAttached($this->user, ['role' => TeamMember::ROLE_OWNER], 'users')
        ->create();

    $this->actingAs($this->user);
});

it('lists only the current tenants instagram posts', function () {
    $otherTeam = Team::factory()->create();

    $ownPost = InstagramPost::factory()->for($this->team)->create();
    $otherPost = InstagramPost::factory()->for($otherTeam)->create();

    bootTenantPanel($this->team);

    $visibleIds = Livewire::test(ListInstagramPosts::class)
        ->instance()
        ->getTableRecords()
        ->pluck('id');

    expect($visibleIds)
        ->toContain($ownPost->id)
        ->not->toContain($otherPost->id);
});

it('associates newly created posts with the current tenant', function () {
    bootTenantPanel($this->team);

    Livewire::test(CreateInstagramPost::class)
        ->fillForm([
            'ig_user_id' => '90010177253934',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/images/bronz-fonz.jpg',
            'caption' => 'Yeni içerik',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    assertDatabaseHas('instagram_posts', [
        'team_id' => $this->team->id,
        'ig_user_id' => '90010177253934',
        'status' => InstagramPost::STATUS_DRAFT,
    ]);
});

it('publishes a draft post through the table action', function () {
    $post = InstagramPost::factory()->for($this->team)->create();

    InstagramAccount::factory()
        ->for($this->team)
        ->withToken('account-token')
        ->create([
            'ig_user_id' => $post->ig_user_id,
            'api_host' => 'graph.instagram.com',
        ]);

    Http::fake([
        'https://graph.instagram.com/*content_publishing_limit*' => Http::response([
            'data' => [['quota_total' => 100, 'quota_used' => 10]],
        ]),
        'https://graph.instagram.com/*/media' => Http::response(['id' => 'ig_container_1']),
        'https://graph.instagram.com/*/media_publish' => Http::response(['id' => 'ig_media_1']),
        '*' => Http::response(),
    ]);

    bootTenantPanel($this->team);

    Livewire::test(ListInstagramPosts::class)
        ->callAction(TestAction::make('publish')->table($post))
        ->assertNotified();

    assertDatabaseHas('instagram_posts', [
        'id' => $post->id,
        'status' => InstagramPost::STATUS_PUBLISHED,
        'media_id' => 'ig_media_1',
    ]);
});

it('hides the publish action from plain team members', function () {
    $this->user->teams()->updateExistingPivot($this->team, ['role' => TeamMember::ROLE_MEMBER]);

    $post = InstagramPost::factory()->for($this->team)->create();

    bootTenantPanel($this->team);

    Livewire::test(ListInstagramPosts::class)
        ->assertActionHidden(TestAction::make('publish')->table($post));
});
