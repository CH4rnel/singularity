<?php

namespace Database\Factories;

use App\Models\ArenaRound;
use App\Models\ArenaSeason;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ArenaRound> */
class ArenaRoundFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'arena_season_id' => ArenaSeason::factory(), 'sequence' => 1,
            'decision_at' => now()->subMinutes(2), 'filled_at' => now()->subMinute(),
            'evidence' => ['fixture' => true], 'result' => [],
            'payload_hash' => str_repeat('1', 64), 'previous_hash' => null, 'record_hash' => str_repeat('2', 64),
        ];
    }
}
