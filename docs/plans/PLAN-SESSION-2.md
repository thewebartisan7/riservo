---
name: PLAN-SESSION-2
description: "Session 2: Data Layer (models, migrations, factories, seeders)"
type: plan
status: shipped
created: 2026-04-15
updated: 2026-04-15
---

# Session 2 Plan — Data Layer (Models + Migrations + Factories + Seeders)

## Goal

Create the complete data layer for all core entities defined in SPEC §13: 9 PHP enums, 11 migrations, 9 models (8 new + modify User), 8 factories, and a comprehensive seeder with realistic Swiss hair salon data.

## Prerequisites

- [x] Session 1 complete — all 2 tests pass
- [x] Laravel 13.4.0 confirmed
- [x] SQLite configured for local dev

## Scope

**Included**: All core models (Business, BusinessHour, Service, Customer, Booking, AvailabilityRule, AvailabilityException, CalendarIntegration), pivot tables (business_user, collaborator_service), PHP enums, factories with states, seeder, relationship tests.

**Excluded**: Controllers, routes, views, business logic/services (Session 3), frontend (Session 4), reserved slug blocklist (Session 5/6).

## Approach: Skip Blueprint, Use Artisan

**Deviation from D-011**: The plan recommends using `php artisan make:model -f` and `php artisan make:migration` instead of Blueprint. Reasons:

1. Blueprint generates old-convention code (`$fillable` arrays), not Laravel 13's `#[Fillable]` attributes
2. Blueprint cannot express custom FK names like `collaborator_id` pointing to `users`
3. Pivot tables with extra columns (BusinessUser.role) require manual work anyway
4. JSON columns, composite unique constraints, and enum defaults all need manual migration tuning
5. The net work is less: write clean code once vs. generate + rewrite

The ROADMAP permits this: *"Every agent is expected to reason about the HOW... If a better technical approach exists, propose it before implementing."*

---

## Implementation Steps

### Step 1: Create PHP Enums

Use `php artisan make:enum` for each. All string-backed except DayOfWeek (int-backed).

| Enum | File | Cases |
|------|------|-------|
| BookingStatus | `app/Enums/BookingStatus.php` | Pending, Confirmed, Cancelled, Completed, NoShow |
| BookingSource | `app/Enums/BookingSource.php` | Riservo, GoogleCalendar, Manual |
| PaymentMode | `app/Enums/PaymentMode.php` | Offline, Online, CustomerChoice |
| ConfirmationMode | `app/Enums/ConfirmationMode.php` | Auto, Manual |
| ExceptionType | `app/Enums/ExceptionType.php` | Block, Open |
| BusinessUserRole | `app/Enums/BusinessUserRole.php` | Admin, Collaborator |
| PaymentStatus | `app/Enums/PaymentStatus.php` | Pending, Paid, Refunded |
| AssignmentStrategy | `app/Enums/AssignmentStrategy.php` | FirstAvailable, RoundRobin |
| DayOfWeek | `app/Enums/DayOfWeek.php` | Monday=1 ... Sunday=7 (ISO 8601, matches Carbon `dayOfWeekIso`) |

### Step 2: Create Migrations (in FK dependency order)

