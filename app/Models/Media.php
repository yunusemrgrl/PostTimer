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
                ? route('media.video', ['media' => $this->name])
                : Curator::getUrlProvider()::getMediumUrl($this->path),
        );
    }

    public function largeUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): string => curator()->isVideo($this->ext)
                ? route('media.video', ['media' => $this->name])
                : Curator::getUrlProvider()::getLargeUrl($this->path),
        );
    }
}
