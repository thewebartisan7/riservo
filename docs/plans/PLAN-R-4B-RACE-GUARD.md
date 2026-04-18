---
name: PLAN-R-4B-RACE-GUARD
description: "R-4B: booking overlap race guard via GIST exclusion constraint"
type: plan
status: shipped
created: 2026-04-16
updated: 2026-04-16
---

# PLAN-R-4B — Booking overlap race guard

**Session**: R-4B — Close the booking double-booking race
**Source**: `docs/reviews/ROADMAP-REVIEW.md` §R-4, `docs/reviews/REVIEW-1.md` issue #3
**Status**: proposed (plan-only)
**Date**: 2026-04-16
**Depends on**: R-4A (Postgres as single database engine)

---

## 1. Context

### The bug

Both booking write paths perform a read-check-write sequence with no transaction, no lock, and no DB-level invariant:

- [`app/Http/Controllers/Booking/PublicBookingController.php:194-290`](../../app/Http/Controllers/Booking/PublicBookingController.php) — `store()`:
  - Line 230: `getAvailableSlots(...)` computes availability.
  - Line 238: `$slotAvailable = collect($availableSlots)->contains(...)`.
  - Line 240-244: returns 409 if not available.
  - Line 278-290: `Booking::create(...)` — outside any transaction.
- [`app/Http/Controllers/Dashboard/BookingController.php:225-316`](../../app/Http/Controllers/Dashboard/BookingController.php) — `store()` (manual booking):
  - Line 259: `getAvailableSlots(...)`.
  - Line 267: `$slotAvailable = collect($availableSlots)->contains(...)`.
  - Line 269-271: redirects with error if not available.
  - Line 295-307: `Booking::create(...)` — outside any transaction.

Two concurrent requests for the same `(provider, time)` can both pass the availability check, and both `Booking::create` will succeed — producing overlapping bookings. This is the race described in REVIEW-1 issue #3.

### Why the existing app check is not enough

The second availability check in each controller is a read against the same transactional snapshot as the first. It narrows the window (the check happens after the rest of request processing) but does not close it. Two requests arriving within the same millisecond can both read "available" and both write.

### Current schema

- [`database/migrations/2026_04_12_191019_create_bookings_table.php`](../../database/migrations/2026_04_12_191019_create_bookings_table.php) + [`2026_04_16_000006_repoint_bookings_collaborator_id_to_provider_id.php`](../../database/migrations/2026_04_16_000006_repoint_bookings_collaborator_id_to_provider_id.php):
  - `bookings.business_id`
  - `bookings.provider_id` (FK → `providers.id`, `restrictOnDelete`)
  - `bookings.service_id`, `bookings.customer_id`
  - `bookings.starts_at` / `bookings.ends_at` — `datetime`, **clean appointment interval** (no buffers applied)
  - `bookings.status` (string, values `pending|confirmed|cancelled|completed|no_show` per D-050)
  - `bookings.source` (`riservo|google_calendar|manual`)
  - Indexes: `(business_id, starts_at)`, `(provider_id, starts_at)`, `customer_id`, `status`, unique on `cancellation_token`
- No overlap constraint of any kind.

### Buffer semantics (SPEC §5.3, D-031)

- Each Service has `buffer_before` and `buffer_after` (minutes, defaults 0).
- Customers see the clean appointment interval. The scheduler expands each existing booking by `(service.buffer_before, service.buffer_after)` and a candidate slot by the same for the requested service, then checks overlap.
- D-031 — only `pending` and `confirmed` bookings block availability. `cancelled`, `completed`, `no_show` do not.
- Only the `SlotGeneratorService::conflictsWithBookings()` path ([`app/Services/SlotGeneratorService.php:172-201`](../../app/Services/SlotGeneratorService.php)) applies buffers today. Any DB-level constraint on the clean interval would permit buffer-period races.

### What R-4A delivered (prerequisite)

- Postgres 14+ everywhere.
- `btree_gist` extension available for install.
- All other behaviour unchanged.

---

## 2. Goal and scope

### Goal

Make "no two overlapping pending/confirmed bookings for the same provider, accounting for buffers" a schema-level invariant that is impossible to violate regardless of concurrency, number of write paths, or future code changes. Keep the existing application-level slot check as a user-friendly fast-fail.

### In scope

