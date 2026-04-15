# Session 10 — Notifications (Email)

## Context

Session 10 implements the transactional email system for all booking lifecycle events. Currently, only a placeholder `BookingConfirmedNotification` exists (Session 7). The queue infrastructure (database driver, jobs tables) is ready. The `Business.reminder_hours` JSON field exists from Session 2. This session adds styled email templates, new notification classes for all booking events, scheduled commands for reminders and auto-completion, and production deployment documentation.

## Goal

Implement all booking lifecycle email notifications, scheduled reminder delivery, automatic booking completion, and document server requirements for production deployment.

## Prerequisites

- All 347 tests passing (verified)
- Queue infrastructure ready (database driver, jobs/failed_jobs tables)
- `reminder_hours` field exists on Business model
- Existing `BookingConfirmedNotification` placeholder to replace

## Scope

**Included:**
- 4 notification classes (rewrite 1 existing + 3 new)
- Blade email templates with riservo branding
- Reminder scheduling command + deduplication table
- Auto-complete bookings command
- Wire notifications into all existing controllers
- Production deployment docs
- Tests for all flows

**Not included:**
- SMS/WhatsApp (v2)
- Email template translations (pre-launch)
- Actual Hostpoint SMTP credentials (just .env config)

## New Decision

**D-056 — Reminder deduplication via `booking_reminders` table**: A dedicated table with `unique(booking_id, hours_before)` prevents duplicate reminder sends. Cleaner than a JSON column on bookings — queryable, auditable, race-condition safe via DB constraint.

## Implementation Steps

### Step 1: Migration + Model — `booking_reminders`

**Create:** `database/migrations/2026_04_13_XXXXXX_create_booking_reminders_table.php`
- `id`, `booking_id` (FK, cascade delete), `hours_before` (unsignedSmallInteger), `sent_at` (timestamp), `timestamps()`
- Unique index on `[booking_id, hours_before]`

**Create:** `app/Models/BookingReminder.php`
- `belongsTo(Booking::class)`

**Modify:** `app/Models/Booking.php`
- Add `hasMany(BookingReminder::class)` relationship

### Step 2: Publish + customize mail views

Run `php artisan vendor:publish --tag=laravel-mail` to get base layout in `resources/views/vendor/mail/`.
Customize header/footer/theme for riservo branding (logo, colors).

### Step 3: Create Blade email templates

All in `resources/views/mail/`:
- `booking-confirmed.blade.php` — customer: your booking is confirmed
- `booking-received.blade.php` — staff: new booking received
- `booking-cancelled.blade.php` — parameterized for customer vs staff via `$cancelledBy`
- `booking-reminder.blade.php` — customer: upcoming appointment reminder

Each template receives `$booking` (with relations), formats dates in business timezone, uses `__()` for strings, includes CTA button.

### Step 4: Rewrite `BookingConfirmedNotification`

**Modify:** `app/Notifications/BookingConfirmedNotification.php`
- Replace MailMessage fluent API with `->view('mail.booking-confirmed', [...])`
- Keep `implements ShouldQueue`
- Pass: booking, business, service, collaborator, formatted datetime, view URL

### Step 5: Create new notification classes

**Create:** `app/Notifications/BookingReceivedNotification.php`
- Sent to business admins + assigned collaborator
- Constructor: `Booking $booking, string $context` ('new' | 'confirmed') — adapts subject/content
- Covers both "New booking received" and "Booking confirmed (to collaborator)" roadmap items (D-057)
- `implements ShouldQueue`
- Uses `mail.booking-received` template

**Create:** `app/Notifications/BookingCancelledNotification.php`
- Constructor: `Booking $booking, string $cancelledBy` ('customer' | 'business')
- When customer cancels → sent to admins + collaborator
- When business cancels → sent to customer
- `implements ShouldQueue`
- Uses `mail.booking-cancelled` template

**Create:** `app/Notifications/BookingReminderNotification.php`
- Constructor: `Booking $booking, int $hoursBefore`
- Sent to customer email
- `implements ShouldQueue`
- Uses `mail.booking-reminder` template

### Step 6: Create scheduled commands

**Create:** `app/Console/Commands/SendBookingReminders.php`
- Signature: `bookings:send-reminders`
- Runs every 5 minutes (with `withoutOverlapping()`)
- For each unique hours_before across all businesses' `reminder_hours`:
  - Find confirmed bookings where `starts_at` is within ±5 min of `now + hours_before`
  - Skip bookings already having a `BookingReminder` for that hours_before
  - Filter by business's actual `reminder_hours` config
  - Insert `BookingReminder` record + dispatch `BookingReminderNotification`

**Create:** `app/Console/Commands/AutoCompleteBookings.php`
- Signature: `bookings:auto-complete`
- Runs every 15 minutes
- Finds confirmed bookings where `ends_at < now()`, transitions to `completed`

**Modify:** `routes/console.php`
- Register both commands on the schedule

### Step 7: Wire notifications into controllers

