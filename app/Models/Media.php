<?php

declare(strict_types=1);

namespace App\Models;

use Awcodes\Curator\Facades\Curator;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends \Awcodes\Curator\Models\Media
{
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Curator's HasPackageFactory::newFactory() resolves the factory from the
     * model namespace as "App\Database\Factories\MediaFactory", but this app's
     * factories live under the "Database\Factories\" namespace.
     */
    protected static function newFactory(): Factory
    {
        return MediaFactory::new();
    }

    /**
     * Herkese açık bir medya URL'sinden ilgili Media kaydını bulur.
     *
     * Curator disk URL'leri `{disk_url}/{path}` formatındadır ancak önek
     * diske göre değişir:
     * - `public` disk: `{APP_URL}/storage/{path}`
     * - R2/S3 (virtual-host veya custom domain): `https://{host}/{path}`
     * - R2/S3 (path-style endpoint): `https://{endpoint}/{bucket}/{path}`
     *
     * Bu yüzden URL'nin path bölümünden baştan segment kırparak üretilen
     * tüm suffix adayları indexli `path` kolonu üzerinden aranır ve en
     * uzun (en spesifik) eşleşme döndürülür. Form hydrate edilirken
     * Curator seçimini geri yüklemek için kullanılır.
     */
    public static function findByPublicUrl(string $url): ?self
    {
        $urlPath = parse_url($url, PHP_URL_PATH);

        if (! is_string($urlPath) || $urlPath === '') {
            return null;
        }

        $candidates = [];
        $segment = ltrim($urlPath, '/');

        // En fazla 3 önek segment kırılır (storage/, bucket/, vb.).
        for ($depth = 0; $depth <= 3 && $segment !== ''; $depth++) {
            $candidates[] = $segment;

            $nextSlash = strpos($segment, '/');

            if ($nextSlash === false) {
                break;
            }

            $segment = substr($segment, $nextSlash + 1);
        }

        return static::query()
            ->whereIn('path', $candidates)
            ->get()
            ->sortByDesc(fn (self $media): int => strlen($media->path))
            ->first();
    }

    public function thumbnailUrl(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if (curator()->isVideo($this->ext)) {
                    return filled($this->curations['video_thumbnail'] ?? null)
                        ? route('media.thumbnail', ['media' => $this->name])
                        : null;
                }

                return Curator::getUrlProvider()::getThumbnailUrl($this->path);
            },
        );
    }

    public function mediumUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): string => curator()->isVideo($this->ext)
                ? $this->videoPlayableUrl()
                : Curator::getUrlProvider()::getMediumUrl($this->path),
        );
    }

    public function largeUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): string => curator()->isVideo($this->ext)
                ? $this->videoPlayableUrl()
                : Curator::getUrlProvider()::getLargeUrl($this->path),
        );
    }

    /**
     * Video için oynatılabilir bir URL üretir.
     *
     * Public disklerde (R2/S3 custom domain veya r2.dev) medyanın kendi
     * public URL'si kullanılır; bu URL, tarayıcı video oynatıcısının
     * ihtiyaç duyduğu Range/seek isteklerini doğrudan karşılar.
     *
     * Disk public değilse, uygulama üzerinden stream eden
     * `media.video` proxy rotasına düşülür.
     */
    protected function videoPlayableUrl(): string
    {
        $config = config("filesystems.disks.{$this->disk}", []);

        if (($config['visibility'] ?? null) === 'public' || $this->visibility === 'public') {
            return (string) $this->url;
        }

        return route('media.video', ['media' => $this->name]);
    }
}
