<?php

namespace Database\Factories;

use App\Models\Content;
use App\Models\InstagramAccount;
use App\Models\Publication;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Publication>
 */
class PublicationFactory extends Factory
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
            'content_id' => Content::factory(),
            'instagram_account_id' => InstagramAccount::factory(),
            'ig_user_id' => (string) fake()->randomNumber(8),
            'status' => Publication::STATUS_DRAFT,
        ];
    }

    /**
     * Geleceğe zamanlanmış yayın.
     */
    public function scheduled(): static
    {
        return $this->state(fn () => [
            'status' => Publication::STATUS_SCHEDULED,
            'scheduled_at' => now()->addHours(2),
        ]);
    }

    /**
     * Zamanı gelmiş (yayınlanmayı bekleyen) yayın.
     */
    public function due(): static
    {
        return $this->state(fn () => [
            'status' => Publication::STATUS_SCHEDULED,
            'scheduled_at' => now()->subMinutes(5),
        ]);
    }

    /**
     * Yayınlanmış yayın.
     */
    public function published(): static
    {
        return $this->state(fn () => [
            'status' => Publication::STATUS_PUBLISHED,
            'container_id' => (string) fake()->randomNumber(8),
            'media_id' => (string) fake()->randomNumber(8),
            'permalink' => 'https://www.instagram.com/p/'.fake()->regexify('[A-Za-z]{10}').'/',
            'ig_media_timestamp' => now(),
            'published_at' => now(),
        ]);
    }

    /**
     * Yayınlanamamış (hatalı) yayın.
     */
    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => Publication::STATUS_FAILED,
            'error_message' => fake()->sentence(),
        ]);
    }

    /**
     * Stok uyarısıyla durdurulmuş yayın.
     */
    public function flagged(): static
    {
        return $this->state(fn () => [
            'status' => Publication::STATUS_FLAGGED,
            'error_message' => fake()->sentence(),
        ]);
    }

    /**
     * İptal edilmiş yayın.
     */
    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => Publication::STATUS_CANCELLED,
        ]);
    }
}
