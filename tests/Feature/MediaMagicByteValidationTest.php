<?php

use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// 1x1 şeffaf PNG (gerçek magic byte: 89 50 4E 47).
const PNG_BYTES = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";

// HTML içeriği — .png uzantısıyla yüklendiğinde spoofing senaryosu.
const HTML_BYTES = '<!DOCTYPE html><html><body>not an image</body></html>';

function mediaOnDisk(string $ext, string $bytes, array $attributes = []): Media
{
    $path = 'media/test-'.uniqid().'.'.$ext;
    Storage::disk('public')->put($path, $bytes);

    return Media::factory()->create([...$attributes, 'disk' => 'public', 'path' => $path, 'ext' => $ext]);
}

beforeEach(function () {
    // Observer side-effect'leri (magic-byte okuma) için disk fake'lenir.
    Http::fake(['*' => Http::response()]);
    Storage::fake('public');
});

it('keeps media whose content matches its extension', function () {
    $media = mediaOnDisk('png', PNG_BYTES);

    expect($media->fresh())->not->toBeNull();
    expect(Storage::disk('public')->exists($media->path))->toBeTrue();
});

it('deletes media whose magic bytes do not match the extension', function () {
    $media = mediaOnDisk('png', HTML_BYTES);

    expect($media->fresh())->toBeNull();
    expect(Storage::disk('public')->exists($media->path))->toBeFalse();
});

it('skips validation for extensions outside the allowed set', function () {
    // Bilinmeyen uzantı → beklenen MIME yok → kontrol atlanır.
    $media = mediaOnDisk('svg', HTML_BYTES);

    expect($media->fresh())->not->toBeNull();
});

it('does not touch records without a physical file', function () {
    // Fabrika kaydı (dosya yok) → kontrol sessizce atlanır.
    $media = Media::factory()->create();

    expect($media->fresh())->not->toBeNull();
});
