<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Services;

use App\Filament\Curator\TenantPathGenerator;
use App\Models\Content;
use App\Models\Media;
use App\Models\Team;
use Awcodes\Curator\Facades\Curator;
use Filament\Facades\Filament;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Toplu video yükleme servisi. Birden fazla dosyayı alır, her biri için
 * Curator Media kaydı + Content oluşturur. Tenant path isolation
 * TenantPathGenerator ile sağlanır.
 */
class BulkMediaImportService
{
    /**
     * @param  array<int, UploadedFile>  $files
     * @param  array{type?: string, surface?: string, caption?: ?string, first_comment?: ?string, product_id?: ?int}  $defaults
     * @return array{created: int, failed: int}
     */
    public function importVideos(array $files, array $defaults = []): array
    {
        $team = Filament::getTenant();
        assert($team instanceof Team);

        $diskName = Curator::getDiskName();
        $disk = Storage::disk($diskName);
        $directory = $this->resolveDirectory();
        $type = $defaults['type'] ?? Content::TYPE_VIDEO;
        $surface = $defaults['surface'] ?? Content::SURFACE_REELS;

        $created = 0;
        $failed = 0;

        foreach ($files as $file) {
            try {
                $media = $this->createMediaRecord($file, $diskName, $disk, $directory, $team);
                $mediaUrl = Media::resolveUrl($media->disk, $media->path, $media->visibility);

                Content::query()->create([
                    'team_id' => $team->id,
                    'type' => $type,
                    'surface' => $surface,
                    'media_url' => $mediaUrl,
                    'caption' => $defaults['caption'] ?? null,
                    'first_comment' => $defaults['first_comment'] ?? null,
                    'product_id' => $defaults['product_id'] ?? null,
                ]);

                $created++;
            } catch (\Throwable $e) {
                report($e);
                $failed++;
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }

    private function resolveDirectory(): string
    {
        return app(TenantPathGenerator::class)->getPath();
    }

    private function createMediaRecord(
        UploadedFile $file,
        string $diskName,
        Filesystem $disk,
        string $directory,
        Team $team,
    ): Media {
        $filename = (string) Str::uuid();
        $extension = mb_strtolower($file->getClientOriginalExtension() ?: 'mp4');
        $path = rtrim($directory, '/').'/'.$filename.'.'.$extension;

        $disk->put($path, $file->getContent(), [
            'visibility' => 'public',
            'ContentType' => $file->getMimeType(),
        ]);

        return Media::query()->create([
            'disk' => $diskName,
            'directory' => $directory,
            'visibility' => 'public',
            'name' => $filename,
            'path' => $path,
            'ext' => $extension,
            'type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'team_id' => $team->id,
        ]);
    }
}
