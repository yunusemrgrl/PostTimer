<?php

namespace App\Domain\Instagram\Enums;

/**
 * InstagramPost::media_product_type kolonunda saklanan Meta ürün türleri.
 * Factory mapping'i ve ürün bazlı davranışları yönlendirmek için kullanılır.
 */
enum InstagramProductType: string
{
    case Feed = 'FEED';

    case Reels = 'REELS';

    case Story = 'STORY';
}
