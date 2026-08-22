<?php

namespace App\Domain\Instagram;

use App\Domain\Instagram\Enums\InstagramMediaType;
use App\Domain\Instagram\Payload\InstagramContainerPayload;

/**
 * Bir Instagram medyasının (postun) domain sözleşmesi.
 *
 * Concrete media türleri (Image/Video/Reel/Story/Carousel) bu arayüzü
 * uygular; her biri kendi Meta publishing media_type'ı ve kendi tipine
 * özel typed container payload DTO'sunu üretir.
 */
interface InstagramMedia
{
    public function mediaType(): InstagramMediaType;

    /**
     * Medya video (yayın öncesi container finish beklenmeli) mi?
     */
    public function isVideo(): bool;

    public function isStory(): bool;

    public function isCarousel(): bool;

    /**
     * Bu medya tipi için Meta Insights endpoint'inden çekilebilecek
     * metric listesi. Carousel album gibi insights desteklenmeyen
     * türler boş dizi döndürür.
     *
     * @return array<int, string>
     */
    public function supportedInsightMetrics(): array;

    /**
     * Meta container oluşturma için tip-güvenli payload üretir.
     *
     * @param  array<int, string>  $childContainerIds  Yalnızca karusel için
     *                                                 gereklidir; diğer türlerde boş kalır.
     */
    public function buildContainerPayload(array $childContainerIds = []): InstagramContainerPayload;
}
