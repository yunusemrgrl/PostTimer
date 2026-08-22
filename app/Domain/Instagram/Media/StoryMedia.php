<?php

namespace App\Domain\Instagram\Media;

use App\Domain\Instagram\AbstractInstagramMedia;
use App\Domain\Instagram\Enums\InstagramMediaType;
use App\Domain\Instagram\Payload\InstagramContainerPayload;
use App\Domain\Instagram\Payload\StoryContainerPayload;

/**
 * Story hem video hem görsel olabilir. `isVideo()` postun gerçek medya
 * içeriğine göre döner; payload da buna göre yalnızca video_url veya
 * image_url taşır (Story + Reel ikilisinin ikisi de video kullanabilmesi
 * burada, Story'nin de video alanı taşımasıyla doğru modellenir).
 */
final class StoryMedia extends AbstractInstagramMedia
{
    public function isStory(): bool
    {
        return true;
    }

    public function isVideo(): bool
    {
        return $this->post->isVideo();
    }

    public function mediaType(): InstagramMediaType
    {
        return InstagramMediaType::Stories;
    }

    public function buildContainerPayload(array $childContainerIds = []): InstagramContainerPayload
    {
        $common = $this->commonFields();

        $isVideo = $this->post->isVideo();

        return new StoryContainerPayload(
            videoUrl: $isVideo ? (string) $this->post->media_url : null,
            imageUrl: $isVideo ? null : (string) $this->post->media_url,
            caption: $common['caption'],
            altText: $common['alt_text'],
            isAiGenerated: $common['is_ai_generated'],
            storyLink: $common['story_link'],
        );
    }
}
