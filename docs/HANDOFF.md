# Handoff

**Session**: R-4B — Booking overlap race guard
**Date**: 2026-04-16
**Status**: Complete

---

## What Was Built

R-4B closes the booking double-booking race identified in REVIEW-1 issue #3
by installing a **Postgres-level invariant** that makes overlapping
pending/confirmed bookings for the same provider impossible regardless of
concurrency, write path, or future code changes. The existing application-
level slot check is kept as the user-visible fast-fail; the exclusion
constraint is the safety net for truly concurrent arrivals.

D-066 is the new architectural decision recorded in
`docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md`.

### Schema invariant

Migration `database/migrations/2026_04_16_074718_add_overlap_guard_to_bookings_table.php`
adds to the `bookings` table:

- `buffer_before_minutes SMALLINT NOT NULL DEFAULT 0` — snapshot of the
  service's `buffer_before` at booking time.
- `buffer_after_minutes SMALLINT NOT NULL DEFAULT 0` — snapshot of the
  service's `buffer_after` at booking time.
- `effective_starts_at TIMESTAMP(0) GENERATED ALWAYS AS (…) STORED` —
  computed from `starts_at - buffer_before_minutes`.
- `effective_ends_at TIMESTAMP(0) GENERATED ALWAYS AS (…) STORED` —
  computed from `ends_at + buffer_after_minutes`.
- Constraint `bookings_no_provider_overlap`:

    EXCLUDE USING GIST (
      provider_id WITH =,
      tsrange(effective_starts_at, effective_ends_at, '[)') WITH &&
    ) WHERE (status IN ('pending', 'confirmed'))

The generated columns use `make_interval(mins => ...::int)` rather than
`(text || ' minutes')::interval` because the former is `IMMUTABLE` (required
for `STORED` generated columns) while the latter is only `STABLE`.

The constraint uses half-open interval semantics (`[)`) so abutting bookings
with zero buffers do not collide — matching the application's
`SlotGeneratorService::conflictsWithBookings()` semantics.

Only `pending` and `confirmed` bookings participate in the GIST index,
exactly mirroring D-031: cancelling a booking frees its slot without any
application-level cleanup.

### Controller transaction wrap

Both booking write paths are now restructured as:

```
try {
    $result = DB::transaction(function () use (...) {
        // 1. Re-check slot via SlotGeneratorService (fast-fail)
        // 2. Auto-assign provider if not selected
        // 3. Upsert customer
        // 4. Booking::create with buffer_*_minutes snapshot
    });
} catch (SlotNoLongerAvailableException) {
    return /* 409 slot-no-longer-available */;
} catch (NoProviderAvailableException) {
    return /* 409 no-provider-available */;
} catch (QueryException $e) {
    if (($e->getPrevious()?->getCode() ?? $e->getCode()) === '23P01') {
        return /* same 409 slot-no-longer-available */;
    }
    throw $e;
}
```

Two marker exceptions live in `app/Exceptions/Booking/`:

- `SlotNoLongerAvailableException` — raised from the fast-fail inside the
  transaction closure.
- `NoProviderAvailableException` — raised when auto-assign finds no
  eligible provider.

Notifications (`BookingConfirmedNotification`, `BookingReceivedNotification`)
fire outside the transaction, after it commits successfully.

Controllers touched:
- `app/Http/Controllers/Booking/PublicBookingController.php` — returns JSON 409.
- `app/Http/Controllers/Dashboard/BookingController.php` — returns redirect
  with `->with('error', ...)`.

### Buffer snapshot plumbing

- `app/Models/Booking.php` — `buffer_before_minutes` and
  `buffer_after_minutes` added to `#[Fillable]`.
- `database/factories/BookingFactory.php` — default state sets both to 0; a
  new `withServiceBuffers()` helper reads the linked service for tests that
  need realistic buffer snapshots.
- `database/seeders/BusinessSeeder.php` — every `Booking::create(...)` site
  passes `buffer_before_minutes` / `buffer_after_minutes` from the service
  being booked. The `Colore` service (`buffer_before=5, buffer_after=15`)
  carries its real buffers into its seeded bookings.

The `SlotGeneratorService` public API is unchanged — the engine continues
to read `service.buffer_before` / `service.buffer_after` at slot-generation
time (live, not snapshotted). Snapshotting only applies to the bookings
table, which is the right semantics: a booking is a contract for a specific
occupied window; later edits to the service's buffers must not retroactively
invalidate existing bookings.

### New test coverage

- `tests/Feature/Booking/BookingOverlapConstraintTest.php` (13 tests) —
  persistence-level matrix: direct `Booking::create` calls asserting the
  constraint fires on overlap, abutting + buffer, identical intervals,
  pending-vs-confirmed interactions, UPDATE path (reactivating a cancelled
  booking), and confirming the surfaced SQLSTATE is `23P01`. Non-blocking
  statuses (`cancelled`, `completed`, `no_show`) do not participate.
