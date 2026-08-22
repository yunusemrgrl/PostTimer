<?php

namespace App\Domain\Instagram\Payload;

use App\Domain\Instagram\Enums\InstagramMediaType;

/**
 * Tek bir medya container'ı için ortak alanları taşıyan readonly taban sınıf.
 *
 * Somut payload'lar (Image/Video/Reel/Story/Carousel) yalnızca kendi
 * türüne özgü alan(lar)ı ekler. Böylece "hem video_url hem image_url
 * tanımlayıp null filtrelemek" gibi kırılgan desen tek bir yerde ve
 * tip-güvenli olarak çözülür.
 */
abstract readonly class InstagramContainerPayload implements InstagramMediaPayload
{
    public function __construct(
        public InstagramMediaType $mediaType,
        public ?string $caption = null,
        public ?string $altText = null,
        public ?bool $isAiGenerated = null,
        public ?string $storyLink = null,
        public bool $isCarouselItem = false,
    ) {}

    /**
     * media_type dışında tüm container tiplerinde ortak olan alanlar.
     * Karusel item'ları is_carousel_item=true ile gönderildiği için
     * media_type gönderilmez (mevcut Meta davranışıyla birebir uyumlu).
     *
     * @return array<string, mixed>
     */
    protected function baseFields(): array
    {
        return array_filter([
            'caption' => $this->caption,
            'alt_text' => $this->altText !== null ? ['text' => $this->altText] : null,
            'is_ai_generated' => $this->isAiGenerated ?: null,
            'media_type' => $this->isCarouselItem ? null : $this->mediaType->value,
            'story_link' => $this->storyLink,
            'is_carousel_item' => $this->isCarouselItem ? true : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
