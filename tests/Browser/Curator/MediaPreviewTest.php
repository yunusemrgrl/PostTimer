<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.media_tenant_hash_key' => 'test-secret-key']);
    Storage::fake('r2');

    $this->user = User::factory()->create();

    $this->team = Team::factory()
        ->hasAttached($this->user, ['role' => TeamMember::ROLE_OWNER], 'users')
        ->create();

    $this->actingAs($this->user);
});

it('renders video thumbnails as image previews and player posters in the browser', function () {
    Route::get('/__tests/curator-video-preview/{media}', function (string $mediaId) {
        $media = Media::query()->findOrFail($mediaId);

        return Blade::render(
            <<<'BLADE'
            <x-curator::display
                :item="$media"
                :src="$player ? $media->large_url : $media->thumbnail_url"
                :poster="$media->thumbnail_url"
                :controls="$player"
                :player="$player"
                class="h-full w-full"
            />
            BLADE,
            [
                'media' => $media,
                'player' => request()->boolean('player'),
            ],
        );
    });

    $media = Media::factory()->for($this->team)->create([
        'disk' => 'r2',
        'name' => 'preview-video',
        'path' => 'media/preview-video.mp4',
        'ext' => 'mp4',
        'type' => 'video/mp4',
        'curations' => [
            'video_thumbnail' => 'media/preview-video-thumbnail.jpg',
        ],
    ]);

    Storage::disk('r2')->put('media/preview-video.mp4', 'video-content');
    Storage::disk('r2')->put('media/preview-video-thumbnail.jpg', 'thumbnail-content');

    $thumbnailPath = parse_url(route('media.thumbnail', ['media' => 'preview-video']), PHP_URL_PATH);
    $videoPath = parse_url(route('media.video', ['media' => 'preview-video']), PHP_URL_PATH);

    $page = visit('/__tests/curator-video-preview/'.$media->id);

    $page
        ->assertPresent('img[src]')
        ->assertNotPresent('video[src]')
        ->assertNoJavaScriptErrors();

    expect(parse_url((string) $page->script('document.querySelector("img")?.getAttribute("src")'), PHP_URL_PATH))
        ->toBe($thumbnailPath);

    $playerPage = visit('/__tests/curator-video-preview/'.$media->id.'?player=1');

    $playerPage
        ->assertPresent('video[src]')
        ->assertNotPresent('img[src]')
        ->assertNoJavaScriptErrors();

    expect(parse_url((string) $playerPage->script('document.querySelector("video")?.getAttribute("src")'), PHP_URL_PATH))
        ->toBe($videoPath)
        ->and(parse_url((string) $playerPage->script('document.querySelector("video")?.getAttribute("poster")'), PHP_URL_PATH))->toBe($thumbnailPath)
        ->and($playerPage->script('document.querySelector("video")?.hasAttribute("controls")'))->toBeTrue();
});
