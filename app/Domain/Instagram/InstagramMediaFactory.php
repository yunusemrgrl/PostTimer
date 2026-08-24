<?php

namespace App\Domain\Instagram;

use App\Domain\Instagram\Media\CarouselMedia;
use App\Domain\Instagram\Media\ImageMedia;
use App\Domain\Instagram\Media\ReelMedia;
use App\Domain\Instagram\Media\StoryMedia;
use App\Domain\Instagram\Media\VideoMedia;
use App\Models\InstagramPost;

/**
 * İki eksenli (media_type + media_product_type / type + surface) girdiyi
 * somut bir domain medya nesnesine eşler. Kaynak model InstagramPost
 * olabileceği gibi Content de olabilir — ikisi de HasPublishableMedia
 * sözleşmesini uygular. Mevcut model davranışları (isCarousel, product
 * türleri) korunarak kullanılır — string karşılaştırmaları burada teke
 * indirilir (InstagramPost ve Content sabitleri aynı Meta değerlerini
 * taşır).
 */
class InstagramMediaFactory
{
    /**
     * Stateless olduğundan container üzerinden çözülen tek bir örnek
     * yeterlidir (servis ve testlerde doğrudan erişim için kolaylık).
     */
    public static function instance(): self
    {
        return app(self::class);
    }

    public function make(HasPublishableMedia $source): InstagramMedia
    {
        if ($source->isCarousel()) {
            return new CarouselMedia($source);
        }

        $product = $source->getMediaProductType();
        $mediaType = $source->getMediaType();

        // STORY → media_type=STORIES
        if ($product === InstagramPost::PRODUCT_TYPE_STORY || $mediaType === InstagramPost::MEDIA_TYPE_STORIES) {
            return new StoryMedia($source);
        }

        // REELS → media_type=REELS
        if ($product === InstagramPost::PRODUCT_TYPE_REELS || $mediaType === InstagramPost::MEDIA_TYPE_REELS) {
            return new ReelMedia($source);
        }

        // FEED video → media_type=VIDEO
        if ($mediaType === InstagramPost::MEDIA_TYPE_VIDEO) {
            return new VideoMedia($source);
        }

        // IMAGE + FEED (ve bilinmeyen varsayılan) → media_type=IMAGE
        return new ImageMedia($source);
    }
}
