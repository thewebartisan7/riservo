<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\BookingStatus;
use App\Enums\BusinessMemberRole;
use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use App\Http\Controllers\Controller;
use App\Jobs\Calendar\PullCalendarEventsJob;
use App\Models\PendingAction;
use App\Notifications\BookingCancelledNotification;
use App\Services\Calendar\CalendarProviderFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Throwable;

class CalendarPendingActionController extends Controller
{
    public function __construct(
        private readonly CalendarProviderFactory $providerFactory,
    ) {}

    public function resolve(Request $request, PendingAction $action): RedirectResponse
    {
        $business = tenant()->business();

        abort_unless($business !== null && $action->business_id === $business->id, 404);

        $isAdmin = tenant()->role() === BusinessMemberRole::Admin;
        $ownsIntegration = $action->integration?->user_id === $request->user()->id;
        abort_unless($isAdmin || $ownsIntegration, 403);

        if ($action->status !== PendingActionStatus::Pending) {
            return back()->with('error', __('This action has already been resolved.'));
        }

        $choice = $request->validate([
            'choice' => ['required', 'string'],
        ])['choice'];

        $action->loadMissing(['integration', 'booking']);

        // PAYMENTS Session 1 (D-113) generalised the pending_actions table.
        // This controller stays calendar-only; payment-typed Pending Actions
        // are resolved by their owning per-session UIs (Session 2b / 3).
        if (! in_array($action->type->value, PendingActionType::calendarValues(), true)) {
            abort(404);
        }

        $handled = match ($action->type) {
            PendingActionType::RiservoEventDeletedInGoogle => $this->resolveRiservoDeleted($action, $choice),
            PendingActionType::ExternalBookingConflict => $this->resolveConflict($action, $choice),
            default => 'invalid',
        };

        if ($handled === 'invalid') {
            return back()->with('error', __('Invalid resolution for this action.'));
        }

        if ($handled === 'failed') {
            // Provider call failed (e.g., Google 5xx). Keep the action pending
            // so staff can retry; surface the failure in the flash banner.
            return back()->with('error', __('Could not complete that action. Please try again in a moment.'));
        }

        $action->forceFill([
            'resolved_by_user_id' => $request->user()->id,
            'resolution_note' => $choice,
            'resolved_at' => now(),
        ])->save();

        return back()->with('success', __('Action resolved.'));
    }

    /**
     * @return 'resolved'|'failed'|'invalid'
     */
    private function resolveRiservoDeleted(PendingAction $action, string $choice): string
    {
        $booking = $action->booking;

        if ($choice === 'cancel_and_notify') {
            if ($booking && $booking->status !== BookingStatus::Cancelled) {
                $booking->update(['status' => BookingStatus::Cancelled]);

                // Booking's source is `riservo` (it's a riservo-originated event that
                // Google deleted). shouldSuppressCustomerNotifications() returns false;
                // the notification fires.
                if ($booking->customer && ! $booking->shouldSuppressCustomerNotifications()) {
                    Notification::route('mail', $booking->customer->email)
                        ->notify(new BookingCancelledNotification($booking, 'business'));
                }
            }
            $action->status = PendingActionStatus::Resolved;
            $action->save();

            return 'resolved';
        }

        if ($choice === 'keep_and_dismiss') {
            $action->status = PendingActionStatus::Dismissed;
            $action->save();

            return 'resolved';
        }

        return 'invalid';
    }

    /**
     * @return 'resolved'|'failed'|'invalid'
     */
    private function resolveConflict(PendingAction $action, string $choice): string
    {
        $integration = $action->integration;

        if ($choice === 'keep_riservo_ignore_external') {
            $action->status = PendingActionStatus::Dismissed;
            $action->save();

            return 'resolved';
        }

        if ($choice === 'cancel_external') {
            $calendarId = $action->payload['external_calendar_id'] ?? null;
            $eventId = $action->payload['external_event_id'] ?? null;

            if (! $integration || ! $calendarId || ! $eventId) {
                return 'invalid';
            }

            try {
                $provider = $this->providerFactory->for($integration);
                $provider->deleteEvent($integration, $calendarId, $eventId);
            } catch (Throwable $e) {
                // Provider rejected or timed out. Do NOT mark the action resolved
                // — the external event still exists and continues blocking
                // availability. Staff must be able to retry.
                report($e);

                return 'failed';
            }

            $action->status = PendingActionStatus::Resolved;
            $action->save();

            return 'resolved';
        }

        if ($choice === 'cancel_riservo_booking') {
            $booking = $action->booking;
            if ($booking && $booking->status !== BookingStatus::Cancelled) {
                $booking->update(['status' => BookingStatus::Cancelled]);

                if ($booking->customer && ! $booking->shouldSuppressCustomerNotifications()) {
                    Notification::route('mail', $booking->customer->email)
                        ->notify(new BookingCancelledNotification($booking, 'business'));
                }
            }

            // D-087 revised: re-dispatch the pull for the source calendar so the
            // external event materialises promptly rather than waiting for the
            // next inbound webhook.
            $calendarId = $action->payload['external_calendar_id'] ?? null;
            if ($integration && $calendarId) {
                PullCalendarEventsJob::dispatch($integration->id, $calendarId);
            }

            $action->status = PendingActionStatus::Resolved;
            $action->save();

            return 'resolved';
        }

        return 'invalid';
    }
}
