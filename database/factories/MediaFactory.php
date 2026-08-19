<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class MediaFactory extends Factory
{
    protected $model = Media::class;

    public function definition(): array
    {
        return [
            'disk' => 'public',
            'directory' => 'media',
            'name' => fake()->word(),
            'path' => fake()->uuid().'.png',
            'ext' => 'png',
            'type' => 'image/png',
            'size' => 1024,
            'width' => 100,
            'height' => 100,
            'team_id' => Team::factory(),
        ];
    }
}
