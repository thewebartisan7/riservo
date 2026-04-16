<?php

namespace Database\Factories;

use App\Enums\AssignmentStrategy;
use App\Enums\ConfirmationMode;
use App\Enums\PaymentMode;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'description' => fake()->paragraph(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'address' => fake()->address(),
            'timezone' => 'Europe/Zurich',
            'payment_mode' => PaymentMode::Offline,
            'confirmation_mode' => ConfirmationMode::Auto,
            'allow_provider_choice' => true,
            'cancellation_window_hours' => 24,
            'assignment_strategy' => AssignmentStrategy::FirstAvailable,
            'reminder_hours' => [24, 1],
        ];
    }

    public function manualConfirmation(): static
    {
        return $this->state(fn (array $attributes) => [
            'confirmation_mode' => ConfirmationMode::Manual,
        ]);
    }

    public function noProviderChoice(): static
    {
        return $this->state(fn (array $attributes) => [
            'allow_provider_choice' => false,
        ]);
    }

    public function onboarded(): static
    {
        return $this->state(fn (array $attributes) => [
            'onboarding_step' => 5,
            'onboarding_completed_at' => now(),
        ]);
    }
}
