<?php

namespace App\Http\Controllers\Booking;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\ConfirmationMode;
use App\Exceptions\Booking\NoProviderAvailableException;
use App\Exceptions\Booking\SlotNoLongerAvailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\StorePublicBookingRequest;
use App\Jobs\Calendar\PushBookingToCalendarJob;
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
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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
            ->structurallyBookable()
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
                    ? Storage::disk('public')->url($business->logo)
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

        if (! $business->allow_provider_choice) {
            return response()->json(['providers' => []]);
        }

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
                    ? Storage::disk('public')->url($provider->user->avatar)
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

        $provider = $this->resolveProviderIfChoiceAllowed(
            $business,
            $request->filled('provider_id') ? $request->integer('provider_id') : null,
        );

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

        $provider = $this->resolveProviderIfChoiceAllowed(
            $business,
            $request->filled('provider_id') ? $request->integer('provider_id') : null,
        );

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

        $selectedProvider = $this->resolveProviderIfChoiceAllowed(
            $business,
            isset($validated['provider_id']) ? (int) $validated['provider_id'] : null,
        );

        if ($selectedProvider) {
            $serviceMember = $service->providers()
                ->where('providers.id', $selectedProvider->id)
                ->where('providers.business_id', $business->id)
                ->exists();

            if (! $serviceMember) {
                return response()->json(['message' => __('Selected provider is not available for this service.')], 409);
            }
        }

        $dateInTz = CarbonImmutable::createFromFormat('Y-m-d', $validated['date'], $timezone)->startOfDay();
        $requestedTime = CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            $validated['date'].' '.$validated['time'],
            $timezone,
        );

        $authUserId = auth()->check() ? auth()->id() : null;

        try {
            [$booking, $customer] = DB::transaction(function () use (
                $business, $service, $selectedProvider, $validated,
                $startsAt, $endsAt, $dateInTz, $requestedTime, $authUserId,
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
                    ['email' => $validated['email']],
                    ['name' => $validated['name'], 'phone' => $validated['phone']],
                );

                if ($customer->name !== $validated['name'] || $customer->phone !== $validated['phone']) {
                    $customer->update([
                        'name' => $validated['name'],
                        'phone' => $validated['phone'],
                    ]);
                }

                if ($authUserId && ! $customer->user_id) {
                    $customer->update(['user_id' => $authUserId]);
                }

                $status = $business->confirmation_mode === ConfirmationMode::Auto
                    ? BookingStatus::Confirmed
                    : BookingStatus::Pending;

                $booking = Booking::create([
                    'business_id' => $business->id,
                    'provider_id' => $provider->id,
                    'service_id' => $service->id,
                    'customer_id' => $customer->id,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'buffer_before_minutes' => $service->buffer_before ?? 0,
                    'buffer_after_minutes' => $service->buffer_after ?? 0,
                    'status' => $status,
                    'source' => BookingSource::Riservo,
                    'payment_status' => 'pending',
                    'notes' => $validated['notes'] ?? null,
                    'cancellation_token' => Str::uuid()->toString(),
                ]);

                return [$booking, $customer];
            });
        } catch (SlotNoLongerAvailableException) {
            return response()->json([
                'message' => __('This time slot is no longer available. Please select another time.'),
            ], 409);
        } catch (NoProviderAvailableException) {
            return response()->json([
                'message' => __('No provider is available for this time slot.'),
            ], 409);
        } catch (QueryException $e) {
            if (($e->getPrevious()?->getCode() ?? $e->getCode()) === '23P01') {
                return response()->json([
                    'message' => __('This time slot is no longer available. Please select another time.'),
                ], 409);
            }
            throw $e;
        }

        if (! $booking->shouldSuppressCustomerNotifications() && $booking->status === BookingStatus::Confirmed) {
            Notification::route('mail', $customer->email)
                ->notify(new BookingConfirmedNotification($booking));
        }

        if (! $booking->shouldSuppressCustomerNotifications()) {
            $this->notifyStaff($booking);
        }

        // Confirmed bookings push immediately; pending bookings push on confirmation
        // (admin action in Dashboard\BookingController::updateStatus).
        if ($booking->status === BookingStatus::Confirmed && $booking->shouldPushToCalendar()) {
            PushBookingToCalendarJob::dispatch($booking->id, 'create');
        }

        return response()->json([
            'token' => $booking->cancellation_token,
            'redirect_url' => route('bookings.show', $booking->cancellation_token),
            'status' => $booking->status->value,
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

    private function resolveProviderIfChoiceAllowed(Business $business, ?int $providerId): ?Provider
    {
        if (! $business->allow_provider_choice || ! $providerId) {
            return null;
        }

        return $business->providers()->where('id', $providerId)->firstOrFail();
    }
}
