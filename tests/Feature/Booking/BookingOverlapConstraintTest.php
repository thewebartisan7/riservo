<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create();

    $this->staff = User::factory()->create();
    $this->provider = attachProvider($this->business, $this->staff);

    $this->otherStaff = User::factory()->create();
    $this->otherProvider = attachProvider($this->business, $this->otherStaff);

    $this->service = Service::factory()->create([
        'business_id' => $this->business->id,
        'duration_minutes' => 30,
        'buffer_before' => 0,
        'buffer_after' => 0,
    ]);

    $this->customer = Customer::factory()->create();

    $this->makeBooking = function (array $attrs = []): Booking {
        return Booking::factory()->create(array_merge([
            'business_id' => $this->business->id,
            'provider_id' => $this->provider->id,
            'service_id' => $this->service->id,
            'customer_id' => $this->customer->id,
        ], $attrs));
    };
});

test('overlapping confirmed bookings for same provider fail at DB level', function () {
    ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
    ]);

    expect(fn () => ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:15', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:45', 'UTC'),
    ]))->toThrow(QueryException::class);
});

test('identical confirmed bookings for same provider fail at DB level', function () {
    ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
    ]);

    expect(fn () => ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
    ]))->toThrow(QueryException::class);
});

test('abutting bookings with zero buffers succeed', function () {
    ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
    ]);

    ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 11:00', 'UTC'),
    ]);

    expect(Booking::count())->toBe(2);
});

test('abutting bookings where first has buffer_after overlap fail', function () {
    ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
        'buffer_after_minutes' => 15,
    ]);

    expect(fn () => ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 11:00', 'UTC'),
    ]))->toThrow(QueryException::class);
});

test('abutting bookings where second has buffer_before overlap fail', function () {
    ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
    ]);

    expect(fn () => ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 11:00', 'UTC'),
        'buffer_before_minutes' => 15,
    ]))->toThrow(QueryException::class);
});

test('overlapping bookings with cancelled status succeed', function () {
    ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
        'status' => BookingStatus::Cancelled,
    ]);

    ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
    ]);

    expect(Booking::count())->toBe(2);
});

test('overlapping bookings with completed status succeed', function () {
    ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
        'status' => BookingStatus::Completed,
    ]);

    ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
    ]);

    expect(Booking::count())->toBe(2);
});

test('overlapping bookings with no_show status succeed', function () {
    ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
        'status' => BookingStatus::NoShow,
    ]);

    ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
    ]);

    expect(Booking::count())->toBe(2);
});

test('overlapping bookings for different providers succeed', function () {
    ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
    ]);

    ($this->makeBooking)([
        'provider_id' => $this->otherProvider->id,
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
    ]);

    expect(Booking::count())->toBe(2);
});

test('pending overlapping confirmed fails', function () {
    ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
        'status' => BookingStatus::Confirmed,
    ]);

    expect(fn () => ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
        'status' => BookingStatus::Pending,
    ]))->toThrow(QueryException::class);
});

test('confirmed overlapping pending fails', function () {
    ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
        'status' => BookingStatus::Pending,
    ]);

    expect(fn () => ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
        'status' => BookingStatus::Confirmed,
    ]))->toThrow(QueryException::class);
});

test('exclusion violation surfaces SQLSTATE 23P01', function () {
    ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
    ]);

    try {
        ($this->makeBooking)([
            'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
        ]);

        throw new RuntimeException('expected QueryException not thrown');
    } catch (QueryException $e) {
        expect($e->getPrevious()?->getCode() ?? $e->getCode())->toBe('23P01');
    }
});

test('updating a cancelled booking status to pending reinstates the constraint', function () {
    ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
        'status' => BookingStatus::Confirmed,
    ]);

    $cancelled = ($this->makeBooking)([
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
        'status' => BookingStatus::Cancelled,
    ]);

    expect(fn () => $cancelled->update(['status' => BookingStatus::Pending]))
        ->toThrow(QueryException::class);
});
