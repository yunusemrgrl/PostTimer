<?php

namespace App\Domain\Instagram;

use App\Models\InstagramPost;

/**
 * Somut media türlerinin paylaştığı ortak alanları/model okumalarını
 * toplar. Alt sınıflar yalnızca tipine özgü davranışı (mediaType, bool
 * bayraklar, payload kurulumu) ekler.
 */
abstract class AbstractInstagramMedia implements InstagramMedia
{
    /**
     * FEED (IMAGE/VIDEO) postları için desteklenen insight metric'leri.
     * 'impressions', 2 Temmuz 2024 sonrası oluşturulan medya için
     * deprecated olduğundan istenmez.
     *
     * @var array<int, string>
     */
    protected const FEED_INSIGHT_METRICS = [
        'reach',
        'likes',
        'comments',
        'saved',
        'shares',
        'total_interactions',
        'views',
        'follows',
        'profile_visits',
        'profile_activity',
    ];

    protected readonly InstagramPost $post;

    public function __construct(InstagramPost $post)
    {
        $this->post = $post;
    }

    public function post(): InstagramPost
    {
        return $this->post;
    }

    public function isVideo(): bool
    {
        return false;
    }

    public function isStory(): bool
    {
        return false;
    }

    public function isCarousel(): bool
    {
        return false;
    }

    /**
     * Container payload'larında ortak olan, modelden okunan alanlar.
     *
     * @return array{caption: string|null, alt_text: string|null, is_ai_generated: bool|null, story_link: string|null}
     */
    protected function commonFields(): array
    {
        return [
            'caption' => $this->post->caption,
            'alt_text' => $this->post->alt_text,
            'is_ai_generated' => $this->post->is_ai_generated,
            'story_link' => $this->post->story_link,
        ];
    }
}
