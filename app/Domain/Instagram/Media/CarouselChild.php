<?php

namespace App\Domain\Instagram\Media;

use App\Domain\Instagram\Payload\ImageContainerPayload;
use App\Domain\Instagram\Payload\InstagramContainerPayload;
use App\Domain\Instagram\Payload\VideoContainerPayload;

/**
 * Karusel çocuk medyasını temsil eden küçük value object.
 *
 * Karusel children DB'de yalnızca `['url' => ...]` / string URL olarak
 * saklandığından image/video ayrımı URL'den türetilir. Eski kodda bu
 * ayrım `preg_match('/\.(mp4|mov)/')` ile service içine dağılmıştı; bu
 * tek yerde toplanır ve tip-güvenli bir payload üretir.
 */
final readonly class CarouselChild
{
    public function __construct(public string $url) {}

    /**
     * Form verisinden gelen `['url' => ...]` dizisini ya da düz string URL'yi
     * değer nesnesine çevirir.
     */
    public static function from(mixed $value): self
    {
        if (is_array($value)) {
            return new self((string) ($value['url'] ?? ''));
        }

        return new self((string) ($value ?? ''));
    }

    public function isVideo(): bool
    {
        return preg_match('/\.(mp4|mov|m4v|webm)(\?|$)/i', $this->url) === 1;
    }

    /**
     * Bu karusel çocuğu için item container payload'ı üretir.
     */
    public function containerPayload(): InstagramContainerPayload
    {
        return $this->isVideo()
            ? new VideoContainerPayload($this->url, isCarouselItem: true)
            : new ImageContainerPayload($this->url, isCarouselItem: true);
    }
}
