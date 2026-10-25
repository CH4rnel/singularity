<?php

namespace Database\Factories;

use App\Models\FujiFaucetClaim;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FujiFaucetClaim>
 */
class FujiFaucetClaimFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'address' => '0x'.bin2hex(random_bytes(20)),
            'amount_wei' => '10000000000000000000000',
            'tx_hash' => '0x'.bin2hex(random_bytes(32)),
            'ip_hash' => hash('sha256', fake()->ipv4()),
        ];
    }
}
