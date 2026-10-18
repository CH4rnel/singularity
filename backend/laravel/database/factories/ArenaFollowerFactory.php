<?php

namespace Database\Factories;

use App\Models\ArenaFollower;
use App\Models\ArenaSeason;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ArenaFollower> */
class ArenaFollowerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['arena_season_id' => ArenaSeason::factory(), 'user_id' => User::factory()];
    }
}
