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
        return $this->service?->name
            ?? $this->external_title
            ?? __('External event');
    }
}
