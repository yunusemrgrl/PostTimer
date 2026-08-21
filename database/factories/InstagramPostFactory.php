<?php

namespace Database\Factories;

use App\Models\InstagramPost;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InstagramPost>
 */
class InstagramPostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'ig_user_id' => (string) fake()->randomNumber(8),
            'media_type' => InstagramPost::MEDIA_TYPE_IMAGE,
            'media_product_type' => InstagramPost::PRODUCT_TYPE_FEED,
            'caption' => fake()->sentence(),
            'media_url' => 'https://example.com/images/'.Str::random(8).'.jpg',
            'story_link' => null,
            'first_comment' => null,
            'is_ai_generated' => false,
            'product_id' => null,
            'status' => InstagramPost::STATUS_DRAFT,
        ];
    }

    /**
     * Geleceğe zamanlanmış bir gönderi.
     */
    public function scheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InstagramPost::STATUS_SCHEDULED,
            'scheduled_at' => now()->addHours(2),
        ]);
    }

    /**
     * Zamanı gelmiş (yayınlanmayı bekleyen) bir gönderi.
     */
    public function due(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InstagramPost::STATUS_SCHEDULED,
            'scheduled_at' => now()->subMinutes(5),
        ]);
    }

    /**
     * Yayınlanmış bir gönderi.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InstagramPost::STATUS_PUBLISHED,
            'container_id' => (string) fake()->randomNumber(8),
            'media_id' => (string) fake()->randomNumber(8),
            'permalink' => 'https://www.instagram.com/p/'.Str::random(10).'/',
            'ig_media_timestamp' => now(),
            'published_at' => now(),
        ]);
    }

    /**
     * Reels olarak yayınlanacak bir video gönderisi.
     */
    public function reels(): static
    {
        return $this->state(fn (array $attributes) => [
            'media_type' => InstagramPost::MEDIA_TYPE_VIDEO,
            'media_product_type' => InstagramPost::PRODUCT_TYPE_REELS,
            'media_url' => 'https://example.com/videos/'.Str::random(8).'.mp4',
        ]);
    }

    /**
     * Story olarak yayınlanacak bir gönderi.
     */
    public function story(): static
    {
        return $this->state(fn (array $attributes) => [
            'media_type' => InstagramPost::MEDIA_TYPE_IMAGE,
            'media_product_type' => InstagramPost::PRODUCT_TYPE_STORY,
            'media_url' => 'https://example.com/images/'.Str::random(8).'.jpg',
        ]);
    }

    /**
     * Carousel olarak yayınlanacak bir gönderi.
     */
    public function carousel(): static
    {
        return $this->state(fn (array $attributes) => [
            'media_type' => InstagramPost::MEDIA_TYPE_CAROUSEL_ALBUM,
            'media_product_type' => InstagramPost::PRODUCT_TYPE_FEED,
            'media_url' => null,
            'children' => [
                ['url' => 'https://example.com/images/'.Str::random(8).'.jpg'],
                ['url' => 'https://example.com/images/'.Str::random(8).'.jpg'],
            ],
        ]);
    }

    /**
     * Yayınlanamamış (hatalı) bir gönderi.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InstagramPost::STATUS_FAILED,
            'error_message' => fake()->sentence(),
        ]);
    }
}
