<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAYMENTS Session 2b — locked roadmap decision #36.
 *
 * One row per refund ATTEMPT. The row's `uuid` is the source of the Stripe
 * idempotency key (`'riservo_refund_'.{uuid}`), so a retry of the same attempt
 * reuses the row + UUID and Stripe collapses the duplicate; two legitimately-
 * distinct refund intents on the same booking get two rows and two UUIDs.
 *
 * Reason vocabulary in Session 2b: `'cancelled-after-payment'` (the late-
 * webhook refund path). Session 3 extends the value space with
 * `customer-requested`, `business-cancelled`, `admin-manual`, and
 * `business-rejected-pending` without changing this schema.
 *
 * Status vocabulary: native PHP enum `BookingRefundStatus` (`pending`,
 * `succeeded`, `failed`). The full set across Sessions 2b + 3; Stripe's
 * `requires_action` / `canceled` states are not surfaced in our flow.
 *
 * `Booking::remainingRefundableCents()` reads the composite index
 * `(booking_id, status)` to clamp partial-refund amounts (locked decision #37).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_refunds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('booking_id')
                ->constrained('bookings')
                ->cascadeOnDelete();
            $table->string('stripe_refund_id')->nullable()->unique();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status')->default('pending');
            $table->string('reason');
            $table->foreignId('initiated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_refunds');
    }
};
