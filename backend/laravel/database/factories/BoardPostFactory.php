<?php

namespace Database\Factories;

use App\Models\BoardPost;
use App\Models\BoardThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BoardPost>
 */
class BoardPostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'board_thread_id' => BoardThread::factory(), 'user_id' => User::factory(),
            'uuid' => (string) Str::uuid(), 'author' => 'Anon #12345678', 'body' => fake()->sentence(),
            'content_hash' => str_repeat('a', 64),
        ];
    }
}
