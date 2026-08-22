<?php

namespace App\Domain\Instagram\Payload;

use App\Domain\Instagram\Enums\InstagramMediaType;

/**
 * Reels için container payload'ı. Reels her zaman video olduğundan
 * media_type=REELS + video_url gönderir (story_link gönderilmez).
 */
final readonly class ReelContainerPayload extends InstagramContainerPayload
{
    public function __construct(
        public string $videoUrl,
        ?string $caption = null,
        ?string $altText = null,
        ?bool $isAiGenerated = null,
    ) {
        parent::__construct(
            mediaType: InstagramMediaType::Reels,
            caption: $caption,
            altText: $altText,
            isAiGenerated: $isAiGenerated,
        );
    }

    public function toPayload(): array
    {
        return array_merge($this->baseFields(), [
            'video_url' => $this->videoUrl,
        ]);
    }
}
