<?php

namespace App\Domain\Instagram\Payload;

use App\Domain\Instagram\Enums\InstagramMediaType;

/**
 * Normal / karusel item video için container payload'ı.
 * media_type=VIDEO + video_url gönderir.
 */
final readonly class VideoContainerPayload extends InstagramContainerPayload
{
    public function __construct(
        public string $videoUrl,
        ?string $caption = null,
        ?string $altText = null,
        ?bool $isAiGenerated = null,
        ?string $storyLink = null,
        bool $isCarouselItem = false,
    ) {
        parent::__construct(
            mediaType: InstagramMediaType::Video,
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
            'video_url' => $this->videoUrl,
        ]);
    }
}
