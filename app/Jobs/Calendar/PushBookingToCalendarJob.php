<?php

namespace App\Jobs\Calendar;

use App\Models\Booking;
use App\Models\CalendarIntegration;
use App\Services\Calendar\CalendarProvider;
use App\Services\Calendar\CalendarProviderFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Push a riservo booking to the provider's destination calendar (D-083).
 *
 * Single-job design: action ∈ {create, update, delete} dispatches to the
 * right provider method. All three actions share the retry/backoff/gate
 * paths. Guarded at dispatch sites via Booking::shouldPushToCalendar().
 *
 * `afterCommit()` ensures the DB transaction that created/updated the
 * booking is visible to the worker before the SDK reads it.
 */
class PushBookingToCalendarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  'create'|'update'|'delete'  $action
     */
    public function __construct(
        public readonly int $bookingId,
        public readonly string $action,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(CalendarProviderFactory $factory): void
    {
        $booking = Booking::with(['provider.user.calendarIntegration', 'service', 'customer', 'business'])
            ->find($this->bookingId);

        if ($booking === null) {
            return; // hard-delete race; nothing to push.
        }

        $integration = $booking->provider?->user?->calendarIntegration;

        if ($integration === null || ! $integration->isConfigured()) {
            return; // integration disconnected or never configured.
        }

        $provider = $factory->for($integration);

        match ($this->action) {
            'create' => $this->create($booking, $provider, $integration),
            'update' => $this->update($booking, $provider, $integration),
            'delete' => $this->delete($booking, $provider, $integration),
        };

        $integration->forceFill([
            'last_pushed_at' => now(),
            'push_error' => null,
            'push_error_at' => null,
        ])->save();
    }

    public function failed(Throwable $e): void
    {
        $booking = Booking::with('provider.user.calendarIntegration')->find($this->bookingId);
        $integration = $booking?->provider?->user?->calendarIntegration;

        if ($integration) {
            $integration->forceFill([
                'push_error' => mb_substr($e->getMessage(), 0, 500),
                'push_error_at' => now(),
            ])->save();
        }
    }

    private function create(Booking $booking, CalendarProvider $provider, CalendarIntegration $integration): void
    {
        if ($booking->external_calendar_id) {
            // Already pushed (re-queued dispatch). Upgrade to an update.
            $provider->updateEvent($booking);

            return;
        }

        $externalId = $provider->pushEvent($booking);

        // Persist the calendar we pushed to (round 2 review). A later
        // `destination_calendar_id` change must not re-target this booking's
        // event to a different calendar.
        $booking->forceFill([
            'external_calendar_id' => $externalId,
            'external_event_calendar_id' => $integration->destination_calendar_id,
        ])->save();
    }

    private function update(Booking $booking, CalendarProvider $provider, CalendarIntegration $integration): void
    {
        if (! $booking->external_calendar_id) {
            // Never pushed — treat as a first push.
            $this->create($booking, $provider, $integration);

            return;
        }

        $provider->updateEvent($booking);
    }

    private function delete(Booking $booking, CalendarProvider $provider, CalendarIntegration $integration): void
    {
        if (! $booking->external_calendar_id) {
            return; // never pushed.
        }

        // Always target the calendar where the event actually lives, not the
        // integration's current destination (which may have been changed via
        // reconfigure). Falls back to the current destination for legacy rows
        // pushed before round-2 landed.
        $calendarId = $booking->external_event_calendar_id ?? $integration->destination_calendar_id;

        $provider->deleteEvent(
            $integration,
            $calendarId,
            $booking->external_calendar_id,
        );
    }
}
