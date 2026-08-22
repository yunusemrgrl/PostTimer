<?php

namespace App\Domain\Instagram\Payload;

use App\Domain\Instagram\Enums\InstagramMediaType;

/**
 * Story için container payload'ı. Story hem video hem görsel olabilir;
 * bu yüzden hem `videoUrl` hem `imageUrl` alanı taşır — hangisi doluysa
 * o gönderilir, null olan paylaşımda elenir. story_link de burada taşınır.
 */
final readonly class StoryContainerPayload extends InstagramContainerPayload
{
    public function __construct(
        public ?string $videoUrl = null,
        public ?string $imageUrl = null,
        ?string $caption = null,
        ?string $altText = null,
        ?bool $isAiGenerated = null,
        ?string $storyLink = null,
    ) {
        parent::__construct(
            mediaType: InstagramMediaType::Stories,
            caption: $caption,
            altText: $altText,
            isAiGenerated: $isAiGenerated,
            storyLink: $storyLink,
        );
    }

    public function toPayload(): array
    {
        return array_merge($this->baseFields(), array_filter([
            'video_url' => $this->videoUrl,
            'image_url' => $this->imageUrl,
        ], static fn (mixed $value): bool => $value !== null));
    }
}
