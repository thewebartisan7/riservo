<?php

use App\Models\Booking;
use App\Models\BookingReminder;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Notifications\BookingReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create([
        'timezone' => 'Europe/Zurich',
        'reminder_hours' => [24, 1],
    ]);
    $this->staff = User::factory()->create();
    $this->provider = attachProvider($this->business, $this->staff);
    $this->service = Service::factory()->create(['business_id' => $this->business->id]);
    $this->customer = Customer::factory()->create(['email' => 'jane@example.com']);
});

test('sends reminders for bookings within time window', function () {
    Notification::fake();
    $now = CarbonImmutable::parse('2026-04-13 10:00:00', 'UTC');
    $this->travelTo($now);

    // Booking 24 hours from now
    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => $now->addHours(24),
        'ends_at' => $now->addHours(25),
    ]);

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    Notification::assertSentOnDemand(BookingReminderNotification::class, function ($notification, $channels, $notifiable) {
        return $notifiable->routes['mail'] === 'jane@example.com'
            && $notification->hoursBefore === 24;
    });

    expect(BookingReminder::count())->toBe(1);
});

test('skips bookings that already have a reminder sent', function () {
    Notification::fake();
    $now = CarbonImmutable::parse('2026-04-13 10:00:00', 'UTC');
    $this->travelTo($now);

    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => $now->addHours(24),
        'ends_at' => $now->addHours(25),
    ]);

    BookingReminder::create([
        'booking_id' => $booking->id,
        'hours_before' => 24,
        'sent_at' => $now->subMinute(),
    ]);

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
    expect(BookingReminder::count())->toBe(1);
});

test('respects per-business reminder_hours configuration', function () {
    Notification::fake();
    $now = CarbonImmutable::parse('2026-04-13 10:00:00', 'UTC');
    $this->travelTo($now);

    // Business with NO reminder hours
    $business2 = Business::factory()->onboarded()->create(['reminder_hours' => []]);
    $staff2 = User::factory()->create();
    $provider2 = attachProvider($business2, $staff2);
    $service2 = Service::factory()->create(['business_id' => $business2->id]);

    Booking::factory()->confirmed()->create([
        'business_id' => $business2->id,
        'provider_id' => $provider2->id,
        'service_id' => $service2->id,
        'customer_id' => $this->customer->id,
        'starts_at' => $now->addHours(24),
        'ends_at' => $now->addHours(25),
    ]);

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

test('skips cancelled bookings', function () {
    Notification::fake();
    $now = CarbonImmutable::parse('2026-04-13 10:00:00', 'UTC');
    $this->travelTo($now);

    Booking::factory()->cancelled()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => $now->addHours(24),
        'ends_at' => $now->addHours(25),
    ]);

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
    expect(BookingReminder::count())->toBe(0);
});

// D-071: eligibility uses business-timezone wall-clock; past-due fires on next run.
// Europe/Zurich DST ends on the last Sunday of October — in 2026, that's 2026-10-25
// (clocks roll back from 03:00 CEST to 02:00 CET at 01:00 UTC).
test('sends reminder across DST fall-back using wall-clock semantics', function () {
    Notification::fake();

    // Appointment at 10:00 local CET on Monday 2026-10-26 (day after DST ends).
    $startsAt = CarbonImmutable::parse('2026-10-26 09:00:00', 'UTC'); // = 10:00 CET
    $now = CarbonImmutable::parse('2026-10-25 09:00:00', 'UTC');
    $this->travelTo($now);

    $this->business->update(['reminder_hours' => [24]]);

    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
    ]);

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    Notification::assertSentOnDemand(BookingReminderNotification::class, function ($notification, $channels, $notifiable) {
        return $notifiable->routes['mail'] === 'jane@example.com'
            && $notification->hoursBefore === 24;
    });
    expect(BookingReminder::count())->toBe(1);
});

// D-071: spring-forward; wall-clock semantics resolve the 02:00-03:00 gap by rolling
// forward. Europe/Zurich DST starts on the last Sunday of March — in 2026, that's
// 2026-03-29 at 02:00 local (01:00 UTC) when clocks leap to 03:00 CEST.
test('sends reminders across DST spring-forward including gap hour', function () {
    Notification::fake();

    // Regular case: appointment at 10:00 CEST on 2026-03-30 (post-spring-forward).
    $regularStarts = CarbonImmutable::parse('2026-03-30 08:00:00', 'UTC'); // = 10:00 CEST
    // Gap case: 24h wall-clock before this appointment lands inside the 02:00-03:00
    // non-existent local hour on 2026-03-29; Carbon rolls forward into post-transition.
    $gapStarts = CarbonImmutable::parse('2026-03-30 00:30:00', 'UTC'); // = 02:30 CEST

    $now = CarbonImmutable::parse('2026-03-29 08:00:00', 'UTC');
    $this->travelTo($now);

    $this->business->update(['reminder_hours' => [24]]);

    $regular = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => $regularStarts,
        'ends_at' => $regularStarts->addHour(),
    ]);

    $gap = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => $gapStarts,
        'ends_at' => $gapStarts->addHour(),
    ]);

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    expect(BookingReminder::count())->toBe(2)
        ->and(BookingReminder::where('booking_id', $regular->id)->where('hours_before', 24)->exists())->toBeTrue()
        ->and(BookingReminder::where('booking_id', $gap->id)->where('hours_before', 24)->exists())->toBeTrue();

    Notification::assertSentOnDemandTimes(BookingReminderNotification::class, 2);
});

// D-071: delayed-run recovery via past-due eligibility. The old ±5-minute window
// dropped every reminder whose window passed during a scheduler outage.
test('sends reminder when scheduler run is delayed past the target time', function () {
    Notification::fake();

    // Appointment 23h30m away; the 24h reminder's wall-clock eligibility passed 30 min ago.
    $now = CarbonImmutable::parse('2026-04-13 10:30:00', 'UTC');
    $this->travelTo($now);

    $this->business->update(['reminder_hours' => [24]]);

    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-14 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-04-14 11:00:00', 'UTC'),
    ]);

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    Notification::assertSentOnDemand(BookingReminderNotification::class, function ($notification) {
        return $notification->hoursBefore === 24;
    });
    expect(BookingReminder::count())->toBe(1);
});

// D-071: idempotency via booking_reminders unique(booking_id, hours_before). A second
// delayed-run pass must not double-send.
test('delayed run is idempotent across repeated invocations', function () {
    Notification::fake();

    $now = CarbonImmutable::parse('2026-04-13 10:30:00', 'UTC');
    $this->travelTo($now);

    $this->business->update(['reminder_hours' => [24]]);

    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-14 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-04-14 11:00:00', 'UTC'),
    ]);

    $this->artisan('bookings:send-reminders')->assertSuccessful();
    $this->artisan('bookings:send-reminders')->assertSuccessful();

    expect(BookingReminder::count())->toBe(1);
    Notification::assertSentOnDemandTimes(BookingReminderNotification::class, 1);
});

// D-071: starts_at > now upper bound — no reminder fires after the appointment.
test('does not send reminder for an appointment already in the past', function () {
    Notification::fake();

    $now = CarbonImmutable::parse('2026-04-13 10:00:00', 'UTC');
    $this->travelTo($now);

    $this->business->update(['reminder_hours' => [1]]);

    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => $now->subHour(),
        'ends_at' => $now,
    ]);

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
    expect(BookingReminder::count())->toBe(0);
});
