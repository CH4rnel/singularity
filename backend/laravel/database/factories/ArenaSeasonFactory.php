<?php

namespace Database\Factories;

use App\Models\ArenaSeason;
use App\Services\Arena\ArenaSeasonManager;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ArenaSeason> */
class ArenaSeasonFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $manager = app(ArenaSeasonManager::class);
        $rules = $manager->rules();

        return ['slug' => fake()->unique()->slug(2), 'title' => 'Season Zero', 'starts_at' => null, 'ends_at' => null, 'rules' => $rules, 'rules_hash' => $manager->digest($rules)];
    }
}
