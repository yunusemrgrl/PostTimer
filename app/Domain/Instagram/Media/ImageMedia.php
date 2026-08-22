<?php

namespace App\Domain\Instagram\Media;

use App\Domain\Instagram\AbstractInstagramMedia;
use App\Domain\Instagram\Enums\InstagramMediaType;
use App\Domain\Instagram\Payload\ImageContainerPayload;
use App\Domain\Instagram\Payload\InstagramContainerPayload;

final class ImageMedia extends AbstractInstagramMedia
{
    public function mediaType(): InstagramMediaType
    {
        return InstagramMediaType::Image;
    }

    public function buildContainerPayload(array $childContainerIds = []): InstagramContainerPayload
    {
        $common = $this->commonFields();

        return new ImageContainerPayload(
            imageUrl: (string) $this->post->media_url,
            caption: $common['caption'],
            altText: $common['alt_text'],
            isAiGenerated: $common['is_ai_generated'],
            storyLink: $common['story_link'],
        );
    }
}
