<?php

namespace Database\Factories;

use App\Models\ArenaCycle;
use App\Models\ArenaSeason;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ArenaCycle> */
class ArenaCycleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'arena_season_id' => ArenaSeason::factory(), 'slot' => 0,
            'decision_at' => now()->addMinutes(3), 'fill_due_at' => now()->addMinutes(4),
            'status' => 'collecting', 'record' => [],
        ];
    }
}
