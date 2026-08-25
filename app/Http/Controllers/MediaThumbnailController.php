<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /**
     * Tarayıcıdan (canvas) üretilen JPEG thumbnail'ı kabul eder, diske
     * kaydeder ve medyanın curations JSON'una işler. FFmpeg gerektirmez —
     * thumbnail üretimi tamamen client-side'dır.
     */
    public function store(Request $request, Media $media): JsonResponse
    {
        $validated = $request->validate([
            'thumbnail' => ['required', 'string', 'starts_with:data:image/jpeg'],
        ]);

        abort_unless(curator()->isVideo($media->ext), 422);

        $data = base64_decode(
            preg_replace('#^data:image/\w+;base64,#i', '', $validated['thumbnail'])
        );

        if ($data === false || strlen($data) === 0) {
            return response()->json(['error' => 'Invalid thumbnail data'], 422);
        }

        $thumbnailsDirectory = (string) config('media.thumbnails_directory', 'media_thumbnails');

        $thumbnailDir = preg_replace(
            '#/media/#',
            '/'.$thumbnailsDirectory.'/',
            $media->directory,
            1
        ) ?? $media->directory;

        $thumbnailPath = $thumbnailDir.'/'.$media->name.'-thumbnail.jpg';

        Storage::disk($media->disk)->put($thumbnailPath, $data, [
            'visibility' => 'public',
            'ContentType' => 'image/jpeg',
        ]);

        $curations = $media->curations ?? [];
        $curations['video_thumbnail'] = $thumbnailPath;
        $curations['thumbnail_status'] = 'ok';
        unset($curations['thumbnail_error']);

        $media->curations = $curations;
        $media->saveQuietly();

        return response()->json([
            'thumbnail_url' => $media->fresh()->thumbnail_url,
        ]);
    }
}