- `tests/Feature/Booking/BookingRaceSimulationTest.php` (3 tests) —
  deterministic controller race. `SlotGeneratorService` is mocked to return
  the same slot twice (simulating the "both fast-fails pass" window). First
  POST succeeds; second hits the DB constraint, the controller catches
  `23P01`, returns 409 (public) / redirect-with-error (dashboard). Covers
  both the provider-selected and auto-assign paths.
- `tests/Feature/Booking/BookingBufferGuardTest.php` (3 tests) — buffer-
  interaction: HTTP request inside a buffer window returns 409; direct
  `Booking::create` that crosses a buffer raises `23P01`; request exactly
  at the buffer boundary succeeds.

An optional `pcntl_fork`-based real-concurrency test was considered and
documented in the plan but not landed — the deterministic simulation plus
the DB constraint are the authoritative proof. If it becomes useful later,
re-add under `tests/Feature/Booking/BookingRealConcurrencyTest.php` with
an `env('RUN_CONCURRENCY_TESTS')` gate.

### Test-factory hardening

Two pre-existing tests were creating impossible state on the same provider
that the new constraint correctly rejected:

- `tests/Feature/Dashboard/DashboardHomeTest.php` — "admin sees business-wide
  stats" created 3 confirmed bookings at the exact same 14:00–15:00 UTC slot.
  Fixed: spread across 08:00 / 10:00 / 12:00 UTC.
- `tests/Feature/Dashboard/DashboardBookingsTest.php` — "pagination works"
  created 25 factory bookings on the same provider with the factory's
  random `dateTimeBetween`; intermittently overlapped. Fixed: loop across
  25 distinct days.
- `tests/Feature/Dashboard/CustomerDirectoryTest.php` — "pagination works
  for customers" had the same random-dateTimeBetween issue with 25
  bookings on the same provider. Fixed in the same way.

In every case the constraint caught a legitimately broken test, not a new
regression.

---

## Current Project State

- **Backend**: Postgres 16 with `btree_gist`; the `bookings` table enforces
  overlap as a schema invariant. No changes to `SlotGeneratorService`'s
  public API. Both booking write paths (public + dashboard) run inside
  `DB::transaction`.
- **Frontend**: no changes.
- **Routes**: no changes.
- **Tests**: full Pest suite green on Postgres — **452 passed, 1727
  assertions**. +19 from the R-4A baseline of 433.
- **Decisions**: D-066 appended to
  `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md`.
- **Migrations**: one new migration `2026_04_16_074718_add_overlap_guard_to_bookings_table`.
  `migrate:fresh --seed` runs clean.

---

## How to Verify Locally

```bash
php artisan migrate:fresh --seed
php artisan test --compact
```

Inspect the constraint:

```sql
SELECT conname, pg_get_constraintdef(oid)
FROM pg_constraint
WHERE conrelid = 'bookings'::regclass
  AND conname = 'bookings_no_provider_overlap';
```

Expect the EXCLUDE USING gist definition on
`(provider_id, tsrange(effective_starts_at, effective_ends_at, '[)'))`
with the partial predicate `status IN ('pending', 'confirmed')`.

---

## What the Next Session Needs to Know

R-4 (both A and B halves) is now complete. The roadmap-review checklist
(`docs/reviews/ROADMAP-REVIEW.md`) moves on to R-5
(deactivated collaborators still appear in booking flows), R-6 (timezone
rendering on customer-facing pages), or other listed items — order per
the developer's preference.

When implementing new booking write sites (Google Calendar pull sync,
imports, admin rescue tools), note:

- The constraint applies uniformly. A new writer that forgets a
  transaction still cannot produce overlapping bookings — the DB rejects
  the write.
- Any `QueryException` with SQLSTATE `23P01` from Postgres on a booking
  write means the new row would overlap an existing pending/confirmed
  booking for the same provider. Translate to a user-visible "slot no
  longer available" message, matching the existing controller behaviour.
- Buffer snapshots are required on every `Booking::create` call (both
  columns default to 0, so omitting them is legal but is semantically
  wrong when the service has non-zero buffers — the constraint will not
  catch a buffer-period conflict if the snapshot is missing). Use
  `BookingFactory::withServiceBuffers()` in tests.

---

## Open Questions / Deferred Items

- **Real-concurrency smoke test** — optional `pcntl_fork`-based test is
  documented in the R-4B plan but not landed. The deterministic simulation
  is authoritative; add only if a future incident argues for OS-level
  proof.
- **Availability-exception race** — an admin editing an availability
  exception while a customer books the affected slot is a separate
  user-flow concern, not a double-booking race. Out of scope; flag if
  observed.
- **Parallel test execution** (`paratest` with per-worker Postgres
  databases) — unchanged from R-4A. Revisit only if the suite grows
  painful.
- **Multi-business join flow + business-switcher UI (R-2B)** — carried
  over from earlier sessions; still deferred.
- **Dashboard-level "unstaffed service" warning** — carried over from
  R-1B; still deferred.