- Snapshot `buffer_before` and `buffer_after` on the booking row at creation time.
- Compute or generate an effective interval (clean interval expanded by the snapshotted buffers) on the booking row.
- Install `btree_gist`; add a partial `EXCLUDE USING GIST` constraint scoped to `status IN ('pending','confirmed')` on `(provider_id WITH =, effective_interval WITH &&)`.
- Wrap both controller write paths in a single `DB::transaction(...)` block containing the second availability check and the `Booking::create`.
- Translate the Postgres `23P01` (exclusion_violation) exception into the existing user-visible 409 "slot no longer available" response.
- Update `BookingFactory`, `BusinessSeeder`, and `SlotGeneratorService` for buffer snapshot.
- Add tests: DB-level constraint tests, controller-level race simulation, buffer-interaction tests, regression.

### Out of scope

- Any engine-level change (done in R-4A).
- Changing the public API of `SlotGeneratorService` — its signature stays.
- Changing D-031 (which statuses block availability).
- Changing D-050 (status transitions).
- Extending the constraint to services (same provider, different service) — the constraint is `provider × interval`, which is the correct scope.
- Retrofitting buffer snapshots into historical data (pre-launch, reseed is acceptable).
- A real OS-concurrency test (`pcntl_fork`) as a CI requirement. We plan a deterministic controller-level race simulation as the primary test; a `pcntl_fork` smoke test is documented as optional and run manually.

---

## 3. Approach

Three things together:

### 3.1 Schema invariant (primary guard)

On `bookings`, add:

- `buffer_before_minutes` — `SMALLINT NOT NULL DEFAULT 0`. Snapshot of the service's `buffer_before` at booking time.
- `buffer_after_minutes` — `SMALLINT NOT NULL DEFAULT 0`. Snapshot of the service's `buffer_after` at booking time.
- `effective_starts_at` — `TIMESTAMP(0) GENERATED ALWAYS AS (starts_at - (buffer_before_minutes || ' minutes')::interval) STORED`.
- `effective_ends_at` — `TIMESTAMP(0) GENERATED ALWAYS AS (ends_at + (buffer_after_minutes || ' minutes')::interval) STORED`.

Then:

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;

ALTER TABLE bookings
  ADD CONSTRAINT bookings_no_provider_overlap
  EXCLUDE USING GIST (
    provider_id WITH =,
    tsrange(effective_starts_at, effective_ends_at, '[)') WITH &&
  ) WHERE (status IN ('pending', 'confirmed'));
