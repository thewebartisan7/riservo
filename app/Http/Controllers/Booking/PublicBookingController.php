<?php

namespace App\Http\Controllers\Booking;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\ConfirmationMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\StorePublicBookingRequest;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use App\Services\SlotGeneratorService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PublicBookingController extends Controller
{
    public function __construct(
        private SlotGeneratorService $slotGenerator,
    ) {}

    public function show(string $slug, ?string $serviceSlug = null): Response
    {
        $business = $this->resolveBusiness($slug);

        $services = $business->services()
            ->where('is_active', true)
            ->withCount('collaborators')
            ->get();

        $preSelectedServiceSlug = null;
        if ($serviceSlug && $services->contains('slug', $serviceSlug)) {
            $preSelectedServiceSlug = $serviceSlug;
        }

        $customerPrefill = null;
        if ($user = auth()->user()) {
            /** @var User $user */
            $customer = Customer::where('user_id', $user->id)->first();
            if ($customer) {
                $customerPrefill = [
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                ];
            }
        }

        return Inertia::render('booking/show', [
            'business' => [
                'name' => $business->name,
                'slug' => $business->slug,
                'description' => $business->description,
                'logo_url' => $business->logo
                    ? asset('storage/'.$business->logo)
                    : null,
                'phone' => $business->phone,
                'email' => $business->email,
                'address' => $business->address,
                'timezone' => $business->timezone,
                'allow_collaborator_choice' => $business->allow_collaborator_choice,
                'confirmation_mode' => $business->confirmation_mode->value,
            ],
            'services' => $services->map(fn (Service $service) => [
                'id' => $service->id,
                'name' => $service->name,
                'slug' => $service->slug,
                'description' => $service->description,
                'duration_minutes' => $service->duration_minutes,
                'price' => $service->price,
            ])->values(),
            'preSelectedServiceSlug' => $preSelectedServiceSlug,
            'customerPrefill' => $customerPrefill,
            'embed' => (bool) request('embed'),
        ]);
    }

    public function collaborators(string $slug, Request $request): JsonResponse
    {
        $business = $this->resolveBusiness($slug);

        $request->validate([
            'service_id' => ['required', 'integer'],
        ]);

        $service = $business->services()
            ->where('id', $request->integer('service_id'))
            ->where('is_active', true)
            ->firstOrFail();

        $collaborators = $service->collaborators()->get()->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'avatar_url' => $user->avatar
                ? asset('storage/'.$user->avatar)
                : null,
        ]);

        return response()->json(['collaborators' => $collaborators->values()]);
    }

    public function availableDates(string $slug, Request $request): JsonResponse
    {
        $business = $this->resolveBusiness($slug);

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

    public function slots(string $slug, Request $request): JsonResponse
    {
        $business = $this->resolveBusiness($slug);

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

    public function store(string $slug, StorePublicBookingRequest $request): JsonResponse
    {
        $business = $this->resolveBusiness($slug);
        $validated = $request->validated();

        // Honeypot check — see D-045
        if (! empty($validated['website'])) {
            return response()->json(['message' => __('Something went wrong.')], 422);
        }

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
                return response()->json(['message' => __('Selected collaborator is not available for this service.')], 409);
            }
        }

        // Re-verify slot availability (race condition protection)
        $dateInTz = CarbonImmutable::createFromFormat('Y-m-d', $validated['date'], $timezone)->startOfDay();
        $availableSlots = $this->slotGenerator->getAvailableSlots($business, $service, $dateInTz, $collaborator);

        $requestedTime = CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            $validated['date'].' '.$validated['time'],
            $timezone,
        );

        $slotAvailable = collect($availableSlots)->contains(fn (CarbonImmutable $slot) => $slot->eq($requestedTime));

        if (! $slotAvailable) {
            return response()->json([
                'message' => __('This time slot is no longer available. Please select another time.'),
            ], 409);
        }

        // Auto-assign collaborator if not specified
        if (! $collaborator) {
            $collaborator = $this->slotGenerator->assignCollaborator($business, $service, $requestedTime);

            if (! $collaborator) {
                return response()->json([
                    'message' => __('No collaborator is available for this time slot.'),
                ], 409);
            }
        }

        // Find or create customer
        $customer = Customer::firstOrCreate(
            ['email' => $validated['email']],
            ['name' => $validated['name'], 'phone' => $validated['phone']],
        );

        // Update name/phone if changed
        if ($customer->name !== $validated['name'] || $customer->phone !== $validated['phone']) {
            $customer->update([
                'name' => $validated['name'],
                'phone' => $validated['phone'],
            ]);
        }

        // Link to authenticated user if applicable
        if (auth()->check() && ! $customer->user_id) {
            $customer->update(['user_id' => auth()->id()]);
        }

        // Determine booking status based on confirmation mode
        $status = $business->confirmation_mode === ConfirmationMode::Auto
            ? BookingStatus::Confirmed
            : BookingStatus::Pending;

        $cancellationToken = Str::uuid()->toString();

        $booking = Booking::create([
            'business_id' => $business->id,
            'collaborator_id' => $collaborator->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => $status,
            'source' => BookingSource::Riservo,
            'payment_status' => 'pending',
            'notes' => $validated['notes'] ?? null,
            'cancellation_token' => $cancellationToken,
        ]);

        // Send placeholder confirmation email (Session 10 replaces)
        Notification::route('mail', $customer->email)
            ->notify(new BookingConfirmedNotification($booking));

        return response()->json([
            'token' => $cancellationToken,
            'redirect_url' => route('bookings.show', $cancellationToken),
            'status' => $status->value,
        ], 201);
    }

    private function resolveBusiness(string $slug): Business
    {
        $business = Business::where('slug', $slug)->firstOrFail();

        abort_unless($business->isOnboarded(), 404);

        return $business;
    }
}
