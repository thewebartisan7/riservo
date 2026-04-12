<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\BusinessHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessHour>
 */
class BusinessHourFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'day_of_week' => fake()->numberBetween(1, 7),
            'open_time' => '09:00',
            'close_time' => '18:00',
        ];
    }

    public function morning(): static
    {
        return $this->state(fn (array $attributes) => [
            'open_time' => '09:00',
            'close_time' => '13:00',
        ]);
    }

    public function afternoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'open_time' => '14:00',
            'close_time' => '18:00',
        ]);
    }
}