```

Properties of this constraint:

- **Partial**: `WHERE status IN ('pending', 'confirmed')` — matches D-031 exactly. A cancelled booking is removed from the constraint's index and no longer blocks new bookings; a status transition from pending → cancelled removes the row from participation without any extra work.
- **Interval semantics**: `tsrange(..., ..., '[)')` is half-open on the end, so two bookings whose effective intervals touch exactly (A ends 10:00:00, B starts 10:00:00, both with zero buffers) do NOT overlap. Matches the application's overlap definition in `SlotGeneratorService::conflictsWithBookings()` (`$occupiedStart->lt($bookingOccupiedEnd) && $occupiedEnd->gt($bookingOccupiedStart)`).
- **Generated columns**: the effective interval is derived; writers cannot forget to compute it. Postgres keeps it in sync.
- **Violation**: yields `SQLSTATE 23P01` (`exclusion_violation`) — a subclass of `integrity_constraint_violation`. Laravel raises `Illuminate\Database\QueryException` whose `getSqlState()` returns `'23P01'`.

### 3.2 Transaction wrap + exception translation (last-mile correctness)

Both controller store methods are reshaped to:

```php
$result = DB::transaction(function () use (...) {
    // Re-check availability (the existing fast-fail)
    $availableSlots = $this->slotGenerator->getAvailableSlots(...);
    if (! collect($availableSlots)->contains(...)) {
        throw new SlotNoLongerAvailableException();
    }

    // Auto-assign provider if unset
    if (! $provider) {
        $provider = $this->slotGenerator->assignProvider(...);
        if (! $provider) {
            throw new NoProviderAvailableException();
        }
    }

    // Upsert customer (public flow only)
    $customer = Customer::firstOrCreate(...);
    // ...phone / name / user_id adjustments

    return Booking::create([...with buffer_before_minutes, buffer_after_minutes...]);
});
```

Wrapped with:

```php
try {
    $booking = /* the transaction block above */;
} catch (SlotNoLongerAvailableException) {
    return /* 409 response */;
} catch (NoProviderAvailableException) {
    return /* 409 response */;
} catch (QueryException $e) {
    if ($e->getSqlState() === '23P01') {
        return /* same 409 "slot no longer available" response */;
    }
    throw $e;
}
```

Notes:

- **Small private exceptions** (`SlotNoLongerAvailableException`, `NoProviderAvailableException`) live in `app/Exceptions/Booking/`. They are used to unwind the transaction cleanly; catching them is the per-controller concern. This keeps the transaction callback free of early-return plumbing.
- **Customer upsert inside the transaction**: on rollback, the customer record and its phone/name update are also rolled back. This is the right semantics — a booking that never existed should not have mutated customer details.
- **Provider assignment inside the transaction**: `assignProvider()` re-runs `getSlotsForProvider()` per candidate; if the DB state changed between fast-fail and this point, it still picks only an actually-available provider. No extra locking needed.
- **No `SELECT … FOR UPDATE`**: the exclusion constraint replaces pessimistic locking. The fast-fail + transaction + constraint together form a complete race guard.

### 3.3 Fast-fail kept in place (UX, defense-in-depth)

The application-level `SlotGeneratorService::getAvailableSlots()` call stays as the first gate inside the transaction. It:

- Produces a friendly 409 with the exact "slot no longer available" message before the DB has to reject anything.
- Exercises buffer-aware semantics at the application layer, which matches the customer-visible behaviour.
- Acts as defense-in-depth: if the DB constraint is ever dropped or relaxed, the app still catches obvious conflicts.

The constraint violation path is the **safety net** for simultaneous arrivals. In practice, the fast-fail catches nearly every conflict; the constraint catches the truly concurrent ones.

### Why this combination

- **Declarative invariant**: impossible to violate from any write path, present or future. Any new controller that calls `Booking::create` inherits the guard for free.
- **Buffer-aware**: the constraint catches buffer-period races (two bookings whose clean intervals don't overlap but whose buffered intervals do), which a clean-interval-only constraint would miss.
- **Status-aware**: partial constraint respects D-031 precisely — no manual logic to subtract "non-blocking" statuses.
- **No lock contention**: no `SELECT FOR UPDATE`, no advisory locks, no per-provider bottleneck. Postgres evaluates the exclusion constraint against the GIST index at commit time.
- **Clean exception story**: a single `23P01` SQLSTATE to catch and translate. No ambiguity with deadlock codes or other driver quirks.

### Rejected alternatives

**Constrain the clean interval only** (no buffer snapshot, no effective columns):
- Misses buffer-period races. Example: service A (zero buffers) at 10:00-10:30 and service B (15 min buffer_before) at 10:30-11:00. Clean intervals abut, don't overlap — DB permits. But B's occupied interval is 10:15-11:00, which overlaps A's 10:00-10:30. The scheduler would have rejected this; the DB would not.
- Rejected.

**Advisory lock per provider** (`pg_advisory_xact_lock(hashtext('provider:'||provider_id))`):
- Serializes writes per provider at the transaction level. Works.
- Imperative — every booking write site has to remember the lock call.
- No declarative schema invariant. A future write site that forgets the lock silently reintroduces the race.
- No structural protection if a background job or import tool writes bookings outside the controller path.
- Rejected in favour of the declarative constraint.

**Store `effective_starts_at` / `effective_ends_at` as regular (non-generated) columns**:
- Works, but every writer has to compute them correctly. A bug in computation silently weakens the constraint.
- Rejected in favour of generated columns — Postgres handles the arithmetic, writers cannot diverge.

**Snapshot buffers on a separate side table** (`booking_buffers` 1:1):
- Separates the constraint-relevant data from the row it protects. Cross-table generated columns are not supported. The exclusion constraint needs the effective interval on the bookings row.
- Rejected.

**Trigger-based enforcement** (BEFORE INSERT/UPDATE trigger that raises on overlap):
- Works on any engine but is imperative and harder to reason about than a declarative constraint.
- Slower per-insert than a GIST index lookup at MVP scale, but negligible at our load.
- Rejected in favour of the EXCLUDE constraint (the purpose-built primitive).

---

## 4. New architectural decision — D-066

**Proposed wording (to be written to `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md` during implementation):**

```md
### D-066 — Booking overlap invariant via EXCLUDE USING GIST on effective interval
- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: Both booking write paths perform a read-check-write sequence
  (availability check in application code, then `Booking::create`) with no
  transaction, no lock, and no DB-level invariant. Two concurrent requests
  can both pass the check and both insert overlapping bookings. Per D-065
  the database engine is Postgres; `EXCLUDE USING GIST` with `btree_gist`
  expresses the invariant declaratively at the schema level.