| # | Migration | Key Columns | Indexes |
|---|-----------|------------|---------|
| 1 | `add_avatar_to_users_table` | avatar (string, nullable) | — |
| 2 | `create_businesses_table` | name, slug, description, logo, phone, email, address, timezone, payment_mode, confirmation_mode, allow_collaborator_choice, cancellation_window_hours, assignment_strategy, reminder_hours (json) | slug (unique) |
| 3 | `create_business_user_table` | business_id, user_id, role | (business_id, user_id) unique |
| 4 | `create_business_hours_table` | business_id, day_of_week, open_time, close_time | (business_id, day_of_week) |
| 5 | `create_services_table` | business_id, name, slug, description, duration_minutes, price (decimal 8,2 nullable), buffer_before, buffer_after, slot_interval_minutes, is_active | (business_id, slug) unique |
| 6 | `create_collaborator_service_table` | collaborator_id (→users), service_id | (collaborator_id, service_id) unique |
| 7 | `create_customers_table` | name, email, phone, user_id (nullable) | email (unique) |
| 8 | `create_bookings_table` | business_id, collaborator_id (→users), service_id, customer_id, starts_at, ends_at, status, source, external_calendar_id, payment_status, notes, cancellation_token | cancellation_token (unique), (business_id, starts_at), (collaborator_id, starts_at), status |
| 9 | `create_availability_rules_table` | collaborator_id (→users), business_id, day_of_week, start_time, end_time | (collaborator_id, day_of_week) |
| 10 | `create_availability_exceptions_table` | business_id, collaborator_id (nullable →users), start_date, end_date, start_time (nullable), end_time (nullable), type, reason | (business_id, start_date, end_date), (collaborator_id, start_date, end_date) |
| 11 | `create_calendar_integrations_table` | user_id, provider, access_token (text), refresh_token (text nullable), calendar_id, webhook_channel_id, webhook_expiry | (user_id, provider) |

**FK cascade rules**:
- `cascadeOnDelete` on: business_user, business_hours, collaborator_service, availability_rules, calendar_integrations (child data meaningless without parent)
- `nullOnDelete` on: customers.user_id, availability_exceptions.collaborator_id (preserve records)
- No cascade on: bookings.collaborator_id, bookings.service_id, bookings.customer_id (preserve booking history)

### Step 3: Create Models

All models follow the User.php convention: `#[Fillable]`, `#[Hidden]`, `casts()` method, `HasFactory` trait.

**Business** (`app/Models/Business.php`)
- Casts: payment_mode→PaymentMode, confirmation_mode→ConfirmationMode, assignment_strategy→AssignmentStrategy, reminder_hours→array, allow_collaborator_choice→boolean
- Relations: users() (belongsToMany via business_user with role pivot), businessHours(), services(), bookings(), availabilityExceptions()
- Scoped relations: admins(), collaborators() (using wherePivot)

**BusinessUser** (`app/Models/BusinessUser.php`) — extends Pivot
- Casts: role→BusinessUserRole

**BusinessHour** (`app/Models/BusinessHour.php`)
- Casts: day_of_week→DayOfWeek
- Relations: business()

**Service** (`app/Models/Service.php`)
- Casts: price→'decimal:2', is_active→boolean
- Relations: business(), collaborators() (belongsToMany via collaborator_service with custom FK), bookings()

**Customer** (`app/Models/Customer.php`)
- Relations: user() (nullable belongsTo), bookings()

**Booking** (`app/Models/Booking.php`)
- Casts: starts_at→datetime, ends_at→datetime, status→BookingStatus, source→BookingSource, payment_status→PaymentStatus
- Relations: business(), collaborator() (belongsTo User via collaborator_id), service(), customer()

**AvailabilityRule** (`app/Models/AvailabilityRule.php`)
- Casts: day_of_week→DayOfWeek
- Relations: collaborator() (belongsTo User), business()

**AvailabilityException** (`app/Models/AvailabilityException.php`)
- Casts: start_date→date, end_date→date, type→ExceptionType
- Relations: business(), collaborator() (nullable belongsTo User)

**CalendarIntegration** (`app/Models/CalendarIntegration.php`)
- Hidden: access_token, refresh_token
- Casts: webhook_expiry→datetime, access_token→encrypted, refresh_token→encrypted
- Relations: user()

**User** (modify `app/Models/User.php`)
- Add avatar to #[Fillable]
- Add relations: businesses(), availabilityRules(), availabilityExceptions(), services(), bookingsAsCollaborator(), calendarIntegration(), customer()

### Step 4: Create Factories

Each factory uses appropriate Faker methods and has useful states.

