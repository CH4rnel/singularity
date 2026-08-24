<?php

namespace Database\Factories;

use App\Models\CrmTask;
use App\Models\CrmTaskComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmTaskComment>
 */
class CrmTaskCommentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'crm_task_id' => CrmTask::factory(),
            'user_id' => User::factory(),
            'body' => $this->faker->paragraph(),
        ];
    }
}
