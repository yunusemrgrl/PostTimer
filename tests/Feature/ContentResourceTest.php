<?php

use App\Filament\App\Resources\Contents\Pages\CreateContent;
use App\Filament\App\Resources\Contents\Pages\EditContent;
use App\Filament\App\Resources\Contents\Pages\ListContents;
use App\Models\Content;
use App\Models\Media;
use App\Models\Product;
use App\Models\Publication;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

function bootPanelForContent(Team $team): void
{
    Filament::setCurrentPanel('app');
    Filament::setTenant($team);
    Filament::bootCurrentPanel();
}

// fillForm iç içe dizileri parçaladığı için picker state'i set() ile verilir
// (InstagramPostResourceTest ile aynı desen).
function pickerState(Media $media): array
{
    return [(string) Str::uuid() => $media->toArray()];
}

it('creates a feed image content through the form', function () {
    bootPanelForContent($this->team);

    $product = Product::factory()->for($this->team)->create();

    $media = Media::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(CreateContent::class)
        ->fillForm([
            'type' => Content::TYPE_IMAGE,
            'surface' => Content::SURFACE_FEED,
            'caption' => 'Yeni içerik açıklaması',
            'alt_text' => 'Portakal görseli',
            'first_comment' => 'Link yorumda',
            'product_id' => $product->id,
        ])
        ->set('data.media_url', pickerState($media))
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    assertDatabaseHas(Content::class, [
        'team_id' => $this->team->id,
        'type' => Content::TYPE_IMAGE,
        'surface' => Content::SURFACE_FEED,
        'caption' => 'Yeni içerik açıklaması',
        'product_id' => $product->id,
    ]);
});

it('creates a carousel content from the picker selection', function () {
    bootPanelForContent($this->team);

    $items = [
        Media::factory()->create(['team_id' => $this->team->id]),
        Media::factory()->create(['team_id' => $this->team->id]),
    ];

    Livewire::test(CreateContent::class)
        ->fillForm([
            'type' => Content::TYPE_CAROUSEL_ALBUM,
            'caption' => 'Karusel içerik',
        ])
        ->set('data.carousel_media', [
            ...pickerState($items[0]),
            ...pickerState($items[1]),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Content::query()->where('type', Content::TYPE_CAROUSEL_ALBUM)->first())
        ->not->toBeNull()
        ->children->toHaveCount(2);
});

it('creates a story content with link sticker', function () {
    bootPanelForContent($this->team);

    $media = Media::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(CreateContent::class)
        ->fillForm([
            'type' => Content::TYPE_IMAGE,
            'surface' => Content::SURFACE_STORY,
            'caption' => 'Story içerik',
            'story_link' => 'https://www.amazon.com.tr/dp/B0CXKJ5F2N',
        ])
        ->set('data.media_url', pickerState($media))
        ->call('create')
        ->assertHasNoFormErrors();

    assertDatabaseHas(Content::class, [
        'surface' => Content::SURFACE_STORY,
        'story_link' => 'https://www.amazon.com.tr/dp/B0CXKJ5F2N',
    ]);
});

it('requires media for non-carousel types', function () {
    bootPanelForContent($this->team);

    Livewire::test(CreateContent::class)
        ->fillForm([
            'type' => Content::TYPE_IMAGE,
            'caption' => 'Medyasız',
        ])
        ->call('create')
        ->assertHasFormErrors(['media_url']);
});

it('updates caption through the edit page', function () {
    bootPanelForContent($this->team);

    $media = Media::factory()->create(['team_id' => $this->team->id]);

    $content = Content::factory()->create([
        'team_id' => $this->team->id,
        // Hydrate'te Curator seçimine geri dönüştürülebilir bir public URL
        'media_url' => Media::resolveUrl((string) $media->disk, (string) $media->path, 'public'),
        'caption' => 'Eski başlık',
    ]);

    Livewire::test(EditContent::class, ['record' => $content->id])
        ->fillForm(['caption' => 'Yeni başlık'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($content->fresh()->caption)->toBe('Yeni başlık');
});

it('shows a publication status summary per content', function () {
    bootPanelForContent($this->team);

    $content = Content::factory()->create(['team_id' => $this->team->id]);

    expect($content->publicationsSummary())->toBe('—');

    Publication::factory()->count(2)->published()->create([
        'team_id' => $this->team->id,
        'content_id' => $content->id,
    ]);
    Publication::factory()->scheduled()->create([
        'team_id' => $this->team->id,
        'content_id' => $content->id,
    ]);

    expect($content->publicationsSummary())->toContain('Yayınlandı: 2')
        ->and($content->publicationsSummary())->toContain('Zamanlandı: 1');
});

it('lists contents with their publication summary column', function () {
    bootPanelForContent($this->team);

    $content = Content::factory()->create(['team_id' => $this->team->id]);
    Publication::factory()->published()->create([
        'team_id' => $this->team->id,
        'content_id' => $content->id,
    ]);

    Livewire::test(ListContents::class)
        ->assertCanSeeTableRecords([$content]);
});

it('shows AI Dublaj action only for video contents', function () {
    bootPanelForContent($this->team);

    $video = Content::factory()->reels()->create(['team_id' => $this->team->id]);
    $image = Content::factory()->create(['team_id' => $this->team->id, 'type' => Content::TYPE_IMAGE]);

    Livewire::test(ListContents::class)
        ->assertActionVisible(TestAction::make('localizeVideo')->table($video))
        ->assertActionHidden(TestAction::make('localizeVideo')->table($image));
});
