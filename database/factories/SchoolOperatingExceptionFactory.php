<?php

namespace Database\Factories;

use App\Models\Schools\SchoolOperatingException;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolOperatingException>
 */
class SchoolOperatingExceptionFactory extends Factory
{
    protected $model = SchoolOperatingException::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Exceção '.fake()->unique()->word(),
            'is_closed' => false,
            'opening_time' => '09:00',
            'closing_time' => '13:00',
            'lunch_start' => '11:00',
            'lunch_end' => '12:00',
            'lunch_duration_minutes' => 30,
            'valid_from' => today()->addDays(10)->toDateString(),
            'valid_until' => today()->addDays(12)->toDateString(),
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'is_closed' => true,
            'opening_time' => null,
            'closing_time' => null,
            'lunch_start' => null,
            'lunch_end' => null,
            'lunch_duration_minutes' => null,
        ]);
    }
}
