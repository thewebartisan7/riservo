<?php

use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * PAYMENTS Session 2b — bookings list Payment filter (locked roadmap
 * decision #19: payment surfaces are admin-only).
 */
beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);
    $this->staff = User::factory()->create();
    $this->provider = attachProvider($this->business, $this->staff);
    $this->service = Service::factory()->create([
        'business_id' => $this->business->id,
        'is_active' => true,
    ]);
    $this->provider->services()->attach($this->service);
});

/**
 * @return array{starts_at: CarbonInterface, ends_at: CarbonInterface}
 */
function bookingsListPaymentFilterWindow(int $dayOffset): array
{
    $startsAt = now()->addDays(7 + $dayOffset)->setTime(10, 0);

    return [
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
    ];
}

test('payment_status=paid filters to Paid bookings only', function () {
    // Use deterministic non-overlapping windows; random factory dates can violate the provider overlap GIST constraint.
    Booking::factory()->paid()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
    ] + bookingsListPaymentFilterWindow(0));
    Booking::factory()->awaitingPayment()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
    ] + bookingsListPaymentFilterWindow(1));
    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'payment_status' => PaymentStatus::NotApplicable,
    ] + bookingsListPaymentFilterWindow(2));

    $this->actingAs($this->admin)
        ->get('/dashboard/bookings?payment_status=paid')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('bookings.data', 1)
            ->where('bookings.data.0.payment.status', 'paid')
            ->where('filters.payment_status', 'paid')
        );
});

test('payment_status=offline filters to NotApplicable', function () {
    // Use deterministic non-overlapping windows; random factory dates can violate the provider overlap GIST constraint.
    Booking::factory()->paid()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
    ] + bookingsListPaymentFilterWindow(0));
    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'payment_status' => PaymentStatus::NotApplicable,
    ] + bookingsListPaymentFilterWindow(1));

    $this->actingAs($this->admin)
        ->get('/dashboard/bookings?payment_status=offline')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('bookings.data', 1)
            ->where('bookings.data.0.payment.status', 'not_applicable')
        );
});

test('staff requesting ?payment_status=paid gets their full booking list back (filter gated on admin — Codex Round 2 F3)', function () {
    // Staff has one paid booking + one offline booking. A naïve
    // ?payment_status=paid filter would let them infer which bookings
    // are paid by counting the filtered vs unfiltered rows. The server
    // must ignore the query string for non-admin viewers.
    Booking::factory()->paid()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
    ] + bookingsListPaymentFilterWindow(0));
    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'payment_status' => PaymentStatus::NotApplicable,
    ] + bookingsListPaymentFilterWindow(1));

    // Admin sees only the paid row when filtering.
    $this->actingAs($this->admin)
        ->get('/dashboard/bookings?payment_status=paid')
        ->assertInertia(fn ($page) => $page->has('bookings.data', 1));

    // Staff sees BOTH rows — filter is ignored server-side.
    $this->actingAs($this->staff)
        ->get('/dashboard/bookings?payment_status=paid')
        ->assertInertia(fn ($page) => $page
            ->where('isAdmin', false)
            ->has('bookings.data', 2)
        );
});

test('every payment_status value surfaces in the list payload (chip dataset coverage)', function () {
    $map = [
        'paid' => PaymentStatus::Paid,
        'awaiting_payment' => PaymentStatus::AwaitingPayment,
        'unpaid' => PaymentStatus::Unpaid,
        'refunded' => PaymentStatus::Refunded,
        'partially_refunded' => PaymentStatus::PartiallyRefunded,
        'refund_failed' => PaymentStatus::RefundFailed,
        'not_applicable' => PaymentStatus::NotApplicable,
    ];

    // Use deterministic non-overlapping windows; random factory dates can violate the provider overlap GIST constraint.
    $i = 0;
    foreach ($map as $enum) {
        Booking::factory()->create([
            'business_id' => $this->business->id,
            'provider_id' => $this->provider->id,
            'service_id' => $this->service->id,
            'payment_status' => $enum,
        ] + bookingsListPaymentFilterWindow($i));
        $i++;
    }

    $this->actingAs($this->admin)
        ->get('/dashboard/bookings?sort=created_at&direction=asc')
        ->assertSuccessful()
        ->assertInertia(function ($page) use ($map) {
            $page->has('bookings.data', count($map));
            $statuses = collect($page->toArray()['props']['bookings']['data'])
                ->pluck('payment.status')
                ->sort()
                ->values()
                ->all();
            expect($statuses)->toEqualCanonicalizing(array_keys($map));
        });
});
