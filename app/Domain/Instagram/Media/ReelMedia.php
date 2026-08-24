<?php

namespace App\Domain\Instagram\Media;

use App\Domain\Instagram\AbstractInstagramMedia;
use App\Domain\Instagram\Enums\InstagramMediaType;
use App\Domain\Instagram\Payload\InstagramContainerPayload;
use App\Domain\Instagram\Payload\ReelContainerPayload;

final class ReelMedia extends AbstractInstagramMedia
{
    public function isVideo(): bool
    {
        return true;
    }

    public function mediaType(): InstagramMediaType
    {
        return InstagramMediaType::Reels;
    }

    public function supportedInsightMetrics(): array
    {
        return [
            'reach',
            'likes',
            'comments',
            'saved',
            'shares',
            'total_interactions',
            'views',
            'ig_reels_video_view_total_time',
            'ig_reels_avg_watch_time',
        ];
    }

    public function buildContainerPayload(array $childContainerIds = []): InstagramContainerPayload
    {
        $common = $this->commonFields();

        return new ReelContainerPayload(
            videoUrl: (string) $this->post->getMediaUrl(),
            caption: $common['caption'],
            altText: $common['alt_text'],
            isAiGenerated: $common['is_ai_generated'],
        );
    }
}
