<?php

namespace Database\Factories;

use App\Models\Content;
use App\Models\Product;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Content>
 */
class ContentFactory extends Factory
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
            'product_id' => fn (array $attributes) => Product::factory()->create(['team_id' => $attributes['team_id']])->id,
            'type' => Content::TYPE_IMAGE,
            'surface' => Content::SURFACE_FEED,
            'caption' => fake()->sentence(),
            'media_url' => 'https://example.com/images/'.Str::random(8).'.jpg',
            'first_comment' => null,
            'is_ai_generated' => false,
        ];
    }

    /**
     * Reels (video + REELS yüzeyi) içeriği.
     */
    public function reels(): static
    {
        return $this->state(fn () => [
            'type' => Content::TYPE_VIDEO,
            'surface' => Content::SURFACE_REELS,
            'media_url' => 'https://example.com/videos/'.Str::random(8).'.mp4',
        ]);
    }

    /**
     * Story içeriği.
     */
    public function story(): static
    {
        return $this->state(fn () => [
            'type' => Content::TYPE_IMAGE,
            'surface' => Content::SURFACE_STORY,
            'story_link' => 'https://www.amazon.com.tr/dp/'.strtoupper(fake()->bothify('B0????????')),
        ]);
    }

    /**
     * Karusel içeriği.
     */
    public function carousel(): static
    {
        return $this->state(fn () => [
            'type' => Content::TYPE_CAROUSEL_ALBUM,
            'surface' => Content::SURFACE_FEED,
            'media_url' => null,
            'children' => [
                ['url' => 'https://example.com/images/'.Str::random(8).'.jpg'],
                ['url' => 'https://example.com/images/'.Str::random(8).'.jpg'],
            ],
        ]);
    }

    /**
     * Ürünsüz içerik.
     */
    public function withoutProduct(): static
    {
        return $this->state(fn () => [
            'product_id' => null,
        ]);
    }
}
