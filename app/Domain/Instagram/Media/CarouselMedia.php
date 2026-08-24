<?php

namespace App\Domain\Instagram\Media;

use App\Domain\Instagram\AbstractInstagramMedia;
use App\Domain\Instagram\Enums\InstagramMediaType;
use App\Domain\Instagram\Payload\CarouselContainerPayload;
use App\Domain\Instagram\Payload\InstagramContainerPayload;
use RuntimeException;

final class CarouselMedia extends AbstractInstagramMedia
{
    public function isCarousel(): bool
    {
        return true;
    }

    public function mediaType(): InstagramMediaType
    {
        return InstagramMediaType::Carousel;
    }

    /**
     * Carousel album medyaları için insights desteklenmez (Meta:
     * "Insights data is not available for any media within an album").
     *
     * @return array<int, string>
     */
    public function supportedInsightMetrics(): array
    {
        return [];
    }

    /**
     * Karusel çocuklarını normalleştirilmiş value object'ler olarak döner.
     *
     * @return array<int, CarouselChild>
     */
    public function childUrls(): array
    {
        return collect($this->post->getChildren() ?? [])
            ->filter()
            ->map(fn (mixed $child): CarouselChild => CarouselChild::from($child))
            ->values()
            ->all();
    }

    public function buildContainerPayload(array $childContainerIds = []): InstagramContainerPayload
    {
        if ($childContainerIds === []) {
            throw new RuntimeException('Karusel çocuk container ID\'leri olmadan karusel container payload\'ı üretilemez.');
        }

        $common = $this->commonFields();

        return new CarouselContainerPayload(
            children: $childContainerIds,
            caption: $common['caption'],
            isAiGenerated: $common['is_ai_generated'],
        );
    }
}
