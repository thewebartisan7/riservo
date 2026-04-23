<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\Booking\NoProviderAvailableException;
use App\Exceptions\Booking\SlotNoLongerAvailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\RescheduleBookingRequest;
use App\Http\Requests\Dashboard\StoreManualBookingRequest;
use App\Http\Requests\Dashboard\UpdateBookingStatusRequest;
use App\Jobs\Calendar\PushBookingToCalendarJob;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use App\Notifications\BookingCancelledNotification;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\BookingReceivedNotification;
use App\Notifications\BookingRescheduledNotification;
use App\Services\SlotGeneratorService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

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

        if (! $isAdmin) {
            $query->whereHas('provider', fn ($q) => $q->where('user_id', $user->id));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
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
            'bookings' => $bookings->through(fn (Booking $booking) => [
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
            ]),
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

    public function updateStatus(UpdateBookingStatusRequest $request, Booking $booking): RedirectResponse
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

        // Codex Round 2 (D-159): admin-side cancellation of paid bookings
        // also stands the system at `cancelled + paid` until Session 3's
        // `RefundService` wires the automatic-full-refund path (locked
        // roadmap decision #17). Until Session 3 ships, block the
        // transition at the dashboard edge too — mirroring the customer-
        // facing guards on `BookingManagementController::cancel` (D-157)
        // and `Customer\BookingController::cancel` (this session). Admins
        // who truly need to cancel can refund manually in the Stripe
        // dashboard first, then use Session 3's flow when it lands.
        if ($newStatus === BookingStatus::Cancelled && $booking->payment_status === PaymentStatus::Paid) {
            return back()->with('error', __('This booking has been paid online. Automatic refunds ship in a later release — until then, refund the customer in the Stripe dashboard first, then cancel.'));
        }

        $booking->update(['status' => $newStatus]);

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
                Notification::route('mail', $booking->customer->email)
                    ->notify(new BookingCancelledNotification($booking, 'business'));
            }
        }

        // Push the booking change to Google Calendar. Cancel → delete;
        // confirm/complete/no-show → create-or-update. shouldPushToCalendar()
        // handles the "inbound origin" skip and the configured-integration gate.
        if ($booking->shouldPushToCalendar()) {
            $action = $newStatus === BookingStatus::Cancelled ? 'delete' : 'update';
            PushBookingToCalendarJob::dispatch($booking->id, $action);
        }

        return back()->with('success', __('Booking status updated to :status.', [
            'status' => $newStatus->label(),
        ]));
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
}
