<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\Dao;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    protected $model = Activity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'proposal.created',
            'user_id' => User::factory(),
            'dao_id' => Dao::factory(),
            'subject_type' => Proposal::class,
            'subject_id' => Proposal::factory(),
        ];
    }
}
