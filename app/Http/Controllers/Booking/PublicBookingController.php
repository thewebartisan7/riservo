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
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\BookingReceivedNotification;
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
            ->whereHas('providers')
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
                'allow_provider_choice' => $business->allow_provider_choice,
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

    public function providers(string $slug, Request $request): JsonResponse
    {
        $business = $this->resolveBusiness($slug);

        $request->validate([
            'service_id' => ['required', 'integer'],
        ]);

        $service = $business->services()
            ->where('id', $request->integer('service_id'))
            ->where('is_active', true)
            ->firstOrFail();

        $providers = $service->providers()
            ->where('providers.business_id', $business->id)
            ->with('user:id,name,avatar')
            ->get()
            ->map(fn (Provider $provider) => [
                'id' => $provider->id,
                'name' => $provider->user?->name ?? '',
                'avatar_url' => $provider->user?->avatar
                    ? asset('storage/'.$provider->user->avatar)
                    : null,
            ]);

        return response()->json(['providers' => $providers->values()]);
    }

    public function availableDates(string $slug, Request $request): JsonResponse
    {
        $business = $this->resolveBusiness($slug);

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

    public function slots(string $slug, Request $request): JsonResponse
    {
        $business = $this->resolveBusiness($slug);

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

    public function store(string $slug, StorePublicBookingRequest $request): JsonResponse
    {
        $business = $this->resolveBusiness($slug);
        $validated = $request->validated();

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

        $provider = null;
        if (! empty($validated['provider_id'])) {
            $provider = $service->providers()
                ->where('providers.id', $validated['provider_id'])
                ->where('providers.business_id', $business->id)
                ->first();

            if (! $provider) {
                return response()->json(['message' => __('Selected provider is not available for this service.')], 409);
            }
        }

        $dateInTz = CarbonImmutable::createFromFormat('Y-m-d', $validated['date'], $timezone)->startOfDay();
        $availableSlots = $this->slotGenerator->getAvailableSlots($business, $service, $dateInTz, $provider);

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

        if (! $provider) {
            $provider = $this->slotGenerator->assignProvider($business, $service, $requestedTime);

            if (! $provider) {
                return response()->json([
                    'message' => __('No provider is available for this time slot.'),
                ], 409);
            }
        }

        $customer = Customer::firstOrCreate(
            ['email' => $validated['email']],
            ['name' => $validated['name'], 'phone' => $validated['phone']],
        );

        if ($customer->name !== $validated['name'] || $customer->phone !== $validated['phone']) {
            $customer->update([
                'name' => $validated['name'],
                'phone' => $validated['phone'],
            ]);
        }

        if (auth()->check() && ! $customer->user_id) {
            $customer->update(['user_id' => auth()->id()]);
        }

        $status = $business->confirmation_mode === ConfirmationMode::Auto
            ? BookingStatus::Confirmed
            : BookingStatus::Pending;

        $cancellationToken = Str::uuid()->toString();

        $booking = Booking::create([
            'business_id' => $business->id,
            'provider_id' => $provider->id,
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

        if ($status === BookingStatus::Confirmed) {
            Notification::route('mail', $customer->email)
                ->notify(new BookingConfirmedNotification($booking));
        }

        $this->notifyStaff($booking);

        return response()->json([
            'token' => $cancellationToken,
            'redirect_url' => route('bookings.show', $cancellationToken),
            'status' => $status->value,
        ], 201);
    }

    private function notifyStaff(Booking $booking): void
    {
        $booking->loadMissing(['business.admins', 'provider.user']);

        $notification = new BookingReceivedNotification($booking, 'new');

        $staffUsers = $booking->business->admins
            ->when($booking->provider?->user, fn ($c) => $c->merge([$booking->provider->user]))
            ->unique('id');

        Notification::send($staffUsers, $notification);
    }

    private function resolveBusiness(string $slug): Business
    {
        $business = Business::where('slug', $slug)->firstOrFail();

        abort_unless($business->isOnboarded(), 404);

        return $business;
    }
}
