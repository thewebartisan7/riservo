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
