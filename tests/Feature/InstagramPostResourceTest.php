<?php

use App\Events\PostPublished;
use App\Filament\App\Resources\InstagramPosts\Pages\CreateInstagramPost;
use App\Filament\App\Resources\InstagramPosts\Pages\EditInstagramPost;
use App\Filament\App\Resources\InstagramPosts\Pages\ListInstagramPosts;
use App\Models\InstagramAccount;
use App\Models\InstagramPost;
use App\Models\Media;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([PostPublished::class]);
});

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

/**
 * Curator kütüphanesinde takım için test medyası oluşturur.
 */
function createMediaFor(Team $team, string $name, string $type = 'image/jpeg', string $ext = 'jpg'): Media
{
    return Media::factory()->for($team)->create([
        'disk' => 'public',
        'directory' => 'tenants/test/media/2026/08',
        'name' => $name,
        'path' => "tenants/test/media/2026/08/{$name}.{$ext}",
        'ext' => $ext,
        'type' => $type,
    ]);
}

it('associates newly created posts with the current tenant and fills media url from curator', function () {
    InstagramAccount::factory()
        ->for($this->team)
        ->withToken('account-token')
        ->create(['ig_user_id' => '90010177253934']);

    $media = createMediaFor($this->team, 'bronz-fonz');

    bootTenantPanel($this->team);

    Livewire::test(CreateInstagramPost::class)
        ->fillForm([
            'ig_user_id' => '90010177253934',
            'media_type' => 'IMAGE',
            'caption' => 'Yeni içerik',
        ])
        // CuratorPicker state'i: uuid => medya dizisi (modal seçim formatı).
        // fillForm iç içe dizileri parçaladığı için picker state'i set() ile verilir.
        ->set('data.media_url', [(string) Str::uuid() => $media->toArray()])
        ->call('create')
        ->assertHasNoFormErrors();

    assertDatabaseHas('instagram_posts', [
        'team_id' => $this->team->id,
        'ig_user_id' => '90010177253934',
        'status' => InstagramPost::STATUS_DRAFT,
        // media_url, Curator medyasının public URL'si ile otomatik doldurulur
        'media_url' => Media::resolveUrl($media->disk, $media->path),
    ]);
});

it('stores carousel children as public urls of the selected curator media', function () {
    InstagramAccount::factory()
        ->for($this->team)
        ->withToken('account-token')
        ->create(['ig_user_id' => '90010177253934']);

    $first = createMediaFor($this->team, 'karusel-ilk');
    $second = createMediaFor($this->team, 'karusel-ikinci');

    bootTenantPanel($this->team);

    Livewire::test(CreateInstagramPost::class)
        ->fillForm([
            'ig_user_id' => '90010177253934',
            'media_type' => 'CAROUSEL',
            'caption' => 'Karusel içerik',
        ])
        ->set('data.carousel_media', [
            (string) Str::uuid() => $first->toArray(),
            (string) Str::uuid() => $second->toArray(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $post = InstagramPost::query()->latest('id')->first();

    expect($post)
        ->media_type->toBe(InstagramPost::MEDIA_TYPE_CAROUSEL)
        ->children->toBe([
            ['url' => Media::resolveUrl($first->disk, $first->path)],
            ['url' => Media::resolveUrl($second->disk, $second->path)],
        ]);
});

it('keeps the curator media url when editing an existing post', function () {
    $media = createMediaFor($this->team, 'mevcut-medya');
    $url = Media::resolveUrl($media->disk, $media->path);

    $post = InstagramPost::factory()->for($this->team)->create([
        'media_type' => InstagramPost::MEDIA_TYPE_IMAGE,
        'media_url' => $url,
        'status' => InstagramPost::STATUS_DRAFT,
    ]);

    InstagramAccount::factory()
        ->for($this->team)
        ->withToken('account-token')
        ->create(['ig_user_id' => $post->ig_user_id]);

    bootTenantPanel($this->team);

    Livewire::test(EditInstagramPost::class, ['record' => $post->id])
        ->call('save')
        ->assertHasNoFormErrors();

    // Hydrate → dehydrate turunda public URL korunur
    expect($post->fresh()->media_url)->toBe($url);
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
            'data' => [['quota_usage' => 10, 'config' => ['quota_total' => 100]]],
        ]),
        'https://graph.instagram.com/*/media' => Http::response(['id' => 'ig_container_1']),
        'https://graph.instagram.com/*/media_publish' => Http::response(['id' => 'ig_media_1']),
        'https://graph.instagram.com/*ig_media_*' => Http::response([
            'id' => 'ig_media_1',
            'permalink' => 'https://instagram.com/p/ig_media_1',
        ]),
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
