<?php

namespace App\Jobs\Calendar;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use App\Models\Booking;
use App\Models\CalendarIntegration;
use App\Models\Customer;
use App\Models\PendingAction;
use App\Models\Provider;
use App\Services\Calendar\CalendarProviderFactory;
use App\Services\Calendar\DTOs\ExternalEvent;
use App\Services\Calendar\Exceptions\SyncTokenExpiredException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pull incremental events for a single calendar from the provider and
 * reconcile against the riservo bookings table.
 *
 * Invariants (per MVPC-2 plan §3 step 24):
 *   - We never silently cancel a riservo booking from a Google-side change.
 *   - We never overwrite the GIST invariant (D-065/D-066): a conflicting
 *     external event becomes an `external_booking_conflict` pending action;
 *     the Booking row is NOT inserted.
 *   - We never echo our own push back as an external event (riservo_booking_id
 *     in extendedProperties.private signals self).
 */
class PullCalendarEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly int $integrationId,
        public readonly string $calendarId,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 120, 300, 600, 1200];
    }

    public function handle(CalendarProviderFactory $factory): void
    {
        $integration = CalendarIntegration::with('business')->find($this->integrationId);

        if ($integration === null || ! $integration->isConfigured()) {
            return;
        }

        $watch = $integration->watches()->where('calendar_id', $this->calendarId)->first();

        $provider = $factory->for($integration);

        try {
            $result = $provider->syncIncremental($integration, $this->calendarId);
        } catch (SyncTokenExpiredException) {
            // 410 Gone → clear the per-watch token, retry forward-only once; on repeat failure, bubble.
            $watch?->forceFill(['sync_token' => null])->save();
            $result = $provider->syncIncremental($integration, $this->calendarId);
        }

        foreach ($result->events as $event) {
            try {
                $this->processEvent($integration, $event);
            } catch (Throwable $e) {
                // An individual event's failure must not poison the rest of the batch.
                Log::error('Failed to process calendar event', [
                    'integration_id' => $integration->id,
                    'calendar_id' => $this->calendarId,
                    'event_id' => $event->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($watch && $result->nextSyncToken !== null) {
            $watch->forceFill(['sync_token' => $result->nextSyncToken])->save();
        }

        $integration->forceFill([
            'last_synced_at' => now(),
            'sync_error' => null,
            'sync_error_at' => null,
        ])->save();
    }

    public function failed(Throwable $e): void
    {
        $integration = CalendarIntegration::find($this->integrationId);
        if ($integration) {
            $integration->forceFill([
                'sync_error' => mb_substr($e->getMessage(), 0, 500),
                'sync_error_at' => now(),
            ])->save();
        }
    }

    private function processEvent(CalendarIntegration $integration, ExternalEvent $event): void
    {
        // Event touches a riservo-originated booking — decide by state.
        $riservoBookingId = $event->riservoBookingId();
        if ($riservoBookingId !== null) {
            $this->processOwnEvent($integration, $event, $riservoBookingId);

            return;
        }

        // Foreign event (created directly in Google). Cancelled events that
        // were previously imported must cancel the corresponding external
        // booking; otherwise upsert.
        $existing = Booking::where('business_id', $integration->business_id)
            ->where('source', BookingSource::GoogleCalendar->value)
            ->where('external_calendar_id', $event->id)
            ->first();

        if ($event->isCancelled()) {
            if ($existing && $existing->status !== BookingStatus::Cancelled) {
                $existing->update(['status' => BookingStatus::Cancelled]);
            }

            return;
        }

        $this->upsertExternalBooking($integration, $event, $existing);
    }

    private function processOwnEvent(
        CalendarIntegration $integration,
        ExternalEvent $event,
        int $riservoBookingId,
    ): void {
        $booking = Booking::find($riservoBookingId);

        if ($booking === null) {
            return;
        }

        // Same-business defence: a hostile crafted event could carry our booking id
        // but land on a different business's integration. The extendedProperties
        // business id must match.
        $claimedBusinessId = $event->riservoBusinessId();
        if ($claimedBusinessId !== null && $claimedBusinessId !== $booking->business_id) {
            return;
        }

        if ($event->isCancelled() && ! in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::Completed, BookingStatus::NoShow], true)) {
            // Google side deleted — riservo side still active. Never auto-cancel
            // (locked decision #5). Surface as a pending action.
            $this->createPendingAction(
                $integration,
                PendingActionType::RiservoEventDeletedInGoogle,
                [
                    'external_event_id' => $event->id,
                    'external_calendar_id' => $event->calendarId,
                    'external_summary' => $event->summary,
                ],
                bookingId: $booking->id,
            );
        }

        // Both sides cancelled or still active → no-op (ignore echoes of our own push).
    }

    private function upsertExternalBooking(
        CalendarIntegration $integration,
        ExternalEvent $event,
        ?Booking $existing,
    ): void {
        if ($event->start === null || $event->end === null) {
            return; // all-day or malformed event; skip for MVP.
        }

        $provider = Provider::where('business_id', $integration->business_id)
            ->where('user_id', $integration->user_id)
            ->first();

        if ($provider === null) {
            // Integration's user lost their provider row in this business.
            // Mark a sync error on the next failed() path.
            throw new \RuntimeException(
                "No active provider for user [{$integration->user_id}] in business [{$integration->business_id}]",
            );
        }

        $customerId = $this->firstMatchingCustomerId($integration->business_id, $event->attendeeEmails);

        $attributes = [
            'business_id' => $integration->business_id,
            'provider_id' => $provider->id,
            'service_id' => null,
            'customer_id' => $customerId,
            'starts_at' => $event->start,
            'ends_at' => $event->end,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'status' => BookingStatus::Confirmed,
            'source' => BookingSource::GoogleCalendar,
            'external_calendar_id' => $event->id,
            'external_title' => $event->summary,
            'external_html_link' => $event->htmlLink,
            'payment_status' => 'pending',
            'cancellation_token' => $existing?->cancellation_token ?? (string) Str::uuid(),
        ];

        try {
            DB::transaction(function () use ($existing, $attributes) {
                if ($existing) {
                    $existing->update($attributes);
                } else {
                    Booking::create($attributes);
                }
            });
        } catch (QueryException $e) {
            // Postgres exclusion_violation (D-066). This external event overlaps
            // an existing confirmed riservo booking on the same provider. Per
            // locked decision #5, surface as a pending action; do NOT insert.
            if (($e->getPrevious()?->getCode() ?? $e->getCode()) !== '23P01') {
                throw $e;
            }

            $conflictingBookingIds = $this->findConflictingBookingIds(
                $attributes['provider_id'],
                $event->start,
                $event->end,
            );

            // D-087: cancel_riservo_booking resolves by cancelling ONE riservo
            // booking and re-dispatching the pull. When multiple bookings conflict,
            // we pin the earliest-starting booking as the primary target; the
            // resolver chooses based on `booking_id` on the pending action.
            $primaryBookingId = $conflictingBookingIds[0] ?? null;

            $this->createPendingAction(
                $integration,
                PendingActionType::ExternalBookingConflict,
                [
                    'external_event_id' => $event->id,
                    'external_calendar_id' => $event->calendarId,
                    'external_summary' => $event->summary,
                    'external_start' => $event->start->toIso8601String(),
                    'external_end' => $event->end->toIso8601String(),
                    'conflict_booking_ids' => $conflictingBookingIds,
                ],
                bookingId: $primaryBookingId,
            );
        }
    }

    /**
     * @return array<int, int>
     */
    private function findConflictingBookingIds(int $providerId, $start, $end): array
    {
        return Booking::where('provider_id', $providerId)
            ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed])
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('starts_at')
            ->pluck('id')
            ->all();
    }

    private function firstMatchingCustomerId(int $businessId, array $emails): ?int
    {
        if (empty($emails)) {
            return null;
        }

        // Locked decision #6: first-match-by-email, scoped to customers who have
        // at least one booking in this business. No auto-create.
        $customer = Customer::whereIn('email', $emails)
            ->whereHas('bookings', fn ($q) => $q->where('business_id', $businessId))
            ->orderBy('id')
            ->first();

        return $customer?->id;
    }

    private function createPendingAction(
        CalendarIntegration $integration,
        PendingActionType $type,
        array $payload,
        ?int $bookingId = null,
    ): void {
        // Avoid duplicate pending actions for the same external event id.
        $externalEventId = $payload['external_event_id'] ?? null;

        $existing = $externalEventId
            ? PendingAction::where('integration_id', $integration->id)
                ->where('type', $type->value)
                ->where('status', PendingActionStatus::Pending->value)
                ->where('payload->external_event_id', $externalEventId)
                ->first()
            : null;

        if ($existing) {
            return;
        }

        PendingAction::create([
            'business_id' => $integration->business_id,
            'integration_id' => $integration->id,
            'booking_id' => $bookingId,
            'type' => $type,
            'payload' => $payload,
            'status' => PendingActionStatus::Pending,
        ]);
    }
}
