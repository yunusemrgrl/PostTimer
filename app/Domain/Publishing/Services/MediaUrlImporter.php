<?php

namespace App\Domain\Publishing\Services;

use App\Models\Media;
use App\Models\Team;
use App\Support\Http\SafeHttpFetcher;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Harici bir URL'i SSRF korumalı indirip takımın medya kütüphanesine
 * (Curator) aktarır. MIME, dosyanın magic byte'larından sniff edilir
 * (Content-Type header'a güvenilemez); uzantı bu MIME'den türetilir.
 *
 * Oluşturulan Media kaydı üzerinde MediaObserver otomatik çalışır:
 * magic-byte doğrulaması ve video thumbnail üretimi.
 */
class MediaUrlImporter
{
    /**
     * MIME → uzantı (Instagram'a gönderilebilir tipler).
     *
     * @var array<string, string>
     */
    private const EXT_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
    ];

    public function __construct(
        protected SafeHttpFetcher $fetcher,
    ) {}

    /**
     * @return Media Oluşturulan medya kaydı.
     *
     * @throws RuntimeException İndirme, tip veya boyut hatasında.
     */
    public function import(Team $team, string $url): Media
    {
        $download = $this->fetcher->fetchToTempFile($url);

        $ext = self::EXT_BY_MIME[(string) $download['mime']] ?? null;

        if ($ext === null) {
            @unlink($download['path']);

            throw new RuntimeException(
                'Desteklenmeyen içerik tipi: '.($download['mime'] ?? 'bilinmiyor')
                .'. İzinli: image/jpeg|png|gif|webp, video/mp4|quicktime|webm.'
            );
        }

        try {
            $disk = (string) config('curator.default_disk', 'public');
            $directory = 'media/'.now()->format('Y/m');
            $name = Str::random(24);
            $path = $directory.'/'.$name.'.'.$ext;

            Storage::disk($disk)->put(
                $path,
                fopen($download['path'], 'rb'),
                ['visibility' => 'public'],
            );

            return Media::query()->create([
                'team_id' => $team->id,
                'disk' => $disk,
                'directory' => $directory,
                'name' => $name,
                'path' => $path,
                'ext' => $ext,
                'type' => (string) $download['mime'],
                'size' => $download['bytes'],
                'width' => null,
                'height' => null,
            ]);
        } finally {
            @unlink($download['path']);
        }
    }
}
