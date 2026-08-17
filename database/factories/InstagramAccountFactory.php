<?php

namespace Database\Factories;

use App\Models\InstagramAccount;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstagramAccount>
 */
class InstagramAccountFactory extends Factory
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
            'access_token' => 'account-token',
            'api_host' => 'graph.instagram.com',
            'username' => fake()->userName(),
            'name' => fake()->company(),
            'account_type' => InstagramAccount::TYPE_BUSINESS,
            'followers_count' => fake()->numberBetween(100, 50000),
            'media_count' => fake()->numberBetween(10, 500),
        ];
    }

    /**
     * Belirli bir erişim jetonuyla hesap.
     */
    public function withToken(?string $token = 'account-token'): static
    {
        return $this->state(fn (array $attributes) => [
            'access_token' => $token,
        ]);
    }

    /**
     * Jetonsuz (henüz bağlanmamış) hesap.
     */
    public function withoutToken(): static
    {
        return $this->state(fn (array $attributes) => [
            'access_token' => null,
        ]);
    }
}
