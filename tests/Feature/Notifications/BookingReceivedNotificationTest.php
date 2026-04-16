<?php

use App\Enums\ConfirmationMode;
use App\Enums\DayOfWeek;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\Service;
use App\Models\User;
use App\Notifications\BookingReceivedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create(['timezone' => 'Europe/Zurich']);
    $this->admin = User::factory()->create(['name' => 'Admin']);
    attachAdmin($this->business, $this->admin);
    $this->staff = User::factory()->create(['name' => 'Alice']);
    $this->provider = attachProvider($this->business, $this->staff);

    BusinessHour::factory()->create([
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'open_time' => '09:00',
        'close_time' => '18:00',
    ]);

    AvailabilityRule::factory()->create([
        'provider_id' => $this->provider->id,
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'start_time' => '09:00',
        'end_time' => '18:00',
    ]);

    $this->service = Service::factory()->create([
        'business_id' => $this->business->id,
        'is_active' => true,
        'duration_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 60,
    ]);
    $this->service->providers()->attach($this->provider);

    $this->travelTo(CarbonImmutable::parse('2026-04-13 08:00', 'Europe/Zurich'));
});

test('new booking dispatches BookingReceivedNotification to admins and staff', function () {
    Notification::fake();

    $this->postJson('/booking/'.$this->business->slug.'/book', [
        'service_id' => $this->service->id,
        'provider_id' => $this->provider->id,
        'date' => '2026-04-13',
        'time' => '10:00',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+41 79 123 45 67',
        'website' => '',
    ]);

    Notification::assertSentTo($this->admin, BookingReceivedNotification::class);
    Notification::assertSentTo($this->staff, BookingReceivedNotification::class);
});

test('pending booking also dispatches BookingReceivedNotification to staff', function () {
    Notification::fake();
    $this->business->update(['confirmation_mode' => ConfirmationMode::Manual]);

    $this->postJson('/booking/'.$this->business->slug.'/book', [
        'service_id' => $this->service->id,
        'provider_id' => $this->provider->id,
        'date' => '2026-04-13',
        'time' => '10:00',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+41 79 123 45 67',
        'website' => '',
    ]);

    Notification::assertSentTo($this->admin, BookingReceivedNotification::class);
    Notification::assertSentTo($this->staff, BookingReceivedNotification::class);
});

test('manual booking from dashboard dispatches BookingReceivedNotification', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->post(route('dashboard.bookings.store'), [
            'service_id' => $this->service->id,
            'provider_id' => $this->provider->id,
            'date' => '2026-04-13',
            'time' => '10:00',
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '+41 79 123 45 67',
        ]);

    // Admin is excluded from receiving since they created it
    Notification::assertSentTo($this->staff, BookingReceivedNotification::class);
});

test('BookingReceivedNotification has context-aware subject', function () {
    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
    ]);

    $newNotification = new BookingReceivedNotification($booking, 'new');
    $confirmedNotification = new BookingReceivedNotification($booking, 'confirmed');

    $newMail = $newNotification->toMail($this->admin);
    $confirmedMail = $confirmedNotification->toMail($this->admin);

    expect($newMail->subject)->toContain('New Booking')
        ->and($confirmedMail->subject)->toContain('Booking Confirmed');
});
