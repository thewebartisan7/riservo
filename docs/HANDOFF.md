# Handoff

**Session**: 10 — Notifications (Email)  
**Date**: 2026-04-13  
**Status**: Complete

---

## What Was Built

Session 10 implemented the complete transactional email notification system for all booking lifecycle events, two scheduled commands, and production deployment documentation.

### Migration

- `booking_reminders` table: `booking_id` (FK), `hours_before`, `sent_at`, unique constraint on `(booking_id, hours_before)` — see D-056

### Model

- `BookingReminder`: belongs to Booking, used for reminder deduplication
- `Booking`: added `reminders()` HasMany relationship

### Notification Classes (4 total, all queued)

1. **BookingConfirmedNotification** (rewritten) — sent to customer when booking is auto-confirmed or manually confirmed by admin
2. **BookingReceivedNotification** (new) — sent to business admins + assigned collaborator on new booking creation or confirmation; `$context` param ('new' | 'confirmed') adapts subject/content (D-057)
3. **BookingCancelledNotification** (new) — sent on cancellation; `$cancelledBy` param ('customer' | 'business') determines recipients and content
4. **BookingReminderNotification** (new) — sent to customer by scheduled command based on `Business.reminder_hours`

All use `implements ShouldQueue`, Blade markdown templates, business timezone for date formatting.

### Blade Email Templates (4 files)

- `resources/views/mail/booking-confirmed.blade.php`
- `resources/views/mail/booking-received.blade.php`
- `resources/views/mail/booking-cancelled.blade.php`
- `resources/views/mail/booking-reminder.blade.php`

Published Laravel mail views in `resources/views/vendor/mail/` — customized header to remove Laravel-specific logo check.

### Scheduled Commands (2)

- `bookings:send-reminders` — every 5 minutes, `withoutOverlapping()`. Finds confirmed bookings starting within ±5 min of each configured reminder interval. Uses `booking_reminders` table for deduplication
- `bookings:auto-complete` — every 15 minutes. Transitions confirmed bookings past `ends_at` to `completed` status

### Controller Wiring (4 controllers modified)

- **PublicBookingController**: sends `BookingConfirmedNotification` to customer only when auto-confirmed (not for pending); always sends `BookingReceivedNotification` to staff
- **Dashboard\BookingController**: on status change to confirmed → notifies customer + staff; on cancel → notifies customer. Manual booking creation → notifies customer + staff (excludes creating admin)
- **BookingManagementController**: customer cancel via token → notifies admins + collaborator
- **Customer\BookingController**: customer cancel via auth → notifies admins + collaborator

### Queue Config

- `config/queue.php`: set `after_commit => true` for database connection — notifications only dispatch after DB commit

### Documentation

- `docs/DEPLOYMENT.md`: server requirements, scheduler cron, queue worker + Supervisor config, required `.env` keys, Hostpoint SMTP template
- `.env.example`: added commented Hostpoint SMTP config block

### Tests (6 files, 22 tests)

- `BookingConfirmedNotificationTest` — auto-confirm dispatch, pending skips, dashboard confirm, subject check (4 tests)
- `BookingReceivedNotificationTest` — staff dispatch, pending dispatch, manual booking, context-aware subject (4 tests)
- `BookingCancelledNotificationTest` — token cancel, dashboard cancel, auth cancel, subjects (5 tests)
- `BookingReminderNotificationTest` — subject check (1 test)
- `SendBookingRemindersTest` — time window, dedup, per-business config, cancelled skip (4 tests)
- `AutoCompleteBookingsTest` — confirmed past, pending skip, future skip, cancelled skip (4 tests)

---

## Current Project State

- **Backend**: 22 migrations, 12 models, 4 services, 1 DTO, 23 controllers, 22 form requests, 5 notifications, 3 custom middleware, 2 scheduled commands
- **Frontend**: 34 pages, 5 layouts, 55 COSS UI components, 4 onboarding components, 6 booking components, 3 dashboard components, 5 settings components
- **Tests**: 369 passing (1280 assertions)
- **Build**: `npm run build` succeeds, `vendor/bin/pint` clean

---

## Key Conventions Established

- **Notification pattern**: Customer notifications use `Notification::route('mail', $email)->notify(...)` (anonymous notifiable). Staff notifications use `Notification::send($userCollection, ...)` since User is Notifiable
- **Staff notify helper**: Controllers that notify staff collect admins + collaborator, unique by ID, optionally exclude the acting user
- **Blade markdown templates** in `resources/views/mail/` — all use `<x-mail::message>`, `<x-mail::panel>`, `<x-mail::button>` components
- **Queued notifications**: all 4 booking notifications implement `ShouldQueue`. Queue `after_commit` is true — notifications dispatch only after DB transaction commits
- **Reminder deduplication**: `booking_reminders` table with unique constraint prevents duplicate sends (D-056)
- **Scheduled commands**: registered in `routes/console.php` using `Schedule::command()` facade

---

## What Session 11 Needs to Know

Session 11 implements billing (Laravel Cashier).

- **Queue worker** must be running for notifications to be processed — `php artisan queue:work` or Supervisor in production
- **Scheduler** must be running for reminders and auto-complete — `php artisan schedule:run` via cron
- **Business model** already has the `reminder_hours` JSON field — billing may need to gate reminder features behind paid plans
- **`after_commit` is true** on the database queue — any new queued jobs in billing will also wait for DB commit before dispatching
- **Existing notification classes** follow a consistent pattern — if billing needs email notifications (e.g., payment failed), follow the same `implements ShouldQueue` + Blade markdown template pattern

---

## Decisions Recorded

- **D-056**: Reminder deduplication via `booking_reminders` table
- **D-057**: Merged BookingReceivedNotification for staff (covers "new booking" and "booking confirmed to collaborator")

---

## Open Questions / Deferred Items

- **Bundle size**: unchanged from Session 9 (~811 KB JS) — no frontend changes in this session
- **is_active filtering in public booking**: still deferred from Session 9 — `SlotGeneratorService` and `PublicBookingController::collaborators()` should filter out deactivated collaborators
- **Onboarding fetch() migration**: still deferred — onboarding step-1 uses raw `fetch()` instead of `useHttp`
- **Email template translations**: all templates use `__()` but only English keys exist. IT/DE/FR translations are pre-launch work per D-008
