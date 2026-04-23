<?php

namespace App\Models;

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
     * `paid_amount_cents`, never from `Service.price`. Session 2a returns
     * the raw column; Session 2b expands to subtract
     * SUM(booking_refunds.amount_cents WHERE status IN (pending, succeeded))
     * once that table lands.
     */
    public function remainingRefundableCents(): int
    {
        return $this->paid_amount_cents ?? 0;
    }
}
