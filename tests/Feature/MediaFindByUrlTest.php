<?php

use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves curator media from public disk, r2 virtual-host and path-style urls', function () {
    $media = Media::factory()->create([
        'disk' => 'r2',
        'directory' => 'tenants/acme/media/2026/08',
        'name' => 'foto',
        'path' => 'tenants/acme/media/2026/08/foto.jpg',
        'ext' => 'jpg',
        'type' => 'image/jpeg',
    ]);

    // public disk: {APP_URL}/storage/{path}
    $publicDisk = Media::findByPublicUrl('http://localhost/storage/'.$media->path);

    // R2/S3 virtual-host veya custom domain: https://{host}/{path}
    $virtualHost = Media::findByPublicUrl('https://cdn.example.com/'.$media->path);

    // R2/S3 path-style endpoint: https://{endpoint}/{bucket}/{path}
    $pathStyle = Media::findByPublicUrl('https://acc.r2.cloudflarestorage.com/r2-bucket/'.$media->path);

    $missing = Media::findByPublicUrl('https://example.com/olmayan/medya.png');

    expect($publicDisk?->id)->toBe($media->id)
        ->and($virtualHost?->id)->toBe($media->id)
        ->and($pathStyle?->id)->toBe($media->id)
        ->and($missing)->toBeNull();
});
