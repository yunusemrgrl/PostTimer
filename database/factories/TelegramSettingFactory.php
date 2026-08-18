<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\TelegramSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramSetting>
 */
class TelegramSettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'bot_token' => fake()->randomNumber(9).':'.fake()->sha1(),
            'webhook_secret' => fake()->randomNumber(6).fake()->randomNumber(6),
            'chat_id' => fake()->randomNumber(8),
            'is_verified' => true,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'chat_id' => null,
            'is_verified' => false,
            'verification_code' => fake()->randomNumber(6),
        ]);
    }
}
