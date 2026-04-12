<?php

namespace Database\Factories;

use App\Enums\ExceptionType;
use App\Models\AvailabilityException;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvailabilityException>
 */
class AvailabilityExceptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('+1 day', '+60 days');

        return [
            'business_id' => Business::factory(),
            'collaborator_id' => null,
            'start_date' => $date,
            'end_date' => $date,
            'start_time' => null,
            'end_time' => null,
            'type' => ExceptionType::Block,
            'reason' => fake()->sentence(),
        ];
    }

    public function forCollaborator(?User $user = null): static
    {
        return $this->state(fn (array $attributes) => [
            'collaborator_id' => $user ?? User::factory(),
        ]);
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ExceptionType::Open,
        ]);
    }

    public function partialDay(string $startTime = '10:00', string $endTime = '11:00'): static
    {
        return $this->state(fn (array $attributes) => [
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
    }

    public function multiDay(int $days = 3): static
    {
        return $this->state(function (array $attributes) use ($days) {
            $startDate = fake()->dateTimeBetween('+1 day', '+60 days');

            return [
                'start_date' => $startDate,
                'end_date' => (clone $startDate)->modify("+{$days} days"),
            ];
        });
    }
}
