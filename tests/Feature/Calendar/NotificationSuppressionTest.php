<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingReminder;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    attachAdmin($this->business, $this->admin);

    $this->provider = Provider::factory()->create([
        'business_id' => $this->business->id,
        'user_id' => $this->admin->id,
    ]);
});

it('Booking::shouldSuppressCustomerNotifications returns true for google_calendar source', function () {
    $external = Booking::factory()->external()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
    ]);
    expect($external->shouldSuppressCustomerNotifications())->toBeTrue();

    $manual = Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => Service::factory()->create(['business_id' => $this->business->id])->id,
        'customer_id' => Customer::factory()->create()->id,
    ]);
    expect($manual->shouldSuppressCustomerNotifications())->toBeFalse();
});

it('updateStatus → cancelled on a google_calendar booking dispatches zero notifications', function () {
    $booking = Booking::factory()->external()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
    ]);

    $this->actingAs($this->admin)
        ->patch(route('dashboard.bookings.update-status', $booking), ['status' => 'cancelled']);

    Notification::assertNothingSent();
});

it('SendBookingReminders skips google_calendar source at query time', function () {
    $this->business->update(['reminder_hours' => [24]]);

    $reminderBooking = Booking::factory()->external()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'starts_at' => now()->addHours(23),
        'ends_at' => now()->addHours(24),
        'source' => BookingSource::GoogleCalendar,
        'status' => BookingStatus::Confirmed,
    ]);

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
    expect(BookingReminder::where('booking_id', $reminderBooking->id)->exists())->toBeFalse();
});
