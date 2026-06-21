<?php

namespace Database\Factories;

use App\Models\CrmContact;
use App\Models\CrmNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmNote>
 */
class CrmNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'crm_contact_id' => CrmContact::factory(),
            'user_id' => User::factory(),
            'type' => 'note',
            'body' => $this->faker->sentence(),
        ];
    }
}
