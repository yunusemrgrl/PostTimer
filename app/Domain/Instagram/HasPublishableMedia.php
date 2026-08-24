<?php

namespace App\Domain\Instagram;

/**
 * Publishable medya kaynaklarının (şu an InstagramPost, ileride Content)
 * ortak sözleşmesi. InstagramMediaFactory ve somut media sınıfları yalnızca
 * bu sözleşme üzerinden çalışır; kaynak modelin geri kalanından bağımsızdır.
 *
 * Metod isimleri kasıtlı olarak getter desenindedir — Eloquent attribute
 * erişimiyle (örn. $post->caption) karışmaması için.
 */
interface HasPublishableMedia
{
    /**
     * İçerik formatı: IMAGE | VIDEO | CAROUSEL_ALBUM (Meta media_type değerleri).
     */
    public function getMediaType(): string;

    /**
     * Yayın yüzü: FEED | REELS | STORY (Meta media_product_type değerleri).
     */
    public function getMediaProductType(): ?string;

    public function isCarousel(): bool;

    /**
     * Medyanın gerçek içeriği video mu? (Story image/video olabilir.)
     */
    public function isVideo(): bool;

    public function getCaption(): ?string;

    public function getAltText(): ?string;

    public function getMediaUrl(): ?string;

    public function getStoryLink(): ?string;

    /**
     * Carousel çocuk medya listesi (children kolon formatıyla uyumlu).
     *
     * @return array<int, mixed>|null
     */
    public function getChildren(): ?array;

    public function isAiGenerated(): bool;
}
