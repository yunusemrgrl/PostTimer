<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaThumbnailController extends Controller
{
    public function show(Media $media): StreamedResponse
    {
        abort_unless(
            $media->ext === 'mp4' &&
            filled($media->curations['video_thumbnail'] ?? null),
            404
        );

        $path = $media->curations['video_thumbnail'];
        $disk = Storage::disk($media->disk);

        abort_unless($disk->exists($path), 404);

        return $disk->response(
            $path,
            null,
            [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ],
            'inline',
        );
    }

    public function video(Media $media): StreamedResponse
    {
        abort_unless($media->ext === 'mp4', 404);

        $disk = Storage::disk($media->disk);

        abort_unless($disk->exists($media->path), 404);

        return $disk->response(
            $media->path,
            $media->name,
            [
                'Content-Type' => 'video/mp4',
                'Cache-Control' => 'public, max-age=3600',
                'Accept-Ranges' => 'bytes',
            ],
            'inline',
        );
    }
}
