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

    /**
     * Story metric'leri. Story için 'impressions', 'likes', 'comments',
     * 'saved' desteklenmez (Meta Media Insights metrics tablosu: likes/
     * comments/saved yalnızca FEED ve REELS).
     *
     * @return array<int, string>
     */
    public function supportedInsightMetrics(): array
    {
        return [
            'replies',
            'navigation',
            'follows',
            'profile_visits',
            'profile_activity',
            'reach',
            'views',
            'shares',
            'total_interactions',
        ];
    }

    public function buildContainerPayload(array $childContainerIds = []): InstagramContainerPayload
    {
        $common = $this->commonFields();

        $isVideo = $this->post->isVideo();

        return new StoryContainerPayload(
            videoUrl: $isVideo ? (string) $this->post->getMediaUrl() : null,
            imageUrl: $isVideo ? null : (string) $this->post->getMediaUrl(),
            caption: $common['caption'],
            altText: $common['alt_text'],
            isAiGenerated: $common['is_ai_generated'],
            storyLink: $common['story_link'],
        );
    }
}
