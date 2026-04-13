<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Notifications\BookingReminderNotification;
use Illuminate\Support\Facades\Notification;

test('BookingReminderNotification has correct subject', function () {
    $business = Business::factory()->onboarded()->create(['timezone' => 'Europe/Zurich']);
    $collaborator = User::factory()->create();
    $service = Service::factory()->create(['business_id' => $business->id]);

    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'collaborator_id' => $collaborator->id,
        'service_id' => $service->id,
    ]);

    $notification = new BookingReminderNotification($booking, 24);
    $mail = $notification->toMail(Notification::route('mail', 'test@example.com'));

    expect($mail->subject)->toContain('Reminder')
        ->and($mail->subject)->toContain($business->name);
});
