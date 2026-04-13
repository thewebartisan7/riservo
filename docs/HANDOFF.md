# Handoff

**Session**: 3 — Scheduling Engine (TDD)  
**Date**: 2026-04-12  
**Status**: Complete

---

## What Was Built

Session 3 built the scheduling engine — the core service that calculates available booking slots. Built entirely test-first.

### TimeWindow DTO (`app/DTOs/TimeWindow.php`)
- Value object with `CarbonImmutable` start/end
- Instance methods: `durationInMinutes()`, `overlaps()`, `contains()`
- Static collection operations: `intersect()`, `subtract()`, `merge()`, `union()`
- Used throughout both services for all time range calculations

### AvailabilityService (`app/Services/AvailabilityService.php`)
- `getAvailableWindows(Business, User collaborator, CarbonImmutable date)` → `TimeWindow[]`
- Computes effective available time windows by:
  1. Getting business hours for the weekday → TimeWindow[]
  2. Applying business-level exceptions (blocks subtract, opens add)
  3. Getting collaborator availability rules for the weekday → TimeWindow[]
  4. Applying collaborator-level exceptions (blocks subtract, opens add)
  5. Intersecting the two sets (business hours bound collaborator availability)
- Exception query uses `whereDate()` for SQLite/MariaDB compatibility (D-030)

### SlotGeneratorService (`app/Services/SlotGeneratorService.php`)
- `getAvailableSlots(Business, Service, CarbonImmutable date, ?User collaborator)` → `CarbonImmutable[]`
  - If collaborator specified: generates slots for that collaborator
  - If null: unions slots across all eligible collaborators (assigned to service)
- `assignCollaborator(Business, Service, CarbonImmutable startsAt)` → `?User`
  - `first_available`: first collaborator (by ID) with an open slot
  - `round_robin`: least-busy — fewest upcoming confirmed/pending bookings (D-028)
- Slot generation logic:
  - Iterates available windows at `slot_interval_minutes`
  - Checks buffer fit: `(slot - buffer_before)` to `(slot + duration + buffer_after)` must fit in window
  - Checks booking conflicts: existing booking occupied windows (with their service's buffers) must not overlap
  - Only `pending`/`confirmed` bookings block (D-031)

### Tests (53 new tests, 110 total)
| File | Tests | Coverage |
|------|-------|----------|
| `tests/Unit/Services/TimeWindowTest.php` | 14 | intersect, subtract, merge, union, overlaps, contains, duration |
| `tests/Feature/Services/AvailabilityServiceTest.php` | 13 | Weekly schedule, business hours intersection, exceptions (block/open, full/partial, both levels), multi-day, compose |
| `tests/Feature/Services/SlotGeneratorServiceTest.php` | 15 | Slot intervals, buffers, booking conflicts, cancelled/completed don't block, back-to-back, edge cases, timezone |
| `tests/Feature/Services/CollaboratorAssignmentTest.php` | 7 | First-available, least-busy round-robin, eligibility, tie-breaking, null collaborator union |
| `tests/Feature/Services/SlotGenerationIntegrationTest.php` | 4 | Seeded data, Swiss National Day block, timezone UTC↔CEST, DST spring forward |

---

## Current Project State

- Database: 14 migrations, all SQLite-compatible (unchanged from Session 2)
- Models: 10 Eloquent models (unchanged from Session 2)
- **Services**: 2 new — `AvailabilityService`, `SlotGeneratorService`
- **DTOs**: 1 new — `TimeWindow`
- Tests: 110 passing (236 assertions)
- Code quality: Pint passes with 0 issues
- No controllers, routes, or views yet
- No frontend installed yet

---

## Key Conventions Established

- **TimeWindow DTO**: all time range operations go through `TimeWindow` static methods (intersect, subtract, merge, union)
- **Business timezone throughout**: `AvailabilityService` and `SlotGeneratorService` operate in the business's local timezone. Booking queries convert date boundaries to UTC. Slot start times are returned in business timezone.
- **Exception date queries**: use `whereDate()` instead of `where()` for date comparison — avoids SQLite string comparison issues with the `'date'` cast storing datetime strings
- **Exception application order**: full-day blocks wipe all → partial blocks subtract → open exceptions add back
- **Booking conflict check**: each existing booking's occupied window uses that booking's own service buffers (not the requested service's buffers)
- **Only pending/confirmed block**: cancelled, completed, and no_show bookings are completely ignored during slot calculation

---

## What Session 4 Needs to Know

Session 4 installs the frontend foundation (Inertia + React + COSS UI). No direct dependency on the scheduling engine, but good to know:

- The scheduling engine has no controllers or API endpoints yet — Session 7 (Public Booking Flow) will wire these up
- `SlotGeneratorService` is the public API for slot calculation. It is injectable via Laravel's container.
- Slot times are `CarbonImmutable` instances in the business timezone
- P-002 (React i18n approach) is still open for Session 4 to resolve

---

## Decisions Recorded

- **D-028**: Round-robin uses least-busy strategy (fewest upcoming bookings, stateless)
- **D-029**: TimeWindow DTO for internal availability calculations
- **D-030**: Slot calculation works entirely in business timezone; booking queries convert to UTC
- **D-031**: Only pending and confirmed bookings block availability
- **P-001**: Resolved — both strategies implemented per D-028

---

## Open Questions / Deferred Items

- **P-002**: React i18n approach — for Session 4
- COSS UI skill is already installed (`.agents/skills/coss` and `.agents/skills/coss-particles`). Before proceeding with Session 4, verify the skill is loading correctly by asking Claude to describe a COSS UI component or pattern — if it answers with COSS-specific knowledge, the skill is active.
- Hostpoint deployment details: needed before production
- `getAvailableDates()` convenience method: not built in Session 3 — Session 7 should add it when building the calendar UI