| Factory | Key States |
|---------|-----------|
| BusinessFactory | manualConfirmation(), noCollaboratorChoice() |
| BusinessHourFactory | morning(), afternoon() |
| ServiceFactory | inactive(), onRequest(), free() |
| CustomerFactory | registered() (links a User) |
| BookingFactory | pending(), confirmed(), cancelled(), completed(), noShow(), past(), future(), manual() |
| AvailabilityRuleFactory | (basic, weekday defaults) |
| AvailabilityExceptionFactory | forCollaborator(), open(), partialDay(), multiDay() |
| CalendarIntegrationFactory | (basic defaults) |
| UserFactory (modify) | add avatar to definition |

### Step 5: Write Seeder

**Scenario**: "Salone Bella" — Swiss hair salon in Lugano.

- 1 Business: slug `salone-bella`, timezone `Europe/Zurich`, auto-confirm, collaborator choice enabled
- Business hours: Mon-Fri 09:00-12:30 + 13:30-18:30, Sat 09:00-16:00, Sun closed
- 4 Users: Maria (admin), Luca/Sofia/Marco (collaborators) — each with different weekly schedules
- 5 Services: Taglio Donna (45min/CHF65), Taglio Uomo (30min/CHF40), Colore (90min/CHF120), Piega (30min/CHF35), Consulenza (15min/on request)
- Collaborator-service assignments (varying per person)
- Availability exceptions: 1 business-level holiday, 1 multi-day closure, 1 collaborator sick day, 1 partial absence, 1 extra availability
- 6 Customers (4 guests, 2 registered)
- 10 Bookings: 3 confirmed, 2 pending, 2 completed, 1 cancelled, 1 no-show, 1 manual

### Step 6: Write Tests

Enable `RefreshDatabase` in `tests/Pest.php` (uncomment line 18).

| Test File | What It Covers |
|-----------|---------------|
| `tests/Feature/Models/BusinessTest.php` | Factory creation, enum casts, relationships, unique slug |
| `tests/Feature/Models/BookingTest.php` | Factory + states, enum casts, all 4 belongsTo relations |
| `tests/Feature/Models/ServiceTest.php` | Business relation, collaborator pivot, nullable price |
| `tests/Feature/Models/UserRelationshipTest.php` | All User relationships (businesses, rules, exceptions, services, bookings, calendar, customer) |
| `tests/Feature/Models/AvailabilityTest.php` | AvailabilityRule + AvailabilityException creation, business vs collaborator level, date ranges, partial days |
| `tests/Feature/DatabaseSeederTest.php` | Seeder runs without error, expected record counts |

### Step 7: Verify

1. `php artisan migrate:fresh --seed` — SQLite compatibility
2. `php artisan test --compact` — all tests pass
3. `vendor/bin/pint --dirty --format agent` — code style
4. `vendor/bin/phpstan analyse` — static analysis

---

## New Decisions to Record

- **D-021**: AvailabilityException uses `business_id` + nullable `collaborator_id` (not polymorphic)
- **D-022**: Avatar field on User model (not BusinessUser pivot)
- **D-023**: `assignment_strategy` column on Business added in Session 2 (default: first_available), logic in Session 3
- **D-024**: `day_of_week` uses ISO 8601 (1=Monday, 7=Sunday) matching Carbon `dayOfWeekIso`
- **D-025**: Enum fields stored as strings in DB for SQLite compatibility; PHP string-backed enums with Eloquent cast
- **D-026**: Skip Blueprint (D-011 revised) — use `php artisan make:model/migration` for cleaner Laravel 13 output
- **D-027**: FK naming: `collaborator_id` (not `user_id`) on Booking, AvailabilityRule, AvailabilityException to clarify the relationship semantics

## File List (~47 files)

**New files**: 9 enums, 11 migrations, 8 new models + 1 pivot model, 8 new factories, 1 new seeder, ~6 test files
**Modified files**: User.php, UserFactory.php, DatabaseSeeder.php, tests/Pest.php
