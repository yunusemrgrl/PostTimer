<?php

use App\Events\PostPublished;
use App\Filament\App\Resources\InstagramPosts\Pages\CreateInstagramPost;
use App\Filament\App\Resources\InstagramPosts\Pages\ListInstagramPosts;
use App\Jobs\PublishScheduledPost;
use App\Models\InstagramAccount;
use App\Models\InstagramPost;
use App\Models\Media;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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
    connectInstagramAccount($this->team, '90010177253934');

    $media = Media::factory()->for($this->team)->create([
        'disk' => 'public',
        'path' => 'tenants/test/media/2026/08/scheduled.jpg',
        'ext' => 'jpg',
        'type' => 'image/jpeg',
    ]);

    bootTenantPanelFor($this->team);

    Livewire::test(CreateInstagramPost::class)
        ->fillForm([
            'ig_user_id' => '90010177253934',
            'media_type' => 'IMAGE',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ])
        ->set('data.media_url', [(string) Str::uuid() => $media->toArray()])
        ->call('create')
        ->assertHasNoFormErrors();

    $post = InstagramPost::query()->first();

    expect($post)
        ->status->toBe(InstagramPost::STATUS_SCHEDULED)
        ->scheduled_at->not->toBeNull()
        ->media_url->toBe(Media::resolveUrl($media->disk, $media->path));

    Http::assertNothingSent();
});

it('keeps a post as draft when no date is chosen', function () {
    connectInstagramAccount($this->team, '90010177253934');

    $media = Media::factory()->for($this->team)->create([
        'disk' => 'public',
        'path' => 'tenants/test/media/2026/08/draft.jpg',
        'ext' => 'jpg',
        'type' => 'image/jpeg',
    ]);

    bootTenantPanelFor($this->team);

    Livewire::test(CreateInstagramPost::class)
        ->fillForm([
            'ig_user_id' => '90010177253934',
            'media_type' => 'IMAGE',
        ])
        ->set('data.media_url', [(string) Str::uuid() => $media->toArray()])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(InstagramPost::query()->first())
        ->status->toBe(InstagramPost::STATUS_DRAFT)
        ->scheduled_at->toBeNull();
});

it('publishes due scheduled posts and leaves future ones alone', function () {
    Queue::fake();
    Event::fake([PostPublished::class]);

    $due = InstagramPost::factory()->for($this->team)->due()->create();
    $future = InstagramPost::factory()->for($this->team)->scheduled()->create();

    connectInstagramAccount($this->team, $due->ig_user_id);

    Artisan::call('instagram:publish-scheduled');

    // Due post için job dispatch edildi
    Queue::assertPushed(PublishScheduledPost::class);

    // Future post için job dispatch edilmedi
    expect($future->fresh())
        ->status->toBe(InstagramPost::STATUS_SCHEDULED)
        ->scheduled_at->not->toBeNull();
});

it('can publish a scheduled post immediately, skipping the queue', function () {
    Event::fake([PostPublished::class]);

    $post = InstagramPost::factory()->for($this->team)->scheduled()->create();

    connectInstagramAccount($this->team, $post->ig_user_id);

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

    bootTenantPanelFor($this->team);

    Livewire::test(ListInstagramPosts::class)
        ->callAction(TestAction::make('publish')->table($post))
        ->assertNotified();

    assertDatabaseHas('instagram_posts', [
        'id' => $post->id,
        'status' => InstagramPost::STATUS_PUBLISHED,
        'media_id' => 'ig_media_1',
    ]);

    Event::assertDispatched(PostPublished::class);
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
