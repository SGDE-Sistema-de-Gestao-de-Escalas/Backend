<?php

namespace Database\Factories;

use App\Models\System\ActivityType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityType>
 */
class ActivityTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * O `school_id` tem de ser indicado por quem usa a factory.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'color' => '#6B7280',
            'is_system' => false,
            'active' => true,
        ];
    }
}
