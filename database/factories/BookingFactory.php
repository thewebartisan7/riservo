<?php

namespace Database\Factories;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('+1 day', '+30 days');
        $duration = fake()->randomElement([30, 45, 60]);

        return [
            'business_id' => Business::factory(),
            'collaborator_id' => User::factory(),
            'service_id' => Service::factory(),
            'customer_id' => Customer::factory(),
            'starts_at' => $startsAt,
            'ends_at' => Carbon::instance($startsAt)->addMinutes($duration),
            'status' => BookingStatus::Confirmed,
            'source' => BookingSource::Riservo,
            'payment_status' => PaymentStatus::Pending,
            'cancellation_token' => Str::uuid()->toString(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Pending,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Confirmed,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Cancelled,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Completed,
        ]);
    }

    public function noShow(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::NoShow,
        ]);
    }

    public function past(): static
    {
        return $this->state(function (array $attributes) {
            $startsAt = fake()->dateTimeBetween('-30 days', '-1 day');
            $duration = fake()->randomElement([30, 45, 60]);

            return [
                'starts_at' => $startsAt,
                'ends_at' => Carbon::instance($startsAt)->addMinutes($duration),
            ];
        });
    }

    public function future(): static
    {
        return $this->state(function (array $attributes) {
            $startsAt = fake()->dateTimeBetween('+1 day', '+30 days');
            $duration = fake()->randomElement([30, 45, 60]);

            return [
                'starts_at' => $startsAt,
                'ends_at' => Carbon::instance($startsAt)->addMinutes($duration),
            ];
        });
    }

    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => BookingSource::Manual,
        ]);
    }
}
