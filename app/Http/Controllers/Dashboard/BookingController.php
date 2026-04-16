<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Exceptions\Booking\NoProviderAvailableException;
use App\Exceptions\Booking\SlotNoLongerAvailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\StoreManualBookingRequest;
use App\Http\Requests\Dashboard\UpdateBookingStatusRequest;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use App\Notifications\BookingCancelledNotification;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\BookingReceivedNotification;
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
                'provider' => [
                    'id' => $booking->provider->id,
                    'name' => $booking->provider->user?->name ?? '',
                    'avatar_url' => $booking->provider->user?->avatar
                        ? Storage::disk('public')->url($booking->provider->user->avatar)
                        : null,
                    'is_active' => ! $booking->provider->trashed(),
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
                'providers' => $service->providers->map(fn (Provider $p) => [
                    'id' => $p->id,
                    'name' => $p->user?->name ?? '',
                ])->values(),
            ])->values(),
            'providers' => $providers->map(fn (Provider $p) => [
                'id' => $p->id,
                'name' => $p->user?->name ?? '',
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

        $booking->update(['status' => $newStatus]);

        $booking->loadMissing(['customer', 'business.admins', 'provider.user']);

        if ($newStatus === BookingStatus::Confirmed) {
            Notification::route('mail', $booking->customer->email)
                ->notify(new BookingConfirmedNotification($booking));

            $this->notifyStaff($booking, new BookingReceivedNotification($booking, 'confirmed'), $user->id);
        }

        if ($newStatus === BookingStatus::Cancelled) {
            Notification::route('mail', $booking->customer->email)
                ->notify(new BookingCancelledNotification($booking, 'business'));
        }

        return back()->with('success', __('Booking status updated to :status.', [
            'status' => $newStatus->label(),
        ]));
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
                    'payment_status' => 'pending',
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

        Notification::route('mail', $customer->email)
            ->notify(new BookingConfirmedNotification($booking));

        $this->notifyStaff($booking, new BookingReceivedNotification($booking, 'new'), $user->id);

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
