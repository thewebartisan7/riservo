<?php

use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\StripeConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->customer = Customer::factory()->create([
        'user_id' => $this->user->id,
        'email' => $this->user->email,
    ]);

    $this->business = Business::factory()->onboarded()->create(['timezone' => 'Europe/Zurich']);
    $this->service = Service::factory()->create(['business_id' => $this->business->id]);
});

test('customer bookings list renders with deactivated provider', function () {
    $staff = User::factory()->create(['name' => 'Trashed Staff']);
    $provider = attachProvider($this->business, $staff);

    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2030-05-01 09:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2030-05-01 10:00', 'UTC'),
    ]);

    $provider->delete();

    $response = $this->actingAs($this->user)->get('/my-bookings');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('customer/bookings')
            ->has('upcoming', 1)
            ->where('upcoming.0.provider.name', 'Trashed Staff')
            ->where('upcoming.0.provider.is_active', false)
        );
});

test('/my-bookings does not N+1 on refund status line for many bookings with failed refunds', function () {
    $staff = User::factory()->create();
    $provider = attachProvider($this->business, $staff);
    StripeConnectedAccount::factory()->active()->for($this->business)->create([
        'stripe_account_id' => 'acct_test_nplus1',
    ]);

    // Seed 20 past bookings, each with one failed refund (triggers the
    // latest-is-failed branch which also queries stripe_connected_accounts
    // in the un-fixed code).
    for ($i = 0; $i < 20; $i++) {
        $booking = Booking::factory()->paid()->create([
            'business_id' => $this->business->id,
            'provider_id' => $provider->id,
            'service_id' => $this->service->id,
            'customer_id' => $this->customer->id,
            'stripe_connected_account_id' => 'acct_test_nplus1',
            'starts_at' => CarbonImmutable::parse('2020-05-01 09:00', 'UTC')->addDays($i),
            'ends_at' => CarbonImmutable::parse('2020-05-01 10:00', 'UTC')->addDays($i),
        ]);
        BookingRefund::factory()->failed()->for($booking)->create([
            'amount_cents' => 5000,
            'currency' => 'chf',
            'failure_reason' => 'test',
            'reason' => 'customer-requested',
        ]);
    }

    DB::enableQueryLog();
    $response = $this->actingAs($this->user)->get('/my-bookings');
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    $response->assertSuccessful();

    // Before the P3 fix, the unfixed code ran:
    //   1 refundStatusLine query per booking (20)
    //   + 1 disconnected-account query per booking with failed refund (20)
    //   = 40 extra queries on top of the baseline.
    //
    // After the fix: refunds are eager-loaded (1 query) + disconnected set
    // precomputed (1 query). Budget with generous headroom:
    expect($queryCount)->toBeLessThan(20);
});

test('customer bookings list passes business.timezone per booking', function () {
    $this->business->update(['timezone' => 'Asia/Tokyo']);

    $staff = User::factory()->create();
    $provider = attachProvider($this->business, $staff);

    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2030-05-01 09:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2030-05-01 10:00', 'UTC'),
    ]);

    $response = $this->actingAs($this->user)->get('/my-bookings');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('upcoming', 1)
            ->where('upcoming.0.business.timezone', 'Asia/Tokyo')
        );
});
