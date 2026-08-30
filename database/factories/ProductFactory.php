<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $asin = strtoupper(fake()->bothify('B0????????'));

        return [
            'team_id' => Team::factory(),
            'platform' => Product::PLATFORM_AMAZON,
            'asin' => $asin,
            'url' => "https://www.amazon.com.tr/dp/{$asin}",
            'title' => fake()->sentence(4),
            'image_url' => fake()->imageUrl(200, 200),
            'category' => fake()->optional()->word(),
        ];
    }
}
