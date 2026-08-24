<?php

use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
    Cache::flush();
    config(['media.ffmpeg_binary' => 'definitely-missing-ffmpeg-binary']);
});

it('marks the thumbnail as ffmpeg_missing when the binary does not exist', function () {
    $media = videoMediaOnDisk();

    expect($media->fresh()->curations)
        ->thumbnail_status->toBe('ffmpeg_missing')
        ->video_thumbnail->toBeNull();
});

it('caches the ffmpeg availability probe', function () {
    $media = videoMediaOnDisk();

    // İlk media probe çalıştırır ve sonucu cache'ler.
    expect($media->fresh()->curations['thumbnail_status'])->toBe('ffmpeg_missing');
    expect(Cache::get('curator:ffmpeg-available'))->toBeFalse();

    // İkinci video: probe tekrar ÇALIŞMAMALI (cache). Bunu doğrulamanın
    // kolay yolu: cache değeri true'ya çevrilirse bile binary hâlâ yok —
    // ama observer cache'e güvenir ve thumbnail denemesi yapmaz (hata olmaz).
    Cache::put('curator:ffmpeg-available', true, now()->addDay());
    config(['media.ffmpeg_binary' => 'definitely-missing-ffmpeg-binary']);

    $second = videoMediaOnDisk();

    // Cache true dediği için üretim denenir; ffmpeg gerçekten olmadığından
    // process başarısız olur → 'failed' işaretlenir (ffmpeg_missing DEĞİL).
    expect($second->fresh()->curations['thumbnail_status'])->toBe('failed');
});
