<?php

namespace Database\Factories;

use App\Models\System\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\Auth\User::factory(),
            'school_id' => null,
            'type' => 'info',
            'event' => 'schedule.generated',
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(),
            'data' => null,
            'read_at' => null,
        ];
    }
}
