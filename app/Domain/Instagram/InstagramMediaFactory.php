<?php

namespace App\Domain\Instagram;

use App\Domain\Instagram\Media\CarouselMedia;
use App\Domain\Instagram\Media\ImageMedia;
use App\Domain\Instagram\Media\ReelMedia;
use App\Domain\Instagram\Media\StoryMedia;
use App\Domain\Instagram\Media\VideoMedia;
use App\Models\InstagramPost;

/**
 * DB'deki iki eksenli (media_type + media_product_type) girdiyi somut bir
 * domain medya nesnesine eşler. Mevcut model davranışları (isCarousel,
 * product türleri) korunarak kullanılır — yeni kod string karşılaştırmaları
 * burada teke indirir.
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

    public function make(InstagramPost $post): InstagramMedia
    {
        if ($post->isCarousel()) {
            return new CarouselMedia($post);
        }

        $product = $post->media_product_type;
        $mediaType = $post->media_type;

        // STORY → media_type=STORIES
        if ($product === InstagramPost::PRODUCT_TYPE_STORY || $mediaType === InstagramPost::MEDIA_TYPE_STORIES) {
            return new StoryMedia($post);
        }

        // REELS → media_type=REELS
        if ($product === InstagramPost::PRODUCT_TYPE_REELS || $mediaType === InstagramPost::MEDIA_TYPE_REELS) {
            return new ReelMedia($post);
        }

        // FEED video → media_type=VIDEO
        if ($mediaType === InstagramPost::MEDIA_TYPE_VIDEO) {
            return new VideoMedia($post);
        }

        // IMAGE + FEED (ve bilinmeyen varsayılan) → media_type=IMAGE
        return new ImageMedia($post);
    }
}
