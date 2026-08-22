<?php

namespace App\Domain\Instagram\Payload;

use App\Domain\Instagram\Enums\InstagramMediaType;

/**
 * Karusel için container payload'ı. Çocuk container ID'lerinin virgülle
 * birleştirilmiş halini `children` olarak gönderir.
 */
final readonly class CarouselContainerPayload extends InstagramContainerPayload
{
    /**
     * @param  array<int, string>  $children  Karusel item container ID'leri
     */
    public function __construct(
        public array $children,
        ?string $caption = null,
        ?bool $isAiGenerated = null,
    ) {
        parent::__construct(
            mediaType: InstagramMediaType::Carousel,
            caption: $caption,
            isAiGenerated: $isAiGenerated,
        );
    }

    public function toPayload(): array
    {
        return array_filter([
            'caption' => $this->caption,
            'is_ai_generated' => $this->isAiGenerated ?: null,
            'media_type' => $this->mediaType->value,
            'children' => implode(',', $this->children),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
