# Session 3 Plan — Scheduling Engine (TDD)

## Goal

Build the scheduling engine that computes available booking slots for a given service, collaborator, and date — fully test-driven, covering availability rules, exceptions, buffers, timezone handling, and automatic collaborator assignment.

## Prerequisites

- Session 2 complete: all models, migrations, factories, seeders, enums in place (57 tests passing)
- Key models ready: `AvailabilityRule`, `AvailabilityException`, `BusinessHour`, `Booking`, `Service`, `Business`
- `assignment_strategy` column exists on `businesses` table (D-023)

## Scope

**Included:**
- `TimeWindow` value object (DTO) for time range operations
- `AvailabilityService` — computes effective available time windows for a collaborator on a date
- `SlotGeneratorService` — generates bookable slot start times from available windows, accounting for buffers and existing bookings
- Automatic collaborator assignment (first-available and least-busy round-robin)
- Comprehensive unit tests (written first) + integration test with realistic data
- Timezone handling (all calculations in business timezone, bookings stored/queried in UTC)

**Not included:**
- No UI, controllers, routes, or API endpoints
- No `getAvailableDates()` convenience method (Session 7 adds this when building the calendar)
- No booking creation logic (Session 7)

---

## Architecture

### Data Flow

```
SlotGeneratorService (public API)
  │
  ├── getAvailableSlots(business, service, date, ?collaborator)
  │     │
  │     ├── If collaborator specified → getSlotsForCollaborator()
  │     └── If null → iterate eligible collaborators, merge unique slot times
  │
  ├── assignCollaborator(business, service, startsAt)
  │     └── Uses business.assignment_strategy to pick collaborator
  │
  └── Depends on:
        AvailabilityService.getAvailableWindows(business, collaborator, date)
          │
          ├── Step 1: Compute effective business hours
          │     a. BusinessHours for weekday → TimeWindow[]
          │     b. Apply business-level block exceptions (subtract)
          │     c. Apply business-level open exceptions (add)
          │
          ├── Step 2: Compute effective collaborator availability
          │     a. AvailabilityRules for weekday → TimeWindow[]
          │     b. Apply collaborator-level block exceptions (subtract)
          │     c. Apply collaborator-level open exceptions (add)
          │
          └── Step 3: Intersect effective business hours ∩ collaborator availability
```

### TimeWindow DTO

**File:** `app/DTOs/TimeWindow.php`

```php
class TimeWindow
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}

    public function durationInMinutes(): int;
    public function overlaps(self $other): bool;
    public function contains(CarbonImmutable $point): bool;

    // Collection operations (static)
    public static function intersect(array $a, array $b): array;
    public static function subtract(array $windows, array $blocks): array;
    public static function merge(array $windows): array;
    public static function union(array $windows, array $additions): array;
}
```

Static methods operate on `TimeWindow[]` arrays. `intersect` computes pairwise overlap. `subtract` removes blocked ranges. `merge` combines overlapping/adjacent windows. `union` adds new windows and merges.

### AvailabilityService

**File:** `app/Services/AvailabilityService.php`

```php
class AvailabilityService
{
    /**
     * @return array<TimeWindow> Available windows in business timezone
     */
    public function getAvailableWindows(
        Business $business,
        User $collaborator,
        CarbonImmutable $date,  // in business timezone
    ): array;
}
```

Internal methods:
- `getBusinessHourWindows(Business, DayOfWeek, CarbonImmutable, string timezone)` → TimeWindow[]
- `getCollaboratorWindows(User, Business, DayOfWeek, CarbonImmutable, string timezone)` → TimeWindow[]
- `getExceptionsForDate(query, CarbonImmutable)` → Collection of AvailabilityException
- `applyExceptions(TimeWindow[], Collection exceptions, CarbonImmutable, string timezone)` → TimeWindow[]

Exception application order:
1. Check for full-day block (null times) → wipe all windows
2. Apply partial blocks (subtract time ranges)
3. Apply open exceptions (add time ranges)
4. Merge overlapping windows

### SlotGeneratorService

**File:** `app/Services/SlotGeneratorService.php`

```php
class SlotGeneratorService
{
    public function __construct(
        private AvailabilityService $availabilityService,
    ) {}

    /**
     * @return array<CarbonImmutable> Slot start times in business timezone
     */
    public function getAvailableSlots(
        Business $business,
        Service $service,
        CarbonImmutable $date,
        ?User $collaborator = null,
    ): array;

    /**
     * Assign collaborator for a given time using business strategy.
     */
    public function assignCollaborator(
        Business $business,
        Service $service,
        CarbonImmutable $startsAt,
    ): ?User;
}
```

**Slot generation logic** (per available window):
1. Start at window start time
2. For each candidate slot time (incrementing by `slot_interval_minutes`):
   - Compute occupied window: `(slot - buffer_before)` to `(slot + duration + buffer_after)`
   - Check: occupied start >= window start (buffer must fit)
   - Check: occupied end <= window end (service + buffer must fit)
   - Check: no overlap with existing bookings' occupied windows (booking times ± their service's buffers)
   - If all checks pass, add slot to results
3. Stop when occupied end would exceed window end

**Existing bookings query:**
- Scoped to `business_id` + `collaborator_id`
- Status in `[pending, confirmed]` only (D-031)
- Overlapping with the target date (converted to UTC for query)
- Eager-loads `service` for buffer values

**Auto-assignment strategies:**
- `first_available`: iterate eligible collaborators (by ID order), return first with an open slot
- `round_robin` (least-busy): among available collaborators, pick the one with fewest upcoming `pending`/`confirmed` bookings for this business

