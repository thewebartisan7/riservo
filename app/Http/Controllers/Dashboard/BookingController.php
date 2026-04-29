<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use App\Exceptions\Booking\NoProviderAvailableException;
use App\Exceptions\Booking\SlotNoLongerAvailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\RescheduleBookingRequest;
use App\Http\Requests\Dashboard\StoreManualBookingRequest;
use App\Http\Requests\Dashboard\UpdateBookingStatusRequest;
use App\Jobs\Calendar\PushBookingToCalendarJob;
use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use App\Notifications\BookingCancelledNotification;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\BookingReceivedNotification;
use App\Notifications\BookingRescheduledNotification;
use App\Services\Payments\RefundService;
use App\Services\SlotGeneratorService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\RateLimitException;

class BookingController extends Controller
{
    public function __construct(
        private SlotGeneratorService $slotGenerator,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $business = tenant()->business();
        $isAdmin = tenant()->role()->value === 'admin';

        $query = Booking::where('business_id', $business->id)
            ->with([
                'service:id,name,duration_minutes,price',
                'provider.user:id,name,avatar',
                'customer:id,name,email,phone',
            ]);

        if ($isAdmin) {
            // PAYMENTS Session 2b: admin-only payment panel + banner in
            // the booking-detail sheet reads the first pending payment-
            // typed action. Type-bucket filter keeps calendar PAs out of
            // this relation even though the underlying `pending_actions`
            // table is generalised (D-113). Eager-loaded only for admins
            // per locked decisions #19 / #31 / #35 (payment surfaces are
            // admin-only).
            //
            // Codex Round 1 (F3): when the late-refund itself fails, both
            // PAs exist on the same booking (`payment.refund_failed`
            // created first by RefundService::recordFailure, then
            // `payment.cancelled_after_payment` by applyLateWebhookRefund).
            // `refund_failed` is the more urgent one to surface — admins
            // need to reconnect Stripe or refund offline. Ordering the
            // eager-load so `refund_failed` sorts first guarantees
            // `pendingActions->first()` returns the urgent PA.
            // PAYMENTS Session 3: extend the admin-only PA type filter to
            // include `payment.dispute_opened` so the booking-detail sheet
            // can surface a Dispute section alongside refund banners. The
            // relation is unfiltered-by-type here; the serializer below
            // splits the rows into two buckets — the urgent "banner" PA
            // (refund_failed / cancelled_after_payment, whichever wins the
            // urgency sort) and the dispute PA — so a booking with BOTH an
            // open dispute AND a refund-failed action surfaces both, not
            // just the most urgent one (Codex Round 1 P2).
            $query->with([
                'pendingActions' => fn ($q) => $q
                    ->whereIn('type', [
                        PendingActionType::PaymentRefundFailed->value,
                        PendingActionType::PaymentCancelledAfterPayment->value,
                        PendingActionType::PaymentDisputeOpened->value,
                    ])
                    ->where('status', PendingActionStatus::Pending->value)
                    ->orderByRaw(
                        'CASE type WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END',
                        [
                            PendingActionType::PaymentRefundFailed->value,
                            PendingActionType::PaymentCancelledAfterPayment->value,
                        ],
                    )
                    ->latest('id'),
                'bookingRefunds' => fn ($q) => $q
                    ->with('initiatedByUser:id,name')
                    ->latest('id'),
            ]);
        }

        if (! $isAdmin) {
            $query->whereHas('provider', fn ($q) => $q->where('user_id', $user->id));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        // PAYMENTS Session 2b: admin-only payment filter per locked
        // roadmap decision #19. Codex Round 2 (F3): the filter must also
        // be server-gated on `$isAdmin` — otherwise a provider could
        // request `?payment_status=paid` and infer the payment state of
        // their bookings from which rows remain, even with the `payment`
        // sub-object nulled out in the serializer (F2 fix). Staff
        // requests silently drop the filter — same pattern as the
        // `provider_id` filter's `&& $isAdmin` guard further down.
        if ($isAdmin && $request->filled('payment_status')) {
            $paymentFilter = $request->string('payment_status')->toString();
            // UI maps 'offline' to the `not_applicable` value so the chip
            // label reads naturally.
            if ($paymentFilter === 'offline') {
                $paymentFilter = PaymentStatus::NotApplicable->value;
            }
            $query->where('payment_status', $paymentFilter);
        }

        if ($request->filled('service_id')) {
            $query->where('service_id', $request->integer('service_id'));
        }

        if ($request->filled('provider_id') && $isAdmin) {
            $query->where('provider_id', $request->integer('provider_id'));
        }

        if ($request->filled('date_from')) {
            $dateFrom = CarbonImmutable::createFromFormat('Y-m-d', $request->string('date_from'), $business->timezone)
                ->startOfDay()
                ->utc();
            $query->where('starts_at', '>=', $dateFrom);
        }

        if ($request->filled('date_to')) {
            $dateTo = CarbonImmutable::createFromFormat('Y-m-d', $request->string('date_to'), $business->timezone)
                ->endOfDay()
                ->utc();
            $query->where('starts_at', '<=', $dateTo);
        }

        // Default is "include external". `?include_external=0` suppresses google_calendar bookings.
        if ($request->string('include_external')->toString() === '0') {
            $query->where('source', '!=', BookingSource::GoogleCalendar->value);
        }

        $sortField = $request->string('sort', 'starts_at');
        $sortDir = $request->string('direction', 'desc');
        $allowedSorts = ['starts_at', 'created_at'];
        if (in_array($sortField->toString(), $allowedSorts, true)) {
            $query->orderBy($sortField->toString(), $sortDir->toString() === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('starts_at');
        }

        $bookings = $query->paginate(20)->withQueryString();

        $services = $business->services()
            ->where('is_active', true)
            ->with(['providers' => fn ($q) => $q->where('providers.business_id', $business->id)->with('user:id,name,avatar')])
            ->get(['id', 'name', 'duration_minutes', 'price', 'slug']);

        $providers = $isAdmin
            ? $business->providers()
                ->with('user:id,name')
                ->orderBy('id')
                ->get()
            : collect();

        return Inertia::render('dashboard/bookings', [
            'bookings' => $bookings->through(function (Booking $booking) use ($isAdmin): array {
                // Codex Round 1 (F2): the `payment` + `pending_payment_
                // action` sub-objects are admin-only. Staff can view their
                // own bookings via `/dashboard/bookings`, so including
                // Stripe ids in the payload unconditionally would leak the
                // money surface past the locked-roadmap-decision #19 gate.
                //
                // Session 3 Codex Round 1 P2: split the PAs into two
                // independent buckets so a booking with BOTH a dispute AND
                // a refund-failed PA surfaces both banners, not just the
                // most urgent one.
                $allPendingActions = $isAdmin ? $booking->pendingActions : collect();
                $urgentPendingAction = $allPendingActions
                    ->first(fn ($pa) => $pa->type !== PendingActionType::PaymentDisputeOpened);
                $disputePendingAction = $allPendingActions
                    ->first(fn ($pa) => $pa->type === PendingActionType::PaymentDisputeOpened);

                return [
                    'id' => $booking->id,
                    'starts_at' => $booking->starts_at->toIso8601String(),
                    'ends_at' => $booking->ends_at->toIso8601String(),
                    'status' => $booking->status->value,
                    'source' => $booking->source->value,
                    'external' => $booking->source === BookingSource::GoogleCalendar,
                    'external_title' => $booking->external_title,
                    'external_html_link' => $booking->external_html_link,
                    'notes' => $booking->notes,
                    'internal_notes' => $booking->internal_notes,
                    'created_at' => $booking->created_at->toIso8601String(),
                    'cancellation_token' => $booking->cancellation_token,
                    'service' => $booking->service
                        ? [
                            'id' => $booking->service->id,
                            'name' => $booking->service->name,
                            'duration_minutes' => $booking->service->duration_minutes,
                            'price' => $booking->service->price,
                        ]
                        : null,
                    'provider' => [
                        'id' => $booking->provider->id,
                        'name' => $booking->provider->user->name ?? '',
                        'avatar_url' => $booking->provider->user?->avatar
                            ? Storage::disk('public')->url($booking->provider->user->avatar)
                            : null,
                        'is_active' => ! $booking->provider->trashed(),
                    ],
                    'customer' => $booking->customer
                        ? [
                            'id' => $booking->customer->id,
                            'name' => $booking->customer->name,
                            'email' => $booking->customer->email,
                            'phone' => $booking->customer->phone,
                        ]
                        : null,
                    // PAYMENTS Session 2b: admin-only payment panel on the
                    // booking-detail sheet reads these. Codex Round 1 (F2)
                    // gated the sub-object on `$isAdmin` so staff can't see
                    // Stripe ids for their own bookings (locked decision
                    // #19: payment surfaces are admin-only).
                    //
                    // Session 3 adds `remaining_refundable_cents` so the
                    // Refund dialog's client-side clamp + the "refundable"
                    // caption match the server's authoritative clamp.
                    // PAYMENTS Hardening Round 2 — D-184. Raw Stripe object
                    // IDs are no longer exposed via Inertia props. The
                    // booking-detail-sheet builds Stripe dashboard deeplinks
                    // via the new server-side redirect endpoints (see
                    // StripeDashboardLinkController), reading IDs from the
                    // server. The booleans below tell the UI when each link
                    // type is available for this booking.
                    'payment' => $isAdmin
                        ? [
                            'status' => $booking->payment_status->value,
                            'paid_amount_cents' => $booking->paid_amount_cents,
                            'currency' => $booking->currency,
                            'paid_at' => $booking->paid_at?->toIso8601String(),
                            'remaining_refundable_cents' => $booking->remainingRefundableCents(),
                            'has_stripe_payment_link' => is_string($booking->stripe_connected_account_id) && $booking->stripe_connected_account_id !== ''
                                && (is_string($booking->stripe_charge_id) && $booking->stripe_charge_id !== ''
                                    || is_string($booking->stripe_payment_intent_id) && $booking->stripe_payment_intent_id !== ''),
                        ]
                        : null,
                    'pending_payment_action' => $urgentPendingAction !== null
                        ? [
                            'id' => $urgentPendingAction->id,
                            'type' => $urgentPendingAction->type->value,
                            'payload' => $this->whitelistPaymentPaPayload($urgentPendingAction->type, $urgentPendingAction->payload ?? []),
                            'created_at' => $urgentPendingAction->created_at->toIso8601String(),
                        ]
                        : null,
                    // PAYMENTS Session 3 Codex Round 1 P2: dispute PA rides
                    // a separate payload key so a booking with BOTH a
                    // dispute AND an urgent banner PA surfaces both in the
                    // detail sheet, not only the most urgent.
                    //
                    // PAYMENTS Hardening Round 2 (G-001): payload is
                    // whitelisted; raw `dispute_id` no longer rides the
                    // prop. The server-side dispute deeplink endpoint reads
                    // it from the PA itself.
                    'dispute_payment_action' => $disputePendingAction !== null
                        ? [
                            'id' => $disputePendingAction->id,
                            'type' => $disputePendingAction->type->value,
                            'payload' => $this->whitelistDisputePaPayload($disputePendingAction->payload ?? []),
                            'has_dispute_link' => is_string($booking->stripe_connected_account_id) && $booking->stripe_connected_account_id !== ''
                                && is_array($disputePendingAction->payload)
                                && is_string($disputePendingAction->payload['dispute_id'] ?? null)
                                && $disputePendingAction->payload['dispute_id'] !== '',
                            'created_at' => $disputePendingAction->created_at->toIso8601String(),
                        ]
                        : null,
                    // PAYMENTS Session 3: admin-only refund list for the
                    // Payment & refunds panel. Each row carries the
                    // initiator's name (admin-manual) or null (system-
                    // dispatched — surfaces as "System" in the UI).
                    //
                    // PAYMENTS Hardening Round 2 (G-001): `stripe_refund_id`
                    // replaced with `stripe_refund_id_last4` for the audit
                    // caption + `has_stripe_link` for the deeplink button.
                    // Convert Collection to a plain array via ->values()->all()
                    // so the through() callback's inferred return shape doesn't
                    // hit Collection<TValue> invariance issues across nested
                    // shape narrowings. Inertia serializes both to JSON arrays
                    // identically.
                    'refunds' => $isAdmin
                        ? $booking->bookingRefunds->map(fn ($r) => $this->refundRowPayload($r, $booking))->values()->all()
                        : null,
                ];
            }),
            'services' => $services->map(fn (Service $service) => [
                'id' => $service->id,
                'name' => $service->name,
                'duration_minutes' => $service->duration_minutes,
                'price' => $service->price,
                'providers' => $service->providers->map(fn (Provider $p) => [
                    'id' => $p->id,
                    'name' => $p->user->name ?? '',
                ])->values(),
            ])->values(),
            'providers' => $providers->map(fn (Provider $p) => [
                'id' => $p->id,
                'name' => $p->user->name ?? '',
            ])->values(),
            'filters' => [
                'status' => $request->string('status', ''),
                'payment_status' => $request->string('payment_status', ''),
                'service_id' => $request->string('service_id', ''),
                'provider_id' => $request->string('provider_id', ''),
                'date_from' => $request->string('date_from', ''),
                'date_to' => $request->string('date_to', ''),
                'sort' => $request->string('sort', 'starts_at'),
                'direction' => $request->string('direction', 'desc'),
            ],
            'isAdmin' => $isAdmin,
            'timezone' => $business->timezone,
        ]);
    }

    public function updateStatus(UpdateBookingStatusRequest $request, Booking $booking, RefundService $refundService): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $business = tenant()->business();

        abort_unless($booking->business_id === $business->id, 404);

        $isAdmin = tenant()->role()->value === 'admin';
        if (! $isAdmin && $booking->provider?->user_id !== $user->id) {
            abort(403);
        }

        $newStatus = BookingStatus::from($request->validated('status'));

        if (! $booking->status->canTransitionTo($newStatus)) {
            return back()->with('error', __('Cannot change status from :from to :to.', [
                'from' => $booking->status->label(),
                'to' => $newStatus->label(),
            ]));
        }

        // PAYMENTS Session 3 (locked decisions #17 / #19 / #29): admin-side
        // cancel of a paid booking dispatches an automatic full refund.
        //
        // F-005 (PAYMENTS Hardening Round 1): the booking transitions to
        // Cancelled FIRST — the slot must be released even if the refund call
        // raises a transient Stripe error and we surface a flash to the admin.
        // The prior order (refund-then-status) could leave the customer
        // refunded with the slot still active when the worker died after the
        // Stripe call but before the status update. RefundService records the
        // refund attempt against the now-cancelled booking; failures land as
        // a `payment.refund_failed` Pending Action + admin email, so the
        // refund issue is recoverable from the dashboard while the slot is
        // already free.
        //
        // The `reason` binds to the booking's PRE-TRANSITION status:
        //   Pending → 'business-rejected-pending' (locked decision #29 —
        //             manual-confirm rejection of a paid booking).
        //   Confirmed → 'business-cancelled' (locked decision #17 — admin
        //             cancelling a previously confirmed booking).
        //
        // Pending + Unpaid (customer_choice + manual-confirm failed Checkout,
        // lands via Session 2b) and Pending + AwaitingPayment (Checkout still
        // open) are NOT refund-dispatching paths — there's nothing to refund.
        // Both flip to Cancelled normally and the customer email omits the
        // refund clause (D-175).
        //
        // Codex Round 1 P1: locked decision #19 makes refund dispatch an
        // admin-only action. Staff users retain the ability to transition
        // their own bookings' status in general, but CANCELLATION of a paid
        // booking must not trigger a Stripe refund from a non-admin — that
        // would bypass the admin gate on the dedicated refund endpoint
        // (`BookingRefundController::store`). Staff paid-cancel attempts
        // are refused here with a "ask your admin" flash, mirroring the
        // pre-Session-3 D-159 shape for this edge case.
        $refundOutcome = null;
        $shouldRefund = $newStatus === BookingStatus::Cancelled
            && in_array($booking->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true);

        if ($shouldRefund && ! $isAdmin) {
            return back()->with('error', __('Paid bookings can only be cancelled by an admin. Ask your admin to cancel and refund this booking.'));
        }

        $refundReason = null;
        if ($shouldRefund) {
            // Snapshot the pre-transition reason; the status update below
            // will move the booking to Cancelled before we call Stripe.
            $refundReason = $booking->status === BookingStatus::Pending
                ? 'business-rejected-pending'
                : 'business-cancelled';
        }

        $booking->update(['status' => $newStatus]);

        if ($refundReason !== null) {
            try {
                $result = $refundService->refund($booking, null, $refundReason);
                $refundOutcome = $result->outcome;
            } catch (ApiConnectionException|RateLimitException|ApiErrorException $e) {
                // F-005: the booking is already Cancelled — the slot is free.
                // The transient Stripe error is surfaced as a distinct flash
                // outcome at the end of this handler so the customer email +
                // calendar push still fire on the now-cancelled booking.
                Log::warning('Dashboard\\BookingController::updateStatus transient Stripe error on cancel', [
                    'booking_id' => $booking->id,
                    'reason' => $refundReason,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                $refundOutcome = 'transient';
            }

            $booking->refresh();
        }

        $booking->loadMissing(['customer', 'business.admins', 'provider.user']);

        if ($newStatus === BookingStatus::Confirmed) {
            if (! $booking->shouldSuppressCustomerNotifications()) {
                Notification::route('mail', $booking->customer->email)
                    ->notify(new BookingConfirmedNotification($booking));

                $this->notifyStaff($booking, new BookingReceivedNotification($booking, 'confirmed'), $user->id);
            }
        }

        if ($newStatus === BookingStatus::Cancelled) {
            if (! $booking->shouldSuppressCustomerNotifications() && $booking->customer) {
                // D-175: refund clause in the customer email is gated on
                // `$refundIssued`. `$refundOutcome === 'succeeded'` is the
                // only case where the refund clause renders; disconnected
                // / failed / not-dispatched all render without the clause.
                Notification::route('mail', $booking->customer->email)
                    ->notify(new BookingCancelledNotification(
                        $booking,
                        'business',
                        refundIssued: $refundOutcome === 'succeeded',
                    ));
            }
        }

        // Push the booking change to Google Calendar. Cancel → delete;
        // confirm/complete/no-show → create-or-update. shouldPushToCalendar()
        // handles the "inbound origin" skip and the configured-integration gate.
        if ($booking->shouldPushToCalendar()) {
            $action = $newStatus === BookingStatus::Cancelled ? 'delete' : 'update';
            PushBookingToCalendarJob::dispatch($booking->id, $action);
        }

        // Admin flash copy distinguishes the refund outcomes so the admin
        // knows whether a follow-up action is needed. A disconnected /
        // failed refund leaves a `payment.refund_failed` Pending Action
        // (written by `RefundService`) — the detail-sheet banner is the
        // resolution surface.
        $flash = match (true) {
            $refundOutcome === 'succeeded' => __('Booking cancelled. Full refund issued.'),
            $refundOutcome === 'transient' => __('Booking cancelled. The refund hit a temporary Stripe issue — retry the refund from the booking detail panel.'),
            in_array($refundOutcome, ['disconnected', 'failed'], true) => __('Booking cancelled. Automatic refund failed — resolve in Stripe. See the refund-failed action for details.'),
            default => __('Booking status updated to :status.', [
                'status' => $newStatus->label(),
            ]),
        };

        $flashKey = in_array($refundOutcome, ['disconnected', 'failed', 'transient'], true) ? 'error' : 'success';

        return back()->with($flashKey, $flash);
    }

    /**
     * Reschedule a booking to a new time (drag / resize from the calendar).
     *
     * Shape (D-105): `{ starts_at: UTC ISO-8601, duration_minutes: int }`. Server
     * recomputes `ends_at = starts_at + duration_minutes` so drag and resize
     * share one endpoint. Availability reuses SlotGeneratorService with
     * `excluding: $booking` so the booking does not block its own move
     * (D-066). Transaction + GIST (D-065/D-066) are the race-safe backstop;
     * a `23P01` (exclusion_violation) surfaces as 409. PushBookingToCalendarJob
     * is dispatched with action=update when the provider has a configured
     * integration (D-083). Customer notification (D-108 via locked #16) is
     * suppressed when the booking is `source = google_calendar` (D-088).
     *
     * Refused with 422:
     *   - the booking's provider is soft-deleted (D-067 — eligibility excludes
     *     trashed providers for new work);
     *   - `source = google_calendar` (external bookings are mirrors);
     *   - terminal status (cancelled/completed/no_show — cannot be rescheduled);
     *   - the booking has no service attached (defensive — external bookings
     *     would have hit the source guard above; kept explicit);
     *   - `starts_at` does not snap to `service.slot_interval_minutes` (D-106);
     *   - the new window would straddle two calendar days (booking invariant).
     */
    public function reschedule(RescheduleBookingRequest $request, Booking $booking): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $business = tenant()->business();

        abort_unless($booking->business_id === $business->id, 404);

        $isAdmin = tenant()->role()->value === 'admin';
        if (! $isAdmin && $booking->provider?->user_id !== $user->id) {
            abort(403);
        }

        if ($booking->source === BookingSource::GoogleCalendar) {
            throw ValidationException::withMessages([
                'booking' => __('External calendar events cannot be rescheduled from riservo.'),
            ]);
        }

        if (! $booking->status->canTransitionTo($booking->status) && ! in_array(
            $booking->status,
            [BookingStatus::Pending, BookingStatus::Confirmed],
            true,
        )) {
            // Pending/Confirmed are the only statuses that block availability
            // (D-031) and therefore the only statuses it makes sense to move.
            throw ValidationException::withMessages([
                'booking' => __('Only pending or confirmed bookings can be rescheduled.'),
            ]);
        }

        if ($booking->provider?->trashed()) {
            throw ValidationException::withMessages([
                'booking' => __('This booking belongs to a deactivated provider and cannot be rescheduled.'),
            ]);
        }

        $service = $booking->service;
        if ($service === null) {
            throw ValidationException::withMessages([
                'booking' => __('This booking has no service attached and cannot be rescheduled.'),
            ]);
        }

        $durationMinutes = (int) $request->validated('duration_minutes');
        $timezone = $business->timezone;
        $startsAtUtc = CarbonImmutable::parse($request->validated('starts_at'))->setTimezone('UTC');
        $startsAtLocal = $startsAtUtc->setTimezone($timezone);
        $endsAtLocal = $startsAtLocal->addMinutes($durationMinutes);

        $interval = $service->slot_interval_minutes;
        if ($startsAtLocal->minute % $interval !== 0 || $startsAtLocal->second !== 0) {
            throw ValidationException::withMessages([
                'starts_at' => __('Start time must align with the :minutes-minute grid.', [
                    'minutes' => $interval,
                ]),
            ]);
        }

        if ($durationMinutes % $interval !== 0) {
            throw ValidationException::withMessages([
                'duration_minutes' => __('Duration must be a multiple of :minutes minutes.', [
                    'minutes' => $interval,
                ]),
            ]);
        }

        if (! $startsAtLocal->isSameDay($endsAtLocal->subSecond())) {
            throw ValidationException::withMessages([
                'booking' => __('A booking cannot straddle two days.'),
            ]);
        }

        $endsAtUtc = $startsAtUtc->addMinutes($durationMinutes);
        $previousStartsAt = $booking->starts_at->copy();
        $previousEndsAt = $booking->ends_at->copy();

        try {
            DB::transaction(function () use (
                $business, $service, $booking, $startsAtLocal, $startsAtUtc, $endsAtUtc, $durationMinutes,
            ) {
                // Validate the *requested* window, not a service-duration
                // slot. A resize (duration_minutes != service.duration_minutes)
                // still has to fit inside the provider's availability window
                // and avoid other bookings. canFitBooking() takes the actual
                // duration and does both checks.
                $fits = $this->slotGenerator->canFitBooking(
                    $business,
                    $service,
                    $booking->provider,
                    $startsAtLocal,
                    $durationMinutes,
                    excluding: $booking,
                );

                if (! $fits) {
                    throw ValidationException::withMessages([
                        'booking' => __('That slot is not available. Pick another time.'),
                    ]);
                }

                $booking->forceFill([
                    'starts_at' => $startsAtUtc,
                    'ends_at' => $endsAtUtc,
                ])->save();
            });
        } catch (QueryException $e) {
            // GIST (D-065/D-066) is the race-safe backstop. Inertia reserves
            // 409 for asset-version / external-redirect semantics, which
            // confuses useHttp clients — translate the race to 422 matching
            // the pre-check's response path. UX surfaces one kind of error.
            if (($e->getPrevious()?->getCode() ?? $e->getCode()) === '23P01') {
                throw ValidationException::withMessages([
                    'booking' => __('This slot was just taken. Pick another time.'),
                ]);
            }
            throw $e;
        }

        $booking->refresh();

        if (! $booking->shouldSuppressCustomerNotifications() && $booking->customer) {
            Notification::route('mail', $booking->customer->email)
                ->notify(new BookingRescheduledNotification(
                    $booking,
                    $previousStartsAt,
                    $previousEndsAt,
                ));
        }

        if ($booking->shouldPushToCalendar()) {
            PushBookingToCalendarJob::dispatch($booking->id, 'update');
        }

        return response()->json([
            'booking' => $this->bookingPayload($booking),
        ]);
    }

    /**
     * Shared payload shape used by reschedule + calendar index (future
     * extensions). Kept private so the calendar index retains its explicit
     * shape today — only new endpoints use this.
     *
     * @return array<string, mixed>
     */
    private function bookingPayload(Booking $booking): array
    {
        $booking->loadMissing(['service:id,name,duration_minutes,price', 'provider.user:id,name,avatar', 'customer:id,name,email,phone']);

        return [
            'id' => $booking->id,
            'starts_at' => $booking->starts_at->toIso8601String(),
            'ends_at' => $booking->ends_at->toIso8601String(),
            'status' => $booking->status->value,
            'source' => $booking->source->value,
            'external' => $booking->source === BookingSource::GoogleCalendar,
            'external_title' => $booking->external_title,
            'external_html_link' => $booking->external_html_link,
            'notes' => $booking->notes,
            'internal_notes' => $booking->internal_notes,
            'created_at' => $booking->created_at->toIso8601String(),
            'cancellation_token' => $booking->cancellation_token,
            'service' => $booking->service
                ? [
                    'id' => $booking->service->id,
                    'name' => $booking->service->name,
                    'duration_minutes' => $booking->service->duration_minutes,
                    'price' => $booking->service->price,
                ]
                : null,
            'provider' => [
                'id' => $booking->provider->id,
                'name' => $booking->provider->user->name ?? '',
                'avatar_url' => $booking->provider->user?->avatar
                    ? Storage::disk('public')->url($booking->provider->user->avatar)
                    : null,
                'is_active' => ! $booking->provider->trashed(),
            ],
            'customer' => $booking->customer
                ? [
                    'id' => $booking->customer->id,
                    'name' => $booking->customer->name,
                    'email' => $booking->customer->email,
                    'phone' => $booking->customer->phone,
                ]
                : null,
        ];
    }

    public function updateNotes(Request $request, Booking $booking): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $business = tenant()->business();

        abort_unless($booking->business_id === $business->id, 404);

        $isAdmin = tenant()->role()->value === 'admin';
        if (! $isAdmin && $booking->provider?->user_id !== $user->id) {
            abort(403);
        }

        $validated = $request->validate([
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $booking->update(['internal_notes' => $validated['internal_notes']]);

        return back()->with('success', __('Notes updated.'));
    }

    public function store(StoreManualBookingRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $business = tenant()->business();
        $validated = $request->validated();

        $service = $business->services()
            ->where('id', $validated['service_id'])
            ->where('is_active', true)
            ->firstOrFail();

        $timezone = $business->timezone;
        $startsAt = CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            $validated['date'].' '.$validated['time'],
            $timezone,
        )->setTimezone('UTC');

        $endsAt = $startsAt->addMinutes($service->duration_minutes);

        $selectedProvider = null;
        if (! empty($validated['provider_id'])) {
            $selectedProvider = $service->providers()
                ->where('providers.id', $validated['provider_id'])
                ->where('providers.business_id', $business->id)
                ->first();

            if (! $selectedProvider) {
                return back()->with('error', __('Selected provider is not available for this service.'));
            }
        }

        $dateInTz = CarbonImmutable::createFromFormat('Y-m-d', $validated['date'], $timezone)->startOfDay();
        $requestedTime = CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            $validated['date'].' '.$validated['time'],
            $timezone,
        );

        try {
            [$booking, $customer] = DB::transaction(function () use (
                $business, $service, $selectedProvider, $validated,
                $startsAt, $endsAt, $dateInTz, $requestedTime,
            ) {
                $availableSlots = $this->slotGenerator->getAvailableSlots($business, $service, $dateInTz, $selectedProvider);

                $slotAvailable = collect($availableSlots)->contains(fn (CarbonImmutable $slot) => $slot->eq($requestedTime));

                if (! $slotAvailable) {
                    throw new SlotNoLongerAvailableException;
                }

                $provider = $selectedProvider;
                if (! $provider) {
                    $provider = $this->slotGenerator->assignProvider($business, $service, $requestedTime);

                    if (! $provider) {
                        throw new NoProviderAvailableException;
                    }
                }

                $customer = Customer::firstOrCreate(
                    ['email' => $validated['customer_email']],
                    ['name' => $validated['customer_name'], 'phone' => $validated['customer_phone'] ?? null],
                );

                if ($customer->name !== $validated['customer_name'] || ($validated['customer_phone'] ?? null) !== $customer->phone) {
                    $customer->update([
                        'name' => $validated['customer_name'],
                        'phone' => $validated['customer_phone'] ?? null,
                    ]);
                }

                $booking = Booking::create([
                    'business_id' => $business->id,
                    'provider_id' => $provider->id,
                    'service_id' => $service->id,
                    'customer_id' => $customer->id,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'buffer_before_minutes' => $service->buffer_before ?? 0,
                    'buffer_after_minutes' => $service->buffer_after ?? 0,
                    'status' => BookingStatus::Confirmed,
                    'source' => BookingSource::Manual,
                    // Locked roadmap decision #30: manual bookings are ALWAYS
                    // offline regardless of Business.payment_mode — the customer
                    // is not in front of the staff member to authorise a charge.
                    // Post-hoc online payment links are tracked in BACKLOG.
                    'payment_status' => PaymentStatus::NotApplicable,
                    'payment_mode_at_creation' => 'offline',
                    'notes' => $validated['notes'] ?? null,
                    'cancellation_token' => Str::uuid()->toString(),
                ]);

                return [$booking, $customer];
            });
        } catch (SlotNoLongerAvailableException) {
            return back()->with('error', __('This time slot is no longer available. Please select another time.'));
        } catch (NoProviderAvailableException) {
            return back()->with('error', __('No provider is available for this time slot.'));
        } catch (QueryException $e) {
            if (($e->getPrevious()?->getCode() ?? $e->getCode()) === '23P01') {
                return back()->with('error', __('This time slot is no longer available. Please select another time.'));
            }
            throw $e;
        }

        if (! $booking->shouldSuppressCustomerNotifications()) {
            Notification::route('mail', $customer->email)
                ->notify(new BookingConfirmedNotification($booking));

            $this->notifyStaff($booking, new BookingReceivedNotification($booking, 'new'), $user->id);
        }

        if ($booking->shouldPushToCalendar()) {
            PushBookingToCalendarJob::dispatch($booking->id, 'create');
        }

        return redirect()->route('dashboard.bookings')
            ->with('success', __('Booking created successfully.'));
    }

    public function availableDates(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $business = tenant()->business();

        $request->validate([
            'service_id' => ['required', 'integer'],
            'provider_id' => ['nullable', 'integer'],
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $service = $business->services()
            ->where('id', $request->integer('service_id'))
            ->where('is_active', true)
            ->firstOrFail();

        $provider = $request->filled('provider_id')
            ? $business->providers()->where('id', $request->integer('provider_id'))->firstOrFail()
            : null;

        $timezone = $business->timezone;
        $monthStart = CarbonImmutable::createFromFormat('Y-m', $request->string('month'), $timezone)->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $dates = [];
        $current = $monthStart;

        while ($current->lte($monthEnd)) {
            $dateKey = $current->format('Y-m-d');

            if ($current->lt($today)) {
                $dates[$dateKey] = false;
            } else {
                $slots = $this->slotGenerator->getAvailableSlots($business, $service, $current, $provider);
                $dates[$dateKey] = ! empty($slots);
            }

            $current = $current->addDay();
        }

        return response()->json(['dates' => $dates]);
    }

    public function slots(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $business = tenant()->business();

        $request->validate([
            'service_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'provider_id' => ['nullable', 'integer'],
        ]);

        $service = $business->services()
            ->where('id', $request->integer('service_id'))
            ->where('is_active', true)
            ->firstOrFail();

        $provider = $request->filled('provider_id')
            ? $business->providers()->where('id', $request->integer('provider_id'))->firstOrFail()
            : null;

        $timezone = $business->timezone;
        $date = CarbonImmutable::createFromFormat('Y-m-d', $request->string('date'), $timezone)->startOfDay();

        $today = CarbonImmutable::now($timezone)->startOfDay();
        if ($date->lt($today)) {
            return response()->json(['slots' => [], 'timezone' => $timezone]);
        }

        $slotTimes = $this->slotGenerator->getAvailableSlots($business, $service, $date, $provider);

        $slots = array_map(fn (CarbonImmutable $slot) => $slot->format('H:i'), $slotTimes);

        return response()->json(['slots' => $slots, 'timezone' => $timezone]);
    }

    private function notifyStaff(Booking $booking, BookingReceivedNotification $notification, ?int $excludeUserId = null): void
    {
        $booking->loadMissing(['business.admins', 'provider.user']);

        $staffUsers = $booking->business->admins
            ->when($booking->provider?->user, fn ($c) => $c->merge([$booking->provider->user]))
            ->unique('id')
            ->when($excludeUserId, fn ($c) => $c->where('id', '!=', $excludeUserId));

        Notification::send($staffUsers, $notification);
    }

    /**
     * Refund row Inertia payload (D-184 / G-001).
     *
     * Extracted into a method so the closure inside `bookings.through(...)`
     * has a single, statically-typed return shape. PHPStan struggled to
     * unify the nested Collection covariance otherwise.
     *
     * @return array{
     *     id: int,
     *     created_at: string,
     *     amount_cents: int,
     *     currency: string,
     *     status: string,
     *     reason: string,
     *     initiator_name: string|null,
     *     stripe_refund_id_last4: string|null,
     *     has_stripe_link: bool,
     * }
     */
    private function refundRowPayload(BookingRefund $r, Booking $booking): array
    {
        $refundId = is_string($r->stripe_refund_id) ? $r->stripe_refund_id : '';
        $accountId = is_string($booking->stripe_connected_account_id) ? $booking->stripe_connected_account_id : '';

        return [
            'id' => $r->id,
            'created_at' => $r->created_at->toIso8601String(),
            'amount_cents' => $r->amount_cents,
            'currency' => $r->currency,
            'status' => $r->status->value,
            'reason' => $r->reason,
            'initiator_name' => $r->initiatedByUser?->name,
            'stripe_refund_id_last4' => $refundId !== '' ? substr($refundId, -4) : null,
            'has_stripe_link' => $refundId !== '' && $accountId !== '',
        ];
    }

    /**
     * D-184 (PAYMENTS Hardening Round 2): the urgent payment Pending Action
     * payload may carry semi-secret Stripe identifiers (payment_intent id,
     * charge id) the UI does not need. Whitelist by PA type so the prop
     * carries only the keys the React banner consumes.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function whitelistPaymentPaPayload(PendingActionType $type, array $payload): array
    {
        // The keys consumed by the React banner today, by PA type:
        //   payment.cancelled_after_payment / payment.refund_failed →
        //     amount_cents, currency, failure_reason, refund_amount_cents
        //   (no stripe_*_id rides the banner UI; the deeplink button uses
        //   the booking's `has_stripe_payment_link` prop + the redirect
        //   endpoint to reach Stripe.)
        $allowed = [
            'amount_cents',
            'currency',
            'failure_reason',
            'refund_amount_cents',
        ];

        return array_intersect_key($payload, array_flip($allowed));
    }

    /**
     * D-184 (PAYMENTS Hardening Round 2): the dispute PA payload exposes
     * dispute metadata to the operator banner. Whitelist the keys + truncate
     * the dispute id; the deeplink itself goes through the server-side
     * redirect endpoint, which reads the raw id from the PA.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function whitelistDisputePaPayload(array $payload): array
    {
        $allowed = [
            'amount',
            'currency',
            'reason',
            'status',
            'evidence_due_by',
        ];

        $out = array_intersect_key($payload, array_flip($allowed));

        $disputeId = $payload['dispute_id'] ?? null;
        if (is_string($disputeId) && $disputeId !== '') {
            $out['dispute_id_last4'] = substr($disputeId, -4);
        }

        return $out;
    }
}
