<?php

namespace App\Models;

use App\Enums\BookingRefundStatus;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property BookingStatus $status
 * @property BookingSource $source
 * @property PaymentStatus $payment_status
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $stripe_checkout_session_id
 * @property string|null $stripe_payment_intent_id
 * @property string|null $stripe_charge_id
 * @property string|null $stripe_connected_account_id The acct_… the Checkout session was minted on. Codex Round 2 (D-158):
 *                                                    pinned on the booking at creation time so late webhooks after disconnect+reconnect cycles can
 *                                                    cross-check against the ORIGINAL minting account rather than the business's current active row.
 * @property int|null $paid_amount_cents
 * @property string|null $currency
 * @property Carbon|null $paid_at
 * @property string $payment_mode_at_creation PAYMENTS Session 2a snapshot (locked decision #14).
 *                                            Plain string (not an enum cast) — value-equal to a PaymentMode enum case at write time,
 *                                            but the snapshot is immutable and must not be silently reinterpreted if the enum ever grows.
 *                                            Literal string compares against 'offline' / 'online' / 'customer_choice' are deliberate.
 * @property Carbon|null $expires_at
 */
#[Fillable([
    'business_id',
    'provider_id',
    'service_id',
    'customer_id',
    'starts_at',
    'ends_at',
    'buffer_before_minutes',
    'buffer_after_minutes',
    'status',
    'source',
    'external_calendar_id',
    'external_event_calendar_id',
    'external_title',
    'external_html_link',
    'payment_status',
    'stripe_checkout_session_id',
    'stripe_payment_intent_id',
    'stripe_charge_id',
    'stripe_connected_account_id',
    'paid_amount_cents',
    'currency',
    'paid_at',
    'payment_mode_at_creation',
    'expires_at',
    'notes',
    'internal_notes',
    'cancellation_token',
])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => BookingStatus::class,
            'source' => BookingSource::class,
            'payment_status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<Provider, $this> */
    public function provider(): BelongsTo
    {
        // D-067: a booking's provider is a historical, immutable fact; resolve the row
        // regardless of deleted_at so display/notification sites never crash. Eligibility
        // for NEW work flows through Provider::query() / $service->providers() /
        // $business->providers(), which keep the default SoftDeletingScope.
        return $this->belongsTo(Provider::class)->withTrashed();
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<BookingReminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(BookingReminder::class);
    }

    /** @return HasMany<BookingRefund, $this> */
    public function bookingRefunds(): HasMany
    {
        return $this->hasMany(BookingRefund::class);
    }

    /**
     * Pending Actions scoped to this booking. PAYMENTS Session 2b reads this
     * through a type-bucket filter (`PaymentCancelledAfterPayment` +
     * `PaymentRefundFailed`) on the dashboard booking-detail sheet;
     * calendar-typed PAs exist on the same table (D-113) but are not
     * surfaced here — they're owned by `CalendarPendingActionController`.
     *
     * @return HasMany<PendingAction, $this>
     */
    public function pendingActions(): HasMany
    {
        return $this->hasMany(PendingAction::class);
    }

    /**
     * Whether this booking should push to the provider's external calendar.
     *
     * Gates every dispatch of PushBookingToCalendarJob (D-083): the provider
     * has a configured integration, and the booking is not itself a pulled
     * Google event (we never round-trip inbound events back out).
     */
    public function shouldPushToCalendar(): bool
    {
        if ($this->source === BookingSource::GoogleCalendar) {
            return false;
        }

        $user = $this->provider?->user;
        $integration = $user?->calendarIntegration;

        return $integration !== null && $integration->isConfigured();
    }

    /**
     * Whether customer-facing notifications must be suppressed for this booking.
     *
     * Locked decision #7: bookings with source = google_calendar do not trigger
     * customer notifications. Guards live at every dispatch site; Notification
     * classes stay unchanged (D-088).
     */
    public function shouldSuppressCustomerNotifications(): bool
    {
        return $this->source === BookingSource::GoogleCalendar;
    }

    /**
     * Display label for this booking's service.
     *
     * External bookings have no service — fall back to the event summary or a
     * generic "External event" label so serialisers can avoid null dereference
     * on $booking->service->name.
     */
    public function serviceLabel(): string
    {
        return $this->service->name
            ?? $this->external_title
            ?? __('External event');
    }

    /**
     * PAYMENTS Session 2a: true iff this booking was created via the online-
     * payment branch of PublicBookingController::store. Distinct from
     * `wasCustomerChoice()` in that a customer_choice+pay-on-site booking
     * correctly reports false here — no Checkout session was created.
     */
    public function isOnlinePayment(): bool
    {
        return $this->payment_mode_at_creation !== 'offline'
            && $this->stripe_checkout_session_id !== null;
    }

    /**
     * PAYMENTS Session 2a: true iff the Business's payment_mode at booking
     * creation was 'customer_choice'. The value is the immutable snapshot
     * per locked decision #14, NOT a re-read of Business.payment_mode.
     */
    public function wasCustomerChoice(): bool
    {
        return $this->payment_mode_at_creation === 'customer_choice';
    }

    /**
     * Refund clamp per locked decision #37: refunds are always computed from
     * `paid_amount_cents`, never from `Service.price`. Session 2b expands the
     * Session 2a stub to subtract in-flight + succeeded refund attempts, so
     * partial-refund UIs (Session 3) and the late-webhook refund path
     * (Session 2b's `RefundService`) can safely sum against this clamp
     * without double-counting.
     *
     * Failed refund attempts do NOT reduce the clamp — the money never left
     * the connected account, so it's still refundable via a fresh attempt.
     *
     * The `max(0, …)` guard is defensive: a stray over-refund row (duplicate
     * insert, partial migration, manual DB edit) shouldn't surface as a
     * negative refundable amount to a downstream caller.
     */
    public function remainingRefundableCents(): int
    {
        $paid = $this->paid_amount_cents ?? 0;

        $consumed = (int) $this->bookingRefunds()
            ->whereIn('status', [
                BookingRefundStatus::Pending->value,
                BookingRefundStatus::Succeeded->value,
            ])
            ->sum('amount_cents');

        return max(0, $paid - $consumed);
    }

    /**
     * PAYMENTS Session 3 — customer-facing refund status line for the public
     * booking management page (`/bookings/{token}`) and the authenticated
     * `/my-bookings` list.
     *
     * Returns null when the booking has no refund attempts (nothing to show
     * customer-side). Otherwise branches on the latest refund attempt's
     * status and the remaining refundable amount:
     *
     *  - failed + pinned account disconnected → "the business will contact
     *    you to arrange the refund" (locked decision #36);
     *  - failed (other) → "the business has been notified";
     *  - succeeded with remaining = 0 → "refunded in full — 5-10 business
     *    days";
     *  - succeeded with remaining > 0 → "partial refund issued";
     *  - only pending → "refund initiated — processing".
     *
     * Timing copy is Stripe's stock 5-10 business days. Staff dashboard
     * uses a different, richer panel (M7) — this method is customer-only.
     *
     * Codex Round 2 P3: N+1 avoidance. Prefer the eager-loaded
     * `bookingRefunds` relation when present; fall back to a fresh query
     * otherwise (safe for single-booking show pages). Accepts an optional
     * `$pinnedAccountDisconnected` precomputed by the caller (batched at
     * controller level for list pages) to skip the per-booking
     * `stripe_connected_accounts` lookup.
     */
    public function refundStatusLine(?bool $pinnedAccountDisconnected = null): ?string
    {
        $refunds = $this->relationLoaded('bookingRefunds')
            ? $this->bookingRefunds->sortByDesc('id')->values()
            : $this->bookingRefunds()->latest('id')->get();

        if ($refunds->isEmpty()) {
            return null;
        }

        $latest = $refunds->first();
        $succeededCents = (int) $refunds
            ->where('status', BookingRefundStatus::Succeeded)
            ->sum('amount_cents');
        $paid = $this->paid_amount_cents ?? 0;

        if ($latest->status === BookingRefundStatus::Failed) {
            $disconnected = $pinnedAccountDisconnected ?? $this->hasDisconnectedPinnedAccount();

            return $disconnected
                ? (string) __('Your booking is cancelled. Because the business\'s payment setup has changed, the refund cannot be issued automatically — they will contact you to arrange it.')
                : (string) __('The automatic refund couldn\'t be processed. The business has been notified and will contact you.');
        }

        if ($succeededCents > 0 && $paid > 0 && $succeededCents >= $paid) {
            return (string) __('Refunded in full — expect the funds in your original payment method within 5–10 business days.');
        }

        if ($succeededCents > 0 && $succeededCents < $paid) {
            return (string) __('Partial refund issued.');
        }

        return (string) __('Refund initiated — processing.');
    }

    /**
     * True when the booking's pinned (D-158) connected account is
     * soft-deleted — i.e. the business disconnected Stripe between
     * booking creation and the refund attempt. Used by
     * `refundStatusLine()` to branch the disconnected-fallback copy per
     * locked decision #36.
     */
    public function hasDisconnectedPinnedAccount(): bool
    {
        if (! is_string($this->stripe_connected_account_id) || $this->stripe_connected_account_id === '') {
            return false;
        }

        return StripeConnectedAccount::withTrashed()
            ->where('stripe_account_id', $this->stripe_connected_account_id)
            ->whereNotNull('deleted_at')
            ->exists();
    }
}