---

## Implementation Steps

### Phase 1: TimeWindow DTO + unit tests

1. Write tests for `TimeWindow` operations (intersect, subtract, merge, union)
2. Implement `TimeWindow` DTO with all static methods

### Phase 2: AvailabilityService (TDD)

3. Write `AvailabilityServiceTest` with tests:
   - Weekly schedule returns correct windows for a weekday
   - No rules for weekday → empty
   - Multiple windows per day (morning + afternoon)
   - Business hours intersection clips collaborator availability
   - Business-level full-day block → no availability
   - Business-level partial block → window split
   - Collaborator-level full-day block → no availability
   - Collaborator-level partial block → window reduced
   - Collaborator open exception extends availability
   - Business open exception on normally-closed day + collaborator open exception → available
   - Multiple exceptions composing on same day (block then re-open)
4. Implement `AvailabilityService` until all tests pass

### Phase 3: SlotGeneratorService (TDD)

5. Write `SlotGeneratorServiceTest` with tests:
   - Basic slot generation (60 min service, 30 min interval, 09:00-18:00)
   - Different slot intervals (15 min, 60 min)
   - `buffer_before` prevents slots where buffer extends before window start
   - `buffer_after` prevents slots where service + buffer extends past window end
   - Both buffers combined
   - Existing confirmed booking blocks overlapping slots
   - Existing pending booking blocks overlapping slots
   - Cancelled/completed/no_show bookings do NOT block slots
   - Existing booking's service buffers correctly expand the blocked window
   - Back-to-back bookings: two adjacent bookings leave no slot between them
   - Slot at exact closing time: last slot ends exactly at window end
   - No slot if service duration exceeds remaining window
   - Multiple available windows generate slots from each window independently
   - Timezone: slots returned in business timezone, bookings queried in UTC
6. Implement `SlotGeneratorService` until all tests pass

### Phase 4: Collaborator Assignment (TDD)

7. Write `CollaboratorAssignmentTest` with tests:
   - `first_available`: returns first collaborator (by ID) with open slot
   - `first_available`: skips collaborators not assigned to the service
   - `round_robin` (least-busy): returns collaborator with fewest upcoming bookings
   - `round_robin`: tie-breaking falls back to first by ID
   - No available collaborator → returns null
   - Only collaborators assigned to the service are considered
   - `getAvailableSlots` with null collaborator returns union of all eligible collaborators' slots
8. Implement assignment logic until all tests pass

### Phase 5: Integration Test

9. Write `SlotGenerationIntegrationTest`:
   - Uses RefreshDatabase + seeded "Salone Bella" data
   - Full slot calculation for a known date with known schedule
   - Verifies slot times match expected values for specific service + collaborator
   - Tests date with business-level exception (e.g., holiday closure)
   - Tests timezone handling: business in Europe/Zurich, verify UTC booking times align
   - DST transition test: pick March 29, 2026 (spring forward in Europe/Zurich), verify no duplicate/missing slots
10. Run full test suite, confirm all tests pass

### Phase 6: Cleanup

11. Run `vendor/bin/pint --dirty --format agent`
12. Run full test suite one final time
13. Update `docs/DECISIONS.md`, `docs/ROADMAP.md`, `docs/HANDOFF.md`

---

## File List

### New Files
| File | Purpose |
|------|---------|
| `app/DTOs/TimeWindow.php` | Value object for time ranges with collection operations |
| `app/Services/AvailabilityService.php` | Computes available time windows per collaborator/date |
| `app/Services/SlotGeneratorService.php` | Generates bookable slots + auto-assignment |
| `tests/Unit/Services/TimeWindowTest.php` | Unit tests for TimeWindow operations |
| `tests/Unit/Services/AvailabilityServiceTest.php` | Unit tests for availability calculation |
| `tests/Unit/Services/SlotGeneratorServiceTest.php` | Unit tests for slot generation + buffers |
| `tests/Unit/Services/CollaboratorAssignmentTest.php` | Unit tests for auto-assignment strategies |
| `tests/Feature/SlotGenerationIntegrationTest.php` | Integration test with seeded data + timezone/DST |

### Modified Files
| File | Change |
|------|--------|
| `docs/DECISIONS.md` | Add D-028 through D-031 |
| `docs/ROADMAP.md` | Check off Session 3 items |
| `docs/HANDOFF.md` | Overwrite with Session 3 handoff |

---

## Testing Plan

| Test File | Count (est.) | What it covers |
|-----------|-------------|----------------|
| `TimeWindowTest` | ~10 | intersect, subtract, merge, union, overlaps, edge cases |
| `AvailabilityServiceTest` | ~11 | Weekly schedule, business hours intersection, exceptions (block/open, full/partial, both levels) |
| `SlotGeneratorServiceTest` | ~14 | Slot generation, intervals, buffers, booking conflicts, edge cases, timezone |
| `CollaboratorAssignmentTest` | ~7 | First-available, least-busy, eligibility, null cases |
| `SlotGenerationIntegrationTest` | ~4 | End-to-end with seeded data, DST |
| **Total** | **~46** | |

All tests use `RefreshDatabase`. Unit tests create focused data via factories. Integration test uses the seeder.

---

## New Decisions to Record

- **D-028** — Round-robin uses least-busy strategy (fewest upcoming bookings, stateless)
- **D-029** — TimeWindow DTO for internal availability calculations
- **D-030** — Slot calculation works entirely in business timezone; booking queries convert to UTC
- **D-031** — Only pending and confirmed bookings block availability (per SPEC §5.3)
