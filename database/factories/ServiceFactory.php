<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Haircut', 'Beard Trim', 'Color Treatment', 'Blowout', 'Consultation']);

        return [
            'business_id' => Business::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'description' => fake()->sentence(),
            'duration_minutes' => fake()->randomElement([30, 45, 60, 90]),
            'price' => fake()->randomFloat(2, 20, 150),
            'buffer_before' => 0,
            'buffer_after' => fake()->randomElement([0, 5, 10]),
            'slot_interval_minutes' => 15,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function onRequest(): static
    {
        return $this->state(fn (array $attributes) => [
            'price' => null,
        ]);
    }

    public function free(): static
    {
        return $this->state(fn (array $attributes) => [
            'price' => 0,
        ]);
    }
}
