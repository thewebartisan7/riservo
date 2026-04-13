<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingReminder;
use App\Models\Business;
use App\Notifications\BookingReminderNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

#[Signature('bookings:send-reminders')]
#[Description('Send reminder emails for upcoming bookings based on each business\'s reminder_hours configuration')]
class SendBookingReminders extends Command
{
    public function handle(): int
    {
        $now = now();

        $allReminderHours = Business::whereNotNull('reminder_hours')
            ->pluck('reminder_hours')
            ->flatten()
            ->unique()
            ->filter(fn ($h) => $h > 0)
            ->values();

        if ($allReminderHours->isEmpty()) {
            $this->info('No businesses have reminder hours configured.');

            return self::SUCCESS;
        }

        $totalSent = 0;

        foreach ($allReminderHours as $hoursBefore) {
            $targetStart = $now->copy()->addHours((int) $hoursBefore)->subMinutes(5);
            $targetEnd = $now->copy()->addHours((int) $hoursBefore)->addMinutes(5);

            $bookings = Booking::where('status', BookingStatus::Confirmed)
                ->whereBetween('starts_at', [$targetStart, $targetEnd])
                ->whereDoesntHave('reminders', fn ($q) => $q->where('hours_before', $hoursBefore))
                ->with(['business', 'service', 'collaborator', 'customer'])
                ->get();

            foreach ($bookings as $booking) {
                if (! in_array($hoursBefore, $booking->business->reminder_hours ?? [])) {
                    continue;
                }

                BookingReminder::create([
                    'booking_id' => $booking->id,
                    'hours_before' => $hoursBefore,
                    'sent_at' => $now,
                ]);

                Notification::route('mail', $booking->customer->email)
                    ->notify(new BookingReminderNotification($booking, (int) $hoursBefore));

                $totalSent++;
            }
        }

        $this->info("Sent {$totalSent} reminder(s).");

        return self::SUCCESS;
    }
}
