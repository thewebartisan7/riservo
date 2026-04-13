<?php

use App\Enums\ConfirmationMode;
use App\Enums\DayOfWeek;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\Service;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create(['timezone' => 'Europe/Zurich']);
    $this->admin = User::factory()->create(['name' => 'Admin']);
    $this->business->users()->attach($this->admin, ['role' => 'admin']);
    $this->collaborator = User::factory()->create(['name' => 'Alice']);
    $this->business->users()->attach($this->collaborator, ['role' => 'collaborator']);

    BusinessHour::factory()->create([
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'open_time' => '09:00',
        'close_time' => '18:00',
    ]);

    AvailabilityRule::factory()->create([
        'collaborator_id' => $this->collaborator->id,
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
    $this->service->collaborators()->attach($this->collaborator);

    $this->travelTo(CarbonImmutable::parse('2026-04-13 08:00', 'Europe/Zurich'));
});

test('auto-confirmed booking dispatches BookingConfirmedNotification to customer', function () {
    Notification::fake();

    $this->postJson('/booking/'.$this->business->slug.'/book', [
        'service_id' => $this->service->id,
        'collaborator_id' => $this->collaborator->id,
        'date' => '2026-04-13',
        'time' => '10:00',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+41 79 123 45 67',
        'website' => '',
    ]);

    Notification::assertSentOnDemand(BookingConfirmedNotification::class, function ($notification, $channels, $notifiable) {
        return $notifiable->routes['mail'] === 'jane@example.com';
    });
});

test('pending booking does NOT dispatch BookingConfirmedNotification to customer', function () {
    Notification::fake();
    $this->business->update(['confirmation_mode' => ConfirmationMode::Manual]);

    $this->postJson('/booking/'.$this->business->slug.'/book', [
        'service_id' => $this->service->id,
        'collaborator_id' => $this->collaborator->id,
        'date' => '2026-04-13',
        'time' => '10:00',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+41 79 123 45 67',
        'website' => '',
    ]);

    Notification::assertSentOnDemand(BookingConfirmedNotification::class, 0);
});

test('booking confirmed via dashboard dispatches to customer', function () {
    Notification::fake();
    $this->business->update(['confirmation_mode' => ConfirmationMode::Manual]);

    // Create a pending booking first
    $response = $this->postJson('/booking/'.$this->business->slug.'/book', [
        'service_id' => $this->service->id,
        'collaborator_id' => $this->collaborator->id,
        'date' => '2026-04-13',
        'time' => '10:00',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+41 79 123 45 67',
        'website' => '',
    ]);

    $booking = Booking::first();

    // Admin confirms booking
    $this->actingAs($this->admin)
        ->patch(route('dashboard.bookings.update-status', $booking), ['status' => 'confirmed']);

    Notification::assertSentOnDemand(BookingConfirmedNotification::class, function ($notification) use ($booking) {
        return $notification->booking->id === $booking->id;
    });
});

test('BookingConfirmedNotification email has correct subject', function () {
    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
    ]);

    $notification = new BookingConfirmedNotification($booking);
    $mail = $notification->toMail(Notification::route('mail', 'test@example.com'));

    expect($mail->subject)->toContain('Booking Confirmed')
        ->and($mail->subject)->toContain($this->business->name);
});