- **Decision**: The `bookings` table carries a snapshot of the service's
  buffers at booking time (`buffer_before_minutes`, `buffer_after_minutes`)
  plus Postgres generated columns `effective_starts_at` and
  `effective_ends_at`. A partial exclusion constraint,
  `bookings_no_provider_overlap`, enforces:

    EXCLUDE USING GIST (
      provider_id WITH =,
      tsrange(effective_starts_at, effective_ends_at, '[)') WITH &&
    ) WHERE (status IN ('pending', 'confirmed'))

  Both controller write paths are wrapped in `DB::transaction(...)`. The
  existing `SlotGeneratorService::getAvailableSlots()` check is kept as the
  first-line fast-fail. A Postgres `23P01` (exclusion_violation) at
  `Booking::create` is caught and translated into the same 409 "slot no
  longer available" response the fast-fail produces.
- **Consequences**:
  - A booking and its effective interval carry the buffer state of the
    service at the moment of creation; editing the service's buffers later
    does not retroactively change the effective interval of existing
    bookings. This is also semantically correct — a booking is a contract
    for a specific occupied window.
  - Only `pending` and `confirmed` bookings participate in the constraint,
    matching D-031. A cancelled booking is removed from the GIST index and
    no longer blocks new bookings.
  - New booking write sites (future jobs, imports, admin tools, Google
    Calendar pull sync) inherit the guard with no extra code. They cannot
    produce overlapping bookings even if they forget a transaction.
  - Migration rewrites the `bookings` schema; pre-launch, the seeder re-
    provisions all data.
- **Supersedes**: none.
```

---

## 5. Implementation order

Each step is its own commit, suite green at every boundary.

### Step 1 — Plumbing exceptions

Files to create:

- `app/Exceptions/Booking/SlotNoLongerAvailableException.php` — marker exception, extends `RuntimeException`.
- `app/Exceptions/Booking/NoProviderAvailableException.php` — marker exception, extends `RuntimeException`.

These are empty subclasses. No behaviour, just types for the transaction callbacks to throw.

Commit: "Add booking transaction exception types".

### Step 2 — Migration: snapshot columns + effective columns + constraint

Run `php artisan make:migration add_overlap_guard_to_bookings_table`.

Migration body:

```php
public function up(): void
{
    Schema::table('bookings', function (Blueprint $table) {
        $table->unsignedSmallInteger('buffer_before_minutes')->default(0)->after('ends_at');
        $table->unsignedSmallInteger('buffer_after_minutes')->default(0)->after('buffer_before_minutes');
    });

    // Generated columns and exclusion constraint: raw SQL, Postgres-specific (D-065).
    DB::statement(<<<'SQL'
        ALTER TABLE bookings
          ADD COLUMN effective_starts_at TIMESTAMP(0)
            GENERATED ALWAYS AS (
              starts_at - (buffer_before_minutes || ' minutes')::interval
            ) STORED,
          ADD COLUMN effective_ends_at TIMESTAMP(0)
            GENERATED ALWAYS AS (
              ends_at + (buffer_after_minutes || ' minutes')::interval
            ) STORED
    SQL);

    DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

    DB::statement(<<<'SQL'
        ALTER TABLE bookings
          ADD CONSTRAINT bookings_no_provider_overlap
          EXCLUDE USING GIST (
            provider_id WITH =,
            tsrange(effective_starts_at, effective_ends_at, '[)') WITH &&
          ) WHERE (status IN ('pending', 'confirmed'))
    SQL);
}

