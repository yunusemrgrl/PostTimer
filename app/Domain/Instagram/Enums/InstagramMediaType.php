<?php

namespace App\Domain\Instagram\Enums;

/**
 * Meta'nın publishing endpoint'ine gönderilen `media_type` değerleri.
 * DB'deki ham (raw) `media_type`/`media_product_type` girdileri
 * InstagramMediaFactory içinde bu enum'a map'lenir.
 */
enum InstagramMediaType: string
{
    case Image = 'IMAGE';

    case Video = 'VIDEO';

    case Reels = 'REELS';

    case Stories = 'STORIES';

    case Carousel = 'CAROUSEL';
}
