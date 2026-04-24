<?php

namespace App\Models;

use App\Enums\BookingRefundStatus;
use Database\Factories\BookingRefundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * PAYMENTS Session 2b: one row per refund ATTEMPT against a booking
 * (locked roadmap decision #36). The row's `uuid` seeds the Stripe
 * idempotency key — retries of the same attempt reuse the row + UUID
 * so Stripe collapses duplicates; two legitimately-distinct intents
 * produce two rows.
 *
 * Session 2b writes `reason = 'cancelled-after-payment'` only (the
 * late-webhook refund path per locked decision #31). Session 3 extends
 * the vocabulary with `customer-requested`, `business-cancelled`,
 * `admin-manual`, `business-rejected-pending`.
 *
 * @property int $id
 * @property string $uuid
 * @property int $booking_id
 * @property string|null $stripe_refund_id
 * @property int $amount_cents
 * @property string $currency
 * @property BookingRefundStatus $status
 * @property string $reason
 * @property int|null $initiated_by_user_id
 * @property string|null $failure_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'uuid',
    'booking_id',
    'stripe_refund_id',
    'amount_cents',
    'currency',
    'status',
    'reason',
    'initiated_by_user_id',
    'failure_reason',
])]
class BookingRefund extends Model
{
    /** @use HasFactory<BookingRefundFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => BookingRefundStatus::class,
            'amount_cents' => 'integer',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function initiatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
