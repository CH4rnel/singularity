<?php

namespace Database\Factories;

use App\Models\BoardThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardThread>
 */
class BoardThreadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'board' => 'general', 'title' => fake()->sentence(), 'bumped_at' => now(),
        ];
    }
}
