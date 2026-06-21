<?php

namespace Database\Factories;

use App\Models\CrmContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmContact>
 */
class CrmContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'telegram' => '@'.$this->faker->userName(),
            'evm_address' => '0x'.$this->faker->regexify('[0-9a-f]{40}'),
            'solana_address' => $this->faker->regexify('[1-9A-HJ-NP-Za-km-z]{43}'),
            'type' => 'lead',
            'status' => 'new',
            'source' => 'manual',
            'user_id' => null,
            'cyber_balance' => null,
            'cyber_sol_balance' => null,
            'tags' => [],
            'metadata' => [],
            'last_synced_at' => null,
        ];
    }

    public function holder(): static
    {
        return $this->state(fn () => ['type' => 'holder', 'source' => 'platform']);
    }

    public function whale(): static
    {
        return $this->state(fn () => [
            'type' => 'whale',
            'source' => 'whale_bot',
            'cyber_sol_balance' => $this->faker->numberBetween(10_000_000, 50_000_000),
        ]);
    }
}
