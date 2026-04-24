<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bookings:send-reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('bookings:auto-complete')->everyFifteenMinutes();
Schedule::command('calendar:renew-watches')->dailyAt('03:00');
// PAYMENTS Session 2b (locked roadmap decision #13 + #31): reap stale
// pending+awaiting_payment ONLINE bookings after 90min Checkout window +
// 5min grace buffer. Only touches `payment_mode_at_creation = 'online'` —
// customer_choice failures are webhook-driven (keep slot, mark Unpaid).
Schedule::command('bookings:expire-unpaid')->everyTwoMinutes()->withoutOverlapping();
