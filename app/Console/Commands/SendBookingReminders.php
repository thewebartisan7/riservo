<?php

namespace App\Console\Commands;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingReminder;
use App\Models\Business;
use App\Notifications\BookingReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Notification;

#[Signature('bookings:send-reminders')]
#[Description('Send reminder emails for upcoming bookings based on each business\'s reminder_hours configuration')]
class SendBookingReminders extends Command
{
    public function handle(): int
    {
        $now = CarbonImmutable::now('UTC');

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

        $horizonEnd = $now->addHours((int) $allReminderHours->max() + 1);

        $candidates = Booking::where('status', BookingStatus::Confirmed)
            // External (Google Calendar) events never get reminders — locked decision
            // #7. Query-time exclusion is cheaper than a per-row guard at dispatch.
            ->where('source', '!=', BookingSource::GoogleCalendar->value)
            ->whereBetween('starts_at', [$now, $horizonEnd])
            ->with(['business', 'service', 'provider.user', 'customer', 'reminders'])
            ->get();

        $totalSent = 0;

        foreach ($candidates as $booking) {
            $tz = $booking->business->timezone;
            $reminderHours = $booking->business->reminder_hours ?? [];

            foreach ($reminderHours as $hoursBefore) {
                $hoursBefore = (int) $hoursBefore;

                if ($hoursBefore <= 0) {
                    continue;
                }

                $reminderTimeUtc = $booking->starts_at
                    ->toImmutable()
                    ->setTimezone($tz)
                    ->modify("-{$hoursBefore} hours")
                    ->utc();

                if ($reminderTimeUtc->greaterThan($now)) {
                    continue;
                }

                if ($booking->reminders->contains('hours_before', $hoursBefore)) {
                    continue;
                }

                try {
                    BookingReminder::create([
                        'booking_id' => $booking->id,
                        'hours_before' => $hoursBefore,
                        'sent_at' => $now,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    continue;
                }

                Notification::route('mail', $booking->customer->email)
                    ->notify(new BookingReminderNotification($booking, $hoursBefore));

                $totalSent++;
            }
        }

        $this->info("Sent {$totalSent} reminder(s).");

        return self::SUCCESS;
    }
}