public function down(): void
{
    DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_no_provider_overlap');

    Schema::table('bookings', function (Blueprint $table) {
        $table->dropColumn(['effective_starts_at', 'effective_ends_at']);
        $table->dropColumn(['buffer_before_minutes', 'buffer_after_minutes']);
    });

    // Deliberately not dropping `btree_gist` — other constraints may depend on it later,
    // and CREATE EXTENSION was idempotent.
}
```

Run: `php artisan migrate`. Verify with `psql \d bookings` that the constraint is present.

Run: `php artisan test --compact`. Expect the suite still green — nothing in the app reads the new columns yet, and the old data was reseeded by `RefreshDatabase` per test with `buffer_*_minutes=0` (the default), so no existing booking pair overlaps via buffers.

Commit: "Add overlap guard to bookings table".

### Step 3 — Booking model, factory, and writer updates

Files to modify:

- [`app/Models/Booking.php`](../../app/Models/Booking.php): add `'buffer_before_minutes'` and `'buffer_after_minutes'` to `#[Fillable]`. Generated columns (`effective_starts_at`, `effective_ends_at`) are read-only — do not include in fillable; add as `'datetime'` casts if the app ever reads them (optional; the constraint operates server-side, no code path needs them yet).
- [`database/factories/BookingFactory.php`](../../database/factories/BookingFactory.php): default `buffer_before_minutes` and `buffer_after_minutes` to 0. When factories create a booking linked to a Service, a new state helper `withServiceBuffers()` reads the attached service's buffers and sets them on the booking.
- [`database/seeders/BusinessSeeder.php`](../../database/seeders/BusinessSeeder.php): every `Booking::create(...)` site sets `buffer_before_minutes` and `buffer_after_minutes` from the service's columns. For the 100-customer generation loop, pull from the picked service on each iteration.
- [`app/Http/Controllers/Booking/PublicBookingController.php`](../../app/Http/Controllers/Booking/PublicBookingController.php), [`app/Http/Controllers/Dashboard/BookingController.php`](../../app/Http/Controllers/Dashboard/BookingController.php): at the `Booking::create` call site, add `'buffer_before_minutes' => $service->buffer_before` and `'buffer_after_minutes' => $service->buffer_after`.

Not yet wrapping in transaction — that is Step 4. This step keeps the existing flow but fills in the snapshot data so the DB is consistent.

Run: `php artisan test --compact`. Expect green. The factory default (0, 0) keeps existing tests unchanged; the factory `withServiceBuffers()` helper is available for new tests in Step 5.

Commit: "Snapshot service buffers on booking rows".

### Step 4 — Wrap controllers in transaction + translate exclusion violation

Files to modify:

- [`app/Http/Controllers/Booking/PublicBookingController.php`](../../app/Http/Controllers/Booking/PublicBookingController.php): `store()` restructured per §3.2. Customer upsert, slot recheck, provider assignment, and `Booking::create` all inside one `DB::transaction(...)`. Outside the transaction, catch `SlotNoLongerAvailableException` / `NoProviderAvailableException` / `QueryException` with `$e->getSqlState() === '23P01'` and return the appropriate 409 JSON response.
- [`app/Http/Controllers/Dashboard/BookingController.php`](../../app/Http/Controllers/Dashboard/BookingController.php): `store()` restructured similarly. Returns `back()->with('error', ...)` instead of a JSON 409.
- Extract the shared "create-or-update customer + link to auth user" logic into a small private method if it stays in both controllers cleanly, or accept the mild duplication — both are fine. Prefer a tiny service method only if it does not grow scope; otherwise leave duplicated and revisit later.

Both methods should:

1. Do all non-transactional work first (validate, resolve service from business scope, parse request times). These don't need the transaction.
2. Open `DB::transaction(function () use (...) { ... })`.
3. Inside: recheck slot → resolve provider → upsert customer → `Booking::create` with buffer snapshot.
4. Outside: catch and translate.
5. Then fire notifications (kept outside the transaction — D-031-style `afterCommit` behaviour is already satisfied by the notifications being queued with `ShouldQueue + afterCommit`, but the top-level dispatch here can stay after the successful transaction block returns).

Run: `php artisan test --compact`. Expect all existing booking tests still green. The `returns 409 when slot is no longer available` test in `tests/Feature/Booking/BookingCreationTest.php` tests the fast-fail path and should still pass unchanged.

Commit: "Wrap booking writes in transaction + translate exclusion violation".

### Step 5 — New tests

Files to create:

- **`tests/Feature/Booking/BookingOverlapConstraintTest.php`** — pure persistence tests.
  - `overlapping confirmed bookings for same provider fail at DB level`
  - `abutting bookings with zero buffers succeed`
  - `abutting bookings where first has buffer_after overlap fail`
  - `abutting bookings where second has buffer_before overlap fail`
  - `overlapping bookings with cancelled status succeed`
  - `overlapping bookings with completed status succeed`
  - `overlapping bookings for different providers succeed`
  - `pending overlapping confirmed fails`
  - `confirmed overlapping pending fails`
  - `updating a cancelled booking's status to pending reinstates the constraint` — reactivating an overlap-blocker is a real workflow; verify the constraint evaluates on UPDATE too (it does, by Postgres semantics).