**Modify:** `app/Http/Controllers/Booking/PublicBookingController.php` (~line 293)
- Replace placeholder comment
- If auto-confirmed: dispatch `BookingConfirmedNotification` to customer
- If pending: don't send confirmation to customer yet
- Always: dispatch `BookingReceivedNotification` to admins + collaborator

**Modify:** `app/Http/Controllers/Dashboard/BookingController.php`
- In `updateStatus()` (~line 181): when status changes to `confirmed` → dispatch `BookingConfirmedNotification` to customer; when `cancelled` → dispatch `BookingCancelledNotification` with `cancelled_by: 'business'`
- In `store()` (~line 298): replace placeholder, dispatch `BookingConfirmedNotification` to customer + `BookingReceivedNotification` to staff

**Modify:** `app/Http/Controllers/Booking/BookingManagementController.php` (~line 63)
- After customer cancel via token: dispatch `BookingCancelledNotification` with `cancelled_by: 'customer'`

**Modify:** `app/Http/Controllers/Customer/BookingController.php` (~line 60)
- After customer cancel via auth: dispatch `BookingCancelledNotification` with `cancelled_by: 'customer'`

### Step 8: Queue config — `after_commit`

**Modify:** `config/queue.php`
- Set `'after_commit' => true` for database connection (ensures notifications only dispatch after DB transaction commits)

### Step 9: Create `docs/DEPLOYMENT.md`

Document:
- Queue worker: `php artisan queue:work --sleep=3 --tries=3 --timeout=90`
- Scheduler cron: `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`
- Supervisor config example for queue worker
- Required `.env` keys for mail (SMTP), queue, app URL
- Hostpoint SMTP settings template

### Step 10: Update `.env.example`

Add commented Hostpoint SMTP config block.

## Testing Plan

**Create:** `tests/Feature/Notifications/BookingConfirmedNotificationTest.php`
- Auto-confirmed booking dispatches to customer
- Pending booking does NOT dispatch confirmation to customer
- Email content has correct subject, service, date/time in business timezone

**Create:** `tests/Feature/Notifications/BookingReceivedNotificationTest.php`
- New booking dispatches to admins and collaborator
- Manual booking from dashboard dispatches to collaborator

**Create:** `tests/Feature/Notifications/BookingCancelledNotificationTest.php`
- Customer cancel via token dispatches to admins + collaborator
- Dashboard cancel dispatches to customer
- Customer cancel via auth dispatches correctly

**Create:** `tests/Feature/Commands/SendBookingRemindersTest.php`
- Sends reminders for bookings within time window
- Skips already-reminded bookings (dedup)
- Respects per-business `reminder_hours`
- Skips cancelled/completed bookings

**Create:** `tests/Feature/Commands/AutoCompleteBookingsTest.php`
- Confirmed past bookings → completed
- Pending bookings NOT auto-completed
- Future bookings NOT touched

## File List

### Create (19 files)
1. `database/migrations/2026_04_13_XXXXXX_create_booking_reminders_table.php`
2. `app/Models/BookingReminder.php`
3. `app/Notifications/BookingReceivedNotification.php`
4. `app/Notifications/BookingCancelledNotification.php`
5. `app/Notifications/BookingReminderNotification.php`
6. `app/Console/Commands/SendBookingReminders.php`
7. `app/Console/Commands/AutoCompleteBookings.php`
8. `resources/views/mail/booking-confirmed.blade.php`
9. `resources/views/mail/booking-received.blade.php`
10. `resources/views/mail/booking-cancelled.blade.php`
11. `resources/views/mail/booking-reminder.blade.php`
12. `resources/views/vendor/mail/` (published via artisan, customized)
13. `docs/DEPLOYMENT.md`
14. `tests/Feature/Notifications/BookingConfirmedNotificationTest.php`
15. `tests/Feature/Notifications/BookingReceivedNotificationTest.php`
16. `tests/Feature/Notifications/BookingCancelledNotificationTest.php`
17. `tests/Feature/Notifications/BookingReminderNotificationTest.php`
18. `tests/Feature/Commands/SendBookingRemindersTest.php`
19. `tests/Feature/Commands/AutoCompleteBookingsTest.php`

### Modify (8 files)
1. `app/Notifications/BookingConfirmedNotification.php` — rewrite with Blade template
2. `app/Models/Booking.php` — add `reminders()` relationship
3. `app/Http/Controllers/Booking/PublicBookingController.php` — wire notifications
4. `app/Http/Controllers/Dashboard/BookingController.php` — wire notifications
5. `app/Http/Controllers/Booking/BookingManagementController.php` — wire cancellation notification
6. `app/Http/Controllers/Customer/BookingController.php` — wire cancellation notification
7. `routes/console.php` — register scheduled commands
8. `config/queue.php` — set `after_commit => true`

## Verification

1. Run `php artisan test --compact` — all tests pass
2. Run `vendor/bin/pint --dirty --format agent` — no style issues
3. Run `php artisan schedule:list` — both commands visible
4. Run `php artisan bookings:send-reminders` and `php artisan bookings:auto-complete` — execute without error
5. Verify email content in Laravel log (`storage/logs/`) since mail driver is `log` in dev
