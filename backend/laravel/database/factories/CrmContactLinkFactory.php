<?php

namespace Database\Factories;

use App\Models\CrmContact;
use App\Models\CrmContactLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmContactLink>
 */
class CrmContactLinkFactory extends Factory
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
            'label' => 'Website',
            'kind' => 'link',
            'url' => $this->faker->unique()->url(),
        ];
    }
}
