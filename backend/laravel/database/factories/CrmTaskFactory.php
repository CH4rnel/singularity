<?php

namespace Database\Factories;

use App\Models\CrmContact;
use App\Models\CrmTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmTask>
 */
class CrmTaskFactory extends Factory
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
            'assigned_to_user_id' => null,
            'created_by_user_id' => null,
            'title' => $this->faker->sentence(4),
            'description' => null,
            'status' => 'open',
            'priority' => 'normal',
            'due_at' => null,
        ];
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_to_user_id' => $user->id,
        ]);
    }

    /** A task with no contact behind it — a standalone operator chore. */
    public function standalone(): static
    {
        return $this->state(fn (array $attributes) => [
            'crm_contact_id' => null,
        ]);
    }

    public function done(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'done',
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'open',
            'due_at' => now()->subDays(2),
        ]);
    }
}
