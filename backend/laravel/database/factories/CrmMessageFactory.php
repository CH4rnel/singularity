<?php

namespace Database\Factories;

use App\Models\CrmContact;
use App\Models\CrmMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmMessage>
 */
class CrmMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'crm_contact_id' => CrmContact::factory(),
            'user_id' => User::factory(),
            'direction' => 'out',
            'channel' => 'telegram',
            'body' => $this->faker->sentence(),
            'sent_at' => now(),
        ];
    }

    /** Their side of it: written by them, so no operator behind the row. */
    public function inbound(): static
    {
        return $this->state(fn () => ['direction' => 'in', 'user_id' => null]);
    }
}