- **`tests/Feature/Booking/BookingRaceSimulationTest.php`** — deterministic controller-level race.
  - Strategy: mock `SlotGeneratorService::getAvailableSlots` to always return the requested slot as available (bypasses the fast-fail). Post two sequential booking requests for the exact same slot. The first succeeds; the second hits the DB constraint, the controller catches `QueryException` (`23P01`), returns 409. Verify Booking::count() === 1 and the correct JSON payload.
  - Same test for the dashboard manual-booking path, returning a redirect-with-flash instead of 409 JSON.
  - This does not require OS concurrency — it proves the "both fast-fails pass" scenario ends with exactly one booking and a clean 409, which is the only behaviour that matters.
- **`tests/Feature/Booking/BookingBufferGuardTest.php`** — buffer-interaction.
  - Service A with `buffer_after = 15`, booked 10:00-10:30.
  - Request to book the same provider at 10:30-11:00 (clean adjacent) → expect 409 because A's buffer (10:30-10:45) overlaps the request's 10:30 start.
  - Alternative: direct `Booking::create` with the conflicting buffer → expect `QueryException` with SQLSTATE 23P01.
- **Optional `tests/Feature/Booking/BookingRealConcurrencyTest.php`** — documented but NOT run in CI.
  - Uses `pcntl_fork()` to spawn two child processes that both post a booking for the same slot with a shared barrier (e.g. a file-based semaphore that both release before the `Booking::create`). Asserts exactly one booking exists after both children exit.
  - Marked `@group concurrency` and skipped unless the env flag `RUN_CONCURRENCY_TESTS=1` is set. The deterministic race simulation above is authoritative; this is confidence-building only.
  - If `pcntl_fork` adds test-runner instability, drop the test from the plan — the deterministic simulation + the DB constraint itself are sufficient proof. (See §10 Risks.)

Run: `php artisan test --compact`. Expect all new tests green; old suite still green.

Commit: "R-4B concurrency tests".

### Step 6 — Update `BusinessSeeder` buffer snapshots (verification + seed integrity)

Files to modify:

- [`database/seeders/BusinessSeeder.php`](../../database/seeders/BusinessSeeder.php): every `Booking::create(...)` takes `buffer_before_minutes` / `buffer_after_minutes` from the service being booked. Most services in the seeder have zero buffers; `Colore` has `buffer_before=5, buffer_after=15` — the two bookings that use `Colore` need those values snapshotted. Verify no seeded pair ends up overlapping once buffers are applied.

Run: `php artisan migrate:fresh --seed` and `php artisan test --compact`. Expect success.

Commit: "Update seeder for buffer snapshot".

### Step 7 — D-066 + documentation

- Append D-066 to `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md` using the wording in §4.
- `docs/HANDOFF.md` — rewritten to reflect R-4B completion.
- `docs/ROADMAP.md` — R-4 checked off.

Commit: "D-066 and handoff".

### Step 8 — Session close

```
php artisan migrate:fresh --seed
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm run build
```

Move this plan from `docs/plans/` to `docs/archive/plans/`.

---

## 6. Files to create / modify / delete

**Create:**

- `app/Exceptions/Booking/SlotNoLongerAvailableException.php`
- `app/Exceptions/Booking/NoProviderAvailableException.php`
- `database/migrations/YYYY_MM_DD_HHMMSS_add_overlap_guard_to_bookings_table.php`
- `tests/Feature/Booking/BookingOverlapConstraintTest.php`
- `tests/Feature/Booking/BookingRaceSimulationTest.php`
- `tests/Feature/Booking/BookingBufferGuardTest.php`
- (optional) `tests/Feature/Booking/BookingRealConcurrencyTest.php`

**Modify:**

- `app/Models/Booking.php`
- `app/Http/Controllers/Booking/PublicBookingController.php`
- `app/Http/Controllers/Dashboard/BookingController.php`
- `database/factories/BookingFactory.php`
- `database/seeders/BusinessSeeder.php`
- `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md` (append D-066)
- `docs/HANDOFF.md`
- `docs/ROADMAP.md`

**Delete:** none.

**Rename:** none.

---

## 7. Testing plan

Covers the four concerns from the R-4 brief: DB-level constraint, full-stack concurrency, buffer interaction, regression.

### 7.1 DB-level constraint (`BookingOverlapConstraintTest.php`)

Each test constructs Booking rows directly via `Booking::create`, asserts the second insert throws a `QueryException` whose `$e->getSqlState() === '23P01'`. Uses the Pest `expect(fn () => ...)->toThrow(...)` syntax. Matrix:

- Confirmed vs confirmed, same provider, overlapping clean interval → throws.
- Confirmed vs confirmed, same provider, identical clean interval → throws.
- Confirmed vs confirmed, same provider, abutting intervals (10:00-10:30 + 10:30-11:00) with zero buffers → succeeds.
- Confirmed at 10:00-10:30 with buffer_after=15 + confirmed at 10:30-11:00 with zero buffers → throws (effective 10:00-10:45 overlaps 10:30-11:00).
- Confirmed at 10:00-10:30 with zero buffers + confirmed at 10:30-11:00 with buffer_before=15 → throws (effective 10:15-11:00 overlaps 10:00-10:30).
- Confirmed at 10:00-10:30 + cancelled at 10:00-10:30, same provider → succeeds.
- Confirmed at 10:00-10:30 + completed at 10:00-10:30, same provider → succeeds.
- Confirmed at 10:00-10:30 + no_show at 10:00-10:30, same provider → succeeds.
- Confirmed at 10:00-10:30 for provider A + confirmed at 10:00-10:30 for provider B → succeeds.
- Pending + confirmed overlap → throws (both participate in the partial index).
- Existing cancelled booking updated to pending, overlaps a confirmed → UPDATE throws 23P01 (tests the constraint on UPDATE, not just INSERT).

### 7.2 Controller-level race simulation (`BookingRaceSimulationTest.php`)

The realistic race (both fast-fail checks pass, both `Booking::create` attempted) is simulated deterministically: use Laravel's `$this->mock(SlotGeneratorService::class, ...)` binding to make `getAvailableSlots` always return the requested time. Make two successive controller calls via the HTTP testing client — the first `Booking::create` succeeds, the second hits the constraint, the controller catches 23P01, and the user sees the 409.

- Public path: two POSTs to `/booking/{slug}/book` with identical data → first 201, second 409 with "slot no longer available".
- Dashboard manual path: two POSTs to `/dashboard/bookings` → first redirect with success, second redirect with the same error flash as a stale-slot failure.
- `expect(Booking::count())->toBe(1)` after both.

This test proves the full stack: the exclusion constraint fires, the controller translates it correctly, no second booking leaks through.

### 7.3 Buffer-interaction (`BookingBufferGuardTest.php`)

Realistic buffer-race scenarios that the clean-interval-only approach would have missed:

- Seed: provider A, service A (`buffer_after=15`), booking at 10:00-10:30.
- Attempt booking with service B (zero buffers) at 10:30-11:00 on same provider → 409 (DB constraint).
- Attempt booking with service B at 10:45-11:15 on same provider → 201 (no overlap even with buffer).

### 7.4 Optional real-concurrency smoke (`BookingRealConcurrencyTest.php`)

- Skipped by default. Enabled via `RUN_CONCURRENCY_TESTS=1 php artisan test --filter BookingRealConcurrencyTest`.
- Uses `pcntl_fork()` + a file-based barrier.
- Asserts Booking::count() === 1 after both children exit.
- Value: direct OS-level proof of the same invariant. Cost: `pcntl_fork` complicates the test harness and can flake on CI.
- Keep in the plan as documented infrastructure; do not make it a CI requirement. If it proves too painful to keep green, drop it — the deterministic simulation is the authoritative proof.

### 7.5 Regression

- All existing tests (433 baseline after R-2, plus whatever R-4A adds/keeps) remain green.
- Specifically, the existing "returns 409 when slot is no longer available" test in `tests/Feature/Booking/BookingCreationTest.php:129` still passes — it exercises the fast-fail path, which is unchanged.
- Seeder smoke: `migrate:fresh --seed` continues to succeed; no seeded bookings overlap after buffers are applied.

---

## 8. Verification steps

Session close verification:

```
php artisan migrate:fresh --seed
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm run build
```

Expected outcomes:

- `migrate:fresh --seed`: all migrations apply, `btree_gist` extension created, constraint in place, seed data loads.
- `test --compact`: suite green with the new tests added.
- `pint`: no changes needed, exit 0.
- `npm run build`: emits clean bundle (no frontend changes in R-4B).

Optional:

- `psql -d riservo-ch -c '\d bookings'` — verify the constraint is registered.
- `RUN_CONCURRENCY_TESTS=1 php artisan test --filter BookingRealConcurrencyTest` — if the optional test is kept.

---

## 9. Risks and mitigations

