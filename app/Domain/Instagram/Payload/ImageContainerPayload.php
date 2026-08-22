<?php

namespace App\Domain\Instagram\Payload;

use App\Domain\Instagram\Enums\InstagramMediaType;

/**
 * Normal / karusel item görseli için container payload'ı.
 * media_type=IMAGE + image_url gönderir.
 */
final readonly class ImageContainerPayload extends InstagramContainerPayload
{
    public function __construct(
        public string $imageUrl,
        ?string $caption = null,
        ?string $altText = null,
        ?bool $isAiGenerated = null,
        ?string $storyLink = null,
        bool $isCarouselItem = false,
    ) {
        parent::__construct(
            mediaType: InstagramMediaType::Image,
            caption: $caption,
            altText: $altText,
            isAiGenerated: $isAiGenerated,
            storyLink: $storyLink,
            isCarouselItem: $isCarouselItem,
        );
    }

    public function toPayload(): array
    {
        return array_merge($this->baseFields(), [
            'image_url' => $this->imageUrl,
        ]);
    }
}
