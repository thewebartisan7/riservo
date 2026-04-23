<?php

namespace App\Http\Controllers\Booking;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\ConfirmationMode;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Exceptions\Booking\NoProviderAvailableException;
use App\Exceptions\Booking\SlotNoLongerAvailableException;
use App\Exceptions\Payments\UnsupportedCountryForCheckout;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\StorePublicBookingRequest;
use App\Jobs\Calendar\PushBookingToCalendarJob;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Service;
use App\Models\StripeConnectedAccount;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\BookingReceivedNotification;
use App\Services\Payments\CheckoutSessionFactory;
use App\Services\SlotGeneratorService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\ApiErrorException;

class PublicBookingController extends Controller
{
    public function __construct(
        private SlotGeneratorService $slotGenerator,
        private CheckoutSessionFactory $checkoutSessionFactory,
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
                // PAYMENTS Session 2a: the React booking flow branches on
                // these three to render the "Continue to payment" CTA and
                // the customer_choice pay-now/pay-on-site pill. The gate
                // folds in Stripe capabilities + country (D-127, D-138).
                'payment_mode' => $business->payment_mode->value,
                'can_accept_online_payments' => $business->canAcceptOnlinePayments(),
                'currency' => $business->stripeConnectedAccount?->default_currency,
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
                'name' => $provider->user->name ?? '',
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

        // PAYMENTS Session 2a: decide whether the booking needs online payment
        // BEFORE the transaction, because the answer governs the booking row's
        // status / payment_status / expires_at columns.
        //
        // Locked decisions bound here:
        //   - #8: service with price null / 0 cannot be booked online.
        //   - #14: payment_mode_at_creation mirrors Business.payment_mode at
        //          booking time; customer_choice + customer-picks-offline STILL
        //          snapshots 'customer_choice' (NOT 'offline'). The snapshot
        //          captures the Business's policy, not the customer's checkout
        //          choice.
        //   - #30: manual / google_calendar carve-out lives in other controllers;
        //          this controller only writes source = riservo.
        $connectedAccount = $business->stripeConnectedAccount;
        $canAcceptOnline = $business->canAcceptOnlinePayments();
        $paymentChoice = $validated['payment_choice'] ?? null;
        $servicePrice = $service->price !== null ? (float) $service->price : 0.0;
        $priceEligibleForOnline = $servicePrice > 0;

        $needsOnlinePayment = $canAcceptOnline
            && $priceEligibleForOnline
            && (
                $business->payment_mode === PaymentMode::Online
                || (
                    $business->payment_mode === PaymentMode::CustomerChoice
                    && ($paymentChoice ?? 'online') === 'online'
                )
            );

        try {
            [$booking, $customer] = DB::transaction(function () use (
                $business, $service, $selectedProvider, $validated,
                $startsAt, $endsAt, $dateInTz, $requestedTime, $authUserId,
                $needsOnlinePayment, $connectedAccount,
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

                $attrs = [
                    'business_id' => $business->id,
                    'provider_id' => $provider->id,
                    'service_id' => $service->id,
                    'customer_id' => $customer->id,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'buffer_before_minutes' => $service->buffer_before ?? 0,
                    'buffer_after_minutes' => $service->buffer_after ?? 0,
                    'source' => BookingSource::Riservo,
                    // Locked decision #14: the snapshot mirrors Business.payment_mode
                    // at booking time, regardless of the customer's checkout-step
                    // choice. It's immutable afterwards.
                    'payment_mode_at_creation' => $business->payment_mode->value,
                    'notes' => $validated['notes'] ?? null,
                    'cancellation_token' => Str::uuid()->toString(),
                ];

                if ($needsOnlinePayment) {
                    // Online-payment branch: booking sits Pending +
                    // AwaitingPayment until Checkout completes. Both
                    // confirmation modes start pending; the webhook +
                    // success-page promoter (M4) decides the final status.
                    //
                    // Locked decision #13: `expires_at` populates only when
                    // payment_mode_at_creation = 'online'. For
                    // customer_choice + pay-now, the reaper does not target
                    // this row (Session 2b) — the failure-branch turns into
                    // `confirmed + unpaid` instead.
                    //
                    // Codex Round 2 (D-158): pin the minting connected
                    // account id onto the booking so disconnect+reconnect
                    // history doesn't make the webhook cross-account guard
                    // non-deterministic.
                    $attrs += [
                        'status' => BookingStatus::Pending,
                        'payment_status' => PaymentStatus::AwaitingPayment,
                        'paid_amount_cents' => (int) round((float) $service->price * 100),
                        // Larastan narrows $connectedAccount to non-null here
                        // because $business->stripeConnectedAccount's HasOne
                        // generic resolves to the related model. The runtime
                        // null-guard lives in mintCheckoutOrRollback() after
                        // the transaction commits; this currency fallback
                        // only covers Stripe-side default_currency being
                        // temporarily null before the first account.retrieve.
                        'currency' => $connectedAccount->default_currency ?? 'chf',
                        'stripe_connected_account_id' => $connectedAccount->stripe_account_id,
                        'expires_at' => $business->payment_mode === PaymentMode::Online
                            ? now()->addMinutes(90)
                            : null,
                    ];
                } else {
                    // Offline / customer_choice-offline-pick / price-null
                    // branch: existing behaviour.
                    $attrs += [
                        'status' => $business->confirmation_mode === ConfirmationMode::Auto
                            ? BookingStatus::Confirmed
                            : BookingStatus::Pending,
                        'payment_status' => PaymentStatus::NotApplicable,
                    ];
                }

                $booking = Booking::create($attrs);

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

        // Online-payment branch: mint a Stripe Checkout session on the
        // connected account and redirect. Failures leave the slot released
        // (booking cancelled).
        if ($needsOnlinePayment) {
            return $this->mintCheckoutOrRollback($booking, $business, $service, $connectedAccount);
        }

        // Offline branch: existing notification + calendar-push flow.
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
            // Codex Round 2 (D-161): explicit external-redirect signal so
            // the client doesn't have to rely on a `https://` prefix
            // heuristic (HTTPS-deployed riservo offline URLs also start
            // with `https://`, so the heuristic would mistakenly skip the
            // confirmation step and hard-navigate for every booking).
            'external_redirect' => false,
            'status' => $booking->status->value,
        ], 201);
    }

    /**
     * PAYMENTS Session 2a: after the booking row commits as
     * `pending + awaiting_payment`, assert the connected account's country
     * is supported (locked decision #43 defense-in-depth) and mint a
     * Stripe Checkout session. On any failure, mark the booking `Cancelled`
     * so the slot is released — the GIST exclusion constraint only holds
     * while `status IN ('pending', 'confirmed')`.
     *
     * `$connectedAccount` is non-null by construction: the caller enters
     * this path only when `$needsOnlinePayment` is true, which requires
     * `canAcceptOnlinePayments()` to return true, which requires a
     * non-null `stripeConnectedAccount` relation (D-127 / D-138).
     */
    private function mintCheckoutOrRollback(
        Booking $booking,
        Business $business,
        Service $service,
        StripeConnectedAccount $connectedAccount,
    ): JsonResponse {
        try {
            $this->checkoutSessionFactory->assertSupportedCountry($connectedAccount);
            $session = $this->checkoutSessionFactory->create($booking, $service, $business, $connectedAccount);
            $booking->update(['stripe_checkout_session_id' => $session->id]);
        } catch (UnsupportedCountryForCheckout $e) {
            Log::critical('PublicBookingController: Checkout creation refused — connected account country not in supported set', [
                'booking_id' => $booking->id,
                'stripe_account_id' => $e->account->stripe_account_id,
                'country' => $e->account->country,
                'supported_countries' => $e->supported,
            ]);
            $this->releaseSlotFor($booking);

            return response()->json([
                'message' => __("Online payments aren't available for this business right now — please contact them directly."),
            ], 422);
        } catch (ApiErrorException $e) {
            report($e);
            $this->releaseSlotFor($booking);

            return response()->json([
                'message' => __("Couldn't start payment. Please try again in a moment."),
            ], 422);
        }

        return response()->json([
            'token' => $booking->cancellation_token,
            'redirect_url' => $session->url,
            // Codex Round 2 (D-161): explicit external-redirect signal.
            // Stripe Checkout URLs start with `https://checkout.stripe.com`,
            // which is distinguishable from riservo URLs by host, but
            // forwarding a boolean the server computed is more robust
            // than any client-side URL heuristic.
            'external_redirect' => true,
            'status' => $booking->status->value,
        ], 201);
    }

    /**
     * Flip a just-created online-payment booking to Cancelled so the slot
     * releases. The GIST overlap constraint is scoped to pending / confirmed
     * status, so a status transition is the slot-release mechanism.
     */
    private function releaseSlotFor(Booking $booking): void
    {
        $booking->update(['status' => BookingStatus::Cancelled]);
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
