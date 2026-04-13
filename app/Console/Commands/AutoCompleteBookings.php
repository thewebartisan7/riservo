<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookings:auto-complete')]
#[Description('Automatically transition confirmed bookings to completed after their end time has passed')]
class AutoCompleteBookings extends Command
{
    public function handle(): int
    {
        $count = Booking::where('status', BookingStatus::Confirmed)
            ->where('ends_at', '<', now())
            ->update(['status' => BookingStatus::Completed]);

        $this->info("Transitioned {$count} booking(s) to completed.");

        return self::SUCCESS;
    }
}
