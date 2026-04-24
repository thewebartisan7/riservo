<?php

namespace Database\Factories;

use App\Enums\BookingRefundStatus;
use App\Models\Booking;
use App\Models\BookingRefund;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BookingRefund>
 */
class BookingRefundFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'booking_id' => Booking::factory()->paid(),
            'stripe_refund_id' => null,
            'amount_cents' => 5000,
            'currency' => 'chf',
            'status' => BookingRefundStatus::Pending,
            'reason' => 'cancelled-after-payment',
            'initiated_by_user_id' => null,
            'failure_reason' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => BookingRefundStatus::Pending,
            'stripe_refund_id' => null,
            'failure_reason' => null,
        ]);
    }

    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => BookingRefundStatus::Succeeded,
            'stripe_refund_id' => 're_test_'.Str::random(16),
            'failure_reason' => null,
        ]);
    }

    public function failed(?string $failureReason = null): static
    {
        return $this->state(fn () => [
            'status' => BookingRefundStatus::Failed,
            'stripe_refund_id' => null,
            'failure_reason' => $failureReason ?? 'This account does not have permission to perform this operation.',
        ]);
    }
}
