<?php

namespace Database\Factories;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Service;
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
            'provider_id' => Provider::factory(),
            'service_id' => Service::factory(),
            'customer_id' => Customer::factory(),
            'starts_at' => $startsAt,
            'ends_at' => Carbon::instance($startsAt)->addMinutes($duration),
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'status' => BookingStatus::Confirmed,
            'source' => BookingSource::Riservo,
            // PAYMENTS Session 2a: the pre-existing `Pending` case was retired
            // per the roadmap's "no production data migration concerns" clause.
            // The factory's default shape (source=riservo, confirmed, no Stripe
            // session) matches an offline-from-the-start booking, which is the
            // intent of `NotApplicable`. Tests that need a paid/awaiting-payment
            // row opt in via the dedicated states below.
            'payment_status' => PaymentStatus::NotApplicable,
            'payment_mode_at_creation' => 'offline',
            'cancellation_token' => Str::uuid()->toString(),
        ];
    }

    public function awaitingPayment(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Pending,
            'payment_status' => PaymentStatus::AwaitingPayment,
            'payment_mode_at_creation' => 'online',
            'stripe_checkout_session_id' => 'cs_test_'.Str::random(16),
            'stripe_connected_account_id' => 'acct_test_'.Str::random(12),
            'paid_amount_cents' => 5000,
            'currency' => 'chf',
            'expires_at' => Carbon::now()->addMinutes(90),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'payment_mode_at_creation' => 'online',
            'stripe_checkout_session_id' => 'cs_test_'.Str::random(16),
            'stripe_payment_intent_id' => 'pi_test_'.Str::random(16),
            'stripe_connected_account_id' => 'acct_test_'.Str::random(12),
            'paid_amount_cents' => 5000,
            'currency' => 'chf',
            'paid_at' => Carbon::now(),
        ]);
    }

    public function withServiceBuffers(): static
    {
        return $this->state(function (array $attributes) {
            $serviceId = $attributes['service_id'] ?? null;
            if ($serviceId instanceof Factory) {
                return [];
            }

            $service = Service::find($serviceId);
            if (! $service) {
                return [];
            }

            return [
                'buffer_before_minutes' => $service->buffer_before ?? 0,
                'buffer_after_minutes' => $service->buffer_after ?? 0,
            ];
        });
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
            // Locked roadmap decision #30: manual bookings are always offline
            // regardless of Business.payment_mode.
            'payment_status' => PaymentStatus::NotApplicable,
            'payment_mode_at_creation' => 'offline',
        ]);
    }

    /**
     * External Google Calendar event materialised as a booking row.
     *
     * MVPC-2 (locked #2): external events have no customer and no service —
     * both FKs are nullable; buffers are 0; source is `google_calendar`; the
     * event's Google id is pinned via `external_calendar_id`; the event's
     * htmlLink is pinned via `external_html_link` (D-084 revised).
     */
    public function external(): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_id' => null,
            'service_id' => null,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'source' => BookingSource::GoogleCalendar,
            'status' => BookingStatus::Confirmed,
            // Locked roadmap decision #30: google_calendar bookings are always
            // offline — no payment ever expected through riservo.
            'payment_status' => PaymentStatus::NotApplicable,
            'payment_mode_at_creation' => 'offline',
            'external_calendar_id' => 'google-event-'.Str::random(20),
            'external_title' => fake()->sentence(3),
            'external_html_link' => fake()->url(),
        ]);
    }
}
