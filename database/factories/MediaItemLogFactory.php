<?php

namespace Database\Factories;

use App\Models\MediaItemLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaItemLog>
 */
class MediaItemLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event' => fake()->randomElement(['created', 'updated', 'requested']),
            'message' => fake()->sentence(),
            'context' => [],
        ];
    }
}
