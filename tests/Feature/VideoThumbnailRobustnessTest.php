<?php

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// Geçerli mp4 başlangıcı (magic byte: ftypmp42) — magic-byte doğrulamasını geçer.
const MP4_BYTES = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00isom";

function videoMediaOnDisk(): Media
{
    $path = 'media/test-'.uniqid().'.mp4';
    Storage::disk('public')->put($path, MP4_BYTES);

    return Media::factory()->create([
        'disk' => 'public',
        'path' => $path,
        'ext' => 'mp4',
        'type' => 'video/mp4',
    ]);
}

beforeEach(function () {
    Http::fake(['*' => Http::response()]);
    Storage::fake('public');
});

it('marks the thumbnail as pending on creation for client-side generation', function () {
    $media = videoMediaOnDisk();

    expect($media->fresh()->curations)
        ->thumbnail_status->toBe('pending')
        ->video_thumbnail->toBeNull();
});

it('does not attempt server-side ffmpeg processing', function () {
    $media = videoMediaOnDisk();

    // No ffmpeg probe, no failed status — just pending
    expect($media->fresh()->curations['thumbnail_status'])->toBe('pending');
});

it('stores a client-uploaded thumbnail via the POST endpoint', function () {
    $media = videoMediaOnDisk();

    $base64 = 'data:image/jpeg;base64,'.base64_encode("\xFF\xD8\xFF\xE0");

    $this->actingAs($media->team->owner ?? User::factory()->create())
        ->postJson("/media/{$media->name}/thumbnail", ['thumbnail' => $base64])
        ->assertOk()
        ->assertJsonStructure(['thumbnail_url']);

    expect($media->fresh()->curations)
        ->thumbnail_status->toBe('ok')
        ->video_thumbnail->not->toBeNull();
});
