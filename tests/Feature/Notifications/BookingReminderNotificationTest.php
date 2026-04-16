<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\Provider;
use App\Models\Service;
use App\Notifications\BookingReminderNotification;
use Illuminate\Support\Facades\Notification;

test('BookingReminderNotification has correct subject', function () {
    $business = Business::factory()->onboarded()->create(['timezone' => 'Europe/Zurich']);
    $provider = Provider::factory()->create(['business_id' => $business->id]);
    $service = Service::factory()->create(['business_id' => $business->id]);

    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
    ]);

    $notification = new BookingReminderNotification($booking, 24);
    $mail = $notification->toMail(Notification::route('mail', 'test@example.com'));

    expect($mail->subject)->toContain('Reminder')
        ->and($mail->subject)->toContain($business->name);
});
