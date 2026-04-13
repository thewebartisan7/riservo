<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Notifications\BookingCancelledNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create([
        'timezone' => 'Europe/Zurich',
        'cancellation_window_hours' => 0,
    ]);
    $this->admin = User::factory()->create(['name' => 'Admin']);
    $this->business->users()->attach($this->admin, ['role' => 'admin']);
    $this->collaborator = User::factory()->create(['name' => 'Alice']);
    $this->business->users()->attach($this->collaborator, ['role' => 'collaborator']);

    $this->service = Service::factory()->create([
        'business_id' => $this->business->id,
        'is_active' => true,
    ]);

    $this->customer = Customer::factory()->create(['email' => 'jane@example.com']);

    $this->travelTo(CarbonImmutable::parse('2026-04-13 08:00', 'Europe/Zurich'));
});

test('customer cancellation via token dispatches to admins and collaborator', function () {
    Notification::fake();

    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
    ]);

    $this->post(route('bookings.cancel', $booking->cancellation_token));

    Notification::assertSentTo($this->admin, BookingCancelledNotification::class, function ($notification) {
        return $notification->cancelledBy === 'customer';
    });
    Notification::assertSentTo($this->collaborator, BookingCancelledNotification::class);
});

test('dashboard cancellation dispatches to customer', function () {
    Notification::fake();

    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
    ]);

    $this->actingAs($this->admin)
        ->patch(route('dashboard.bookings.update-status', $booking), ['status' => 'cancelled']);

    Notification::assertSentOnDemand(BookingCancelledNotification::class, function ($notification, $channels, $notifiable) {
        return $notifiable->routes['mail'] === 'jane@example.com'
            && $notification->cancelledBy === 'business';
    });
});

test('customer cancellation via auth route dispatches correctly', function () {
    Notification::fake();

    $customerUser = User::factory()->create();
    $this->customer->update(['user_id' => $customerUser->id]);

    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
    ]);

    $this->actingAs($customerUser)
        ->post(route('customer.bookings.cancel', $booking));

    Notification::assertSentTo($this->admin, BookingCancelledNotification::class, function ($notification) {
        return $notification->cancelledBy === 'customer';
    });
});

test('BookingCancelledNotification has correct subject for customer cancellation', function () {
    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    $notification = new BookingCancelledNotification($booking, 'customer');
    $mail = $notification->toMail($this->admin);

    expect($mail->subject)->toContain('Booking Cancelled');
});

test('BookingCancelledNotification has correct subject for business cancellation', function () {
    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    $notification = new BookingCancelledNotification($booking, 'business');
    $mail = $notification->toMail(Notification::route('mail', 'jane@example.com'));

    expect($mail->subject)->toContain('cancelled')
        ->and($mail->subject)->toContain($this->business->name);
});
