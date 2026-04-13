<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\StoreManualBookingRequest;
use App\Http\Requests\Dashboard\UpdateBookingStatusRequest;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use App\Services\SlotGeneratorService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
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
        $business = $user->currentBusiness();
        $isAdmin = $user->currentBusinessRole()->value === 'admin';

        $query = Booking::where('business_id', $business->id)
            ->with([
                'service:id,name,duration_minutes,price',
                'collaborator:id,name,avatar',
                'customer:id,name,email,phone',
            ]);

        if (! $isAdmin) {
            $query->where('collaborator_id', $user->id);
        }

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('service_id')) {
            $query->where('service_id', $request->integer('service_id'));
        }

        if ($request->filled('collaborator_id') && $isAdmin) {
            $query->where('collaborator_id', $request->integer('collaborator_id'));
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

        // Sort
        $sortField = $request->string('sort', 'starts_at');
        $sortDir = $request->string('direction', 'desc');
        $allowedSorts = ['starts_at', 'created_at'];
        if (in_array($sortField->toString(), $allowedSorts, true)) {
            $query->orderBy($sortField->toString(), $sortDir->toString() === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('starts_at');
        }

        $bookings = $query->paginate(20)->withQueryString();

        // Data for filter dropdowns and manual booking dialog
        $services = $business->services()
            ->where('is_active', true)
            ->with('collaborators:users.id,users.name,users.avatar')
            ->get(['id', 'name', 'duration_minutes', 'price', 'slug']);

        $collaborators = $isAdmin
            ? $business->users()
                ->wherePivotIn('role', ['admin', 'collaborator'])
                ->get(['users.id', 'users.name'])
            : collect();

        return Inertia::render('dashboard/bookings', [
            'bookings' => $bookings->through(fn (Booking $booking) => [
                'id' => $booking->id,
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
                'status' => $booking->status->value,
                'source' => $booking->source->value,
                'notes' => $booking->notes,
                'internal_notes' => $booking->internal_notes,
                'created_at' => $booking->created_at->toIso8601String(),
                'cancellation_token' => $booking->cancellation_token,
                'service' => [
                    'id' => $booking->service->id,
                    'name' => $booking->service->name,
                    'duration_minutes' => $booking->service->duration_minutes,
                    'price' => $booking->service->price,
                ],
                'collaborator' => [
                    'id' => $booking->collaborator->id,
                    'name' => $booking->collaborator->name,
                    'avatar_url' => $booking->collaborator->avatar
                        ? asset('storage/'.$booking->collaborator->avatar)
                        : null,
                ],
                'customer' => [
                    'id' => $booking->customer->id,
                    'name' => $booking->customer->name,
                    'email' => $booking->customer->email,
                    'phone' => $booking->customer->phone,
                ],
            ]),
            'services' => $services->map(fn (Service $service) => [
                'id' => $service->id,
                'name' => $service->name,
                'duration_minutes' => $service->duration_minutes,
                'price' => $service->price,
                'collaborators' => $service->collaborators->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                ])->values(),
            ])->values(),
            'collaborators' => $collaborators->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
            ])->values(),
            'filters' => [
                'status' => $request->string('status', ''),
                'service_id' => $request->string('service_id', ''),
                'collaborator_id' => $request->string('collaborator_id', ''),
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
        $business = $user->currentBusiness();

        abort_unless($booking->business_id === $business->id, 404);

        $isAdmin = $user->currentBusinessRole()->value === 'admin';
        if (! $isAdmin && $booking->collaborator_id !== $user->id) {
            abort(403);
        }

        $newStatus = BookingStatus::from($request->validated('status'));

        if (! $booking->status->canTransitionTo($newStatus)) {
            return back()->with('error', __('Cannot change status from :from to :to.', [
                'from' => $booking->status->label(),
                'to' => $newStatus->label(),
            ]));
        }

        $booking->update(['status' => $newStatus]);

        return back()->with('success', __('Booking status updated to :status.', [
            'status' => $newStatus->label(),
        ]));
    }

    public function updateNotes(Request $request, Booking $booking): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $business = $user->currentBusiness();

        abort_unless($booking->business_id === $business->id, 404);

        $isAdmin = $user->currentBusinessRole()->value === 'admin';
        if (! $isAdmin && $booking->collaborator_id !== $user->id) {
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
        $business = $user->currentBusiness();
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

        // Resolve collaborator
        $collaborator = null;
        if (! empty($validated['collaborator_id'])) {
            $collaborator = $service->collaborators()
                ->where('users.id', $validated['collaborator_id'])
                ->first();

            if (! $collaborator) {
                return back()->with('error', __('Selected collaborator is not available for this service.'));
            }
        }

        // Verify slot availability
        $dateInTz = CarbonImmutable::createFromFormat('Y-m-d', $validated['date'], $timezone)->startOfDay();
        $availableSlots = $this->slotGenerator->getAvailableSlots($business, $service, $dateInTz, $collaborator);

        $requestedTime = CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            $validated['date'].' '.$validated['time'],
            $timezone,
        );

        $slotAvailable = collect($availableSlots)->contains(fn (CarbonImmutable $slot) => $slot->eq($requestedTime));

        if (! $slotAvailable) {
            return back()->with('error', __('This time slot is no longer available. Please select another time.'));
        }

        // Auto-assign collaborator if not specified
        if (! $collaborator) {
            $collaborator = $this->slotGenerator->assignCollaborator($business, $service, $requestedTime);

            if (! $collaborator) {
                return back()->with('error', __('No collaborator is available for this time slot.'));
            }
        }

        // Find or create customer — see D-004
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

        $cancellationToken = Str::uuid()->toString();

        // Manual bookings are always confirmed — see D-051
        $booking = Booking::create([
            'business_id' => $business->id,
            'collaborator_id' => $collaborator->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => BookingStatus::Confirmed,
            'source' => BookingSource::Manual,
            'payment_status' => 'pending',
            'notes' => $validated['notes'] ?? null,
            'cancellation_token' => $cancellationToken,
        ]);

        // Send placeholder confirmation email (Session 10 replaces)
        Notification::route('mail', $customer->email)
            ->notify(new BookingConfirmedNotification($booking));

        return redirect()->route('dashboard.bookings')
            ->with('success', __('Booking created successfully.'));
    }

    public function availableDates(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $business = $user->currentBusiness();

        $request->validate([
            'service_id' => ['required', 'integer'],
            'collaborator_id' => ['nullable', 'integer'],
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $service = $business->services()
            ->where('id', $request->integer('service_id'))
            ->where('is_active', true)
            ->firstOrFail();

        $collaborator = $request->filled('collaborator_id')
            ? User::findOrFail($request->integer('collaborator_id'))
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
                $slots = $this->slotGenerator->getAvailableSlots($business, $service, $current, $collaborator);
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
        $business = $user->currentBusiness();

        $request->validate([
            'service_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'collaborator_id' => ['nullable', 'integer'],
        ]);

        $service = $business->services()
            ->where('id', $request->integer('service_id'))
            ->where('is_active', true)
            ->firstOrFail();

        $collaborator = $request->filled('collaborator_id')
            ? User::findOrFail($request->integer('collaborator_id'))
            : null;

        $timezone = $business->timezone;
        $date = CarbonImmutable::createFromFormat('Y-m-d', $request->string('date'), $timezone)->startOfDay();

        $today = CarbonImmutable::now($timezone)->startOfDay();
        if ($date->lt($today)) {
            return response()->json(['slots' => [], 'timezone' => $timezone]);
        }

        $slotTimes = $this->slotGenerator->getAvailableSlots($business, $service, $date, $collaborator);

        $slots = array_map(fn (CarbonImmutable $slot) => $slot->format('H:i'), $slotTimes);

        return response()->json(['slots' => $slots, 'timezone' => $timezone]);
    }
}
