# Handoff

**Session**: 2 — Data Layer (Models + Migrations + Seeders)  
**Date**: 2026-04-12  
**Status**: Complete

---

## What Was Built

Session 2 created the complete data layer for all core entities in the application.

### PHP Enums (9 files in `app/Enums/`)
- `BookingStatus` (Pending, Confirmed, Cancelled, Completed, NoShow)
- `BookingSource` (Riservo, GoogleCalendar, Manual)
- `PaymentMode` (Offline, Online, CustomerChoice)
- `ConfirmationMode` (Auto, Manual)
- `ExceptionType` (Block, Open)
- `BusinessUserRole` (Admin, Collaborator)
- `PaymentStatus` (Pending, Paid, Refunded)
- `AssignmentStrategy` (FirstAvailable, RoundRobin)
- `DayOfWeek` (Monday=1 through Sunday=7, ISO 8601)

### Migrations (11 files in `database/migrations/`)
1. `add_avatar_to_users_table` — adds nullable avatar to existing users table
2. `create_businesses_table` — full business entity with all SPEC fields
3. `create_business_user_table` — pivot with role column, unique composite
4. `create_business_hours_table` — weekly open/close per day
5. `create_services_table` — with composite unique on (business_id, slug)
6. `create_collaborator_service_table` — pivot with custom FK `collaborator_id`
7. `create_customers_table` — separate from users (D-004)
8. `create_bookings_table` — all SPEC fields, multiple indexes
9. `create_availability_rules_table` — collaborator weekly schedule
10. `create_availability_exceptions_table` — nullable collaborator_id (D-021)
11. `create_calendar_integrations_table` — encrypted token storage

### Models (9 files in `app/Models/`)
- `Business`, `BusinessUser` (Pivot), `BusinessHour`, `Service`, `Customer`, `Booking`, `AvailabilityRule`, `AvailabilityException`, `CalendarIntegration`
- `User` modified: added avatar field, 7 relationship methods

### Factories (8 files in `database/factories/`)
- All models have factories with useful states (e.g., Booking: pending/confirmed/cancelled/completed/noShow/past/future/manual)
- UserFactory updated with avatar field

### Seeder
- `BusinessSeeder`: "Salone Bella" — Swiss hair salon in Lugano
  - 4 users (1 admin Maria, 3 collaborators Luca/Sofia/Marco)
  - 5 services with varying durations/prices/buffers
  - 11 business hours (Mon-Sat, with lunch break)
  - 36 availability rules (per-collaborator weekly schedules)
  - 5 availability exceptions (2 business-level, 3 collaborator-level)
  - 6 customers (4 guests, 2 registered)
  - 10 bookings across all statuses

---

## Current Project State

- Database: 14 migrations, all SQLite-compatible
- Models: 10 Eloquent models with full relationships and enum casts
- Tests: 57 passing (93 assertions) — model relationships, enum casts, factory states, seeder
- Code quality: Pint and Larastan (level 5) pass with 0 issues
- No controllers, routes, or views yet
- No frontend installed yet

---

## Key Conventions Established

- **Laravel 13 model attributes**: `#[Fillable]` and `#[Hidden]` PHP attributes (not `$fillable` arrays)
- **Enum casting**: all status/type/role fields use PHP string-backed enums with Eloquent casts
- **FK naming**: `collaborator_id` for user-as-collaborator references; `user_id` for generic user references (D-027)
- **DayOfWeek**: ISO 8601 (1=Monday, 7=Sunday) matching Carbon's `dayOfWeekIso` (D-024)
- **AvailabilityException ownership**: `business_id` always set + nullable `collaborator_id` (D-021)
- **CalendarIntegration tokens**: `encrypted` cast for access_token/refresh_token, `#[Hidden]`
- **PHPDoc generics**: BelongsToMany with custom pivot requires 4 template params: `BelongsToMany<Model, $this, PivotClass, 'pivot'>`

---

## What Session 3 Needs to Know

Session 3 builds the scheduling engine (TDD). The data layer is complete and ready.

### Key models for Session 3:
- `AvailabilityRule` — collaborator weekly schedule (day_of_week, start_time, end_time)
- `AvailabilityException` — overrides (block/open), business or collaborator level, date range + optional time range
- `BusinessHour` — business-level outer bounds (day_of_week, open_time, close_time)
- `Booking` — existing bookings with starts_at/ends_at and buffer info on Service
- `Service` — duration_minutes, buffer_before, buffer_after, slot_interval_minutes

### Slot calculation order (SPEC §5.3):
1. Get collaborator's AvailabilityRules for the weekday
2. Intersect with BusinessHours (outer bounds)
3. Apply business-level AvailabilityExceptions
4. Apply collaborator-level AvailabilityExceptions
5. Subtract existing Bookings (including buffer_before + buffer_after)
6. Generate slots at service's slot_interval_minutes

### P-001 status:
The `assignment_strategy` column exists on Business (default: `first_available`). Session 3 agent should decide whether to implement round-robin or defer it.

---

## Open Questions / Deferred Items

- **P-001**: Assignment strategy implementation — for Session 3
- **P-002**: React i18n approach — for Session 4
- VenaUI (`vena-ui`) npm package: confirm availability before Session 4
- Hostpoint deployment details: needed before production
