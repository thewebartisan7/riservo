<?php

namespace Database\Factories;

use App\Models\AvailabilityRule;
use App\Models\Business;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvailabilityRule>
 */
class AvailabilityRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'business_id' => Business::factory(),
            'day_of_week' => fake()->numberBetween(1, 5),
            'start_time' => '09:00',
            'end_time' => '17:00',
        ];
    }
}