**Generated-column math precision.** Postgres `timestamp(0)` stores whole seconds. The `(X || ' minutes')::interval` arithmetic is exact. No floating-point concerns. Mitigation: the DB-level constraint tests in §7.1 exercise boundary cases (abutting and one-minute-overlapping); if a rounding issue exists it surfaces there.

**Service buffer edits after bookings exist.** Snapshotting buffers means a later change to `services.buffer_before` or `services.buffer_after` does not retroactively update existing bookings. Mitigation: this is explicitly the desired semantics, recorded in D-066's consequences. The settings UI in the dashboard does not need any new behaviour; future bookings pick up the new buffer automatically.

**Exclusion violation in tests that use `RefreshDatabase` transactions.** `RefreshDatabase` wraps each test in a transaction and rolls back. Postgres deferred constraints work normally inside transactions — exclusion constraints fire at statement time, not at commit. The tests in §7.1 rely on this (they catch the exception from within a single test's transaction). Mitigation: verified by construction; if any test unexpectedly rolls back to a clean state without the expected exception, investigate `RefreshDatabase` trait interactions and possibly fall back to `DatabaseTransactions` on that specific test class.

**`Booking::create` inside a transaction for the manual path when the outer request does not need one.** Wrapping in a transaction adds a ~millisecond overhead and holds a brief GIST index lock during the insert. Negligible for MVP. Mitigation: accept; no performance tuning needed.

**Deadlock surface.** The exclusion constraint evaluates via a GIST index on the bookings table. No external locks, no cross-table locks. Deadlock surface is minimal. Concurrent writes targeting different providers do not interact. Mitigation: covered by design; add a short note in D-066's consequences.

**Lock contention under load.** Per-provider contention on the GIST index is a non-issue at MVP scale. At scale, GIST writes are O(log n) and the index is partial (only pending/confirmed), keeping it small. Mitigation: re-evaluate if a specific provider ever crosses ~10k active bookings — not a near-term concern.

**Controller reshape breaks existing tests.** The transaction wrapper changes the order of side effects (customer upsert moves inside the transaction). Mitigation: Step 4's test run catches any behaviour drift. The existing tests that exercise customer-upsert-then-booking succeed because the customer is still visible after the transaction commits.

**`QueryException::getSqlState()` availability.** Laravel's `QueryException` exposes `getCode()` (the numeric driver code) but `getSqlState()` is on the `PDOException` previous. Mitigation: use `$e->getPrevious()?->getCode()` which returns the SQLSTATE as a string on PDO exceptions. Add a tiny helper that normalises this to avoid brittleness: `if (($e->getPrevious()?->getCode() ?? $e->getCode()) === '23P01') { ... }`. Verified pattern in Laravel.

**Session / connection pooling.** Postgres exclusion constraints are per-transaction, not per-session. Pgbouncer or similar transaction-mode poolers work fine. Mitigation: documented; no code change required.

**`pcntl_fork` flakiness (optional test).** If kept, may produce intermittent failures under resource constraints. Mitigation: flagged via env gate and excluded from CI by default. If flakes become an issue, delete the test — deterministic simulation is authoritative.

**Google Calendar pull sync (Session 12, not yet built).** When pull sync later creates bookings with `source=google_calendar`, the exclusion constraint still applies. External events that conflict with riservo bookings get rejected, which is correct — the collaborator already has a commitment. Mitigation: document in D-066's consequences; Session 12's plan should account for the 23P01 case in its pull-sync job.

**R-4A rollback.** If R-4A fails or is rolled back, R-4B cannot run (requires Postgres). Mitigation: R-4B strictly depends on R-4A; the plan header notes the dependency; do not start R-4B implementation until R-4A is merged and green.

---

## 10. Open questions

- **Optional real-concurrency test**: keep or drop? Recommended: keep as documented infrastructure, opt-in via env flag; the deterministic simulation is authoritative either way.
- **Generated columns exposed on the Booking model**: not needed by any current code path. Leave unfilled; add casts only if a caller starts reading them.
- **Future Cashier adoption on Business**: orthogonal to R-4B; Cashier's `subscriptions` table does not interact with the bookings invariant.
- **Availability exception races**: the Slot engine also reads `availability_exceptions`; can these race with booking creation? Out of scope — an admin editing an exception while a customer books the affected slot is a separate user-flow issue, not the double-booking race. Flag if it becomes an observed problem.

---

*Wait for developer approval before implementation. Do not start R-4B until R-4A is merged and the suite is green on Postgres.*
