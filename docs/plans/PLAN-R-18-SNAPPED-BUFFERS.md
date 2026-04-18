---
name: PLAN-R-18-SNAPPED-BUFFERS
description: "R-18: slot generator reads snapped booking buffers (Round 2, Session B)"
type: plan
status: shipped
created: 2026-04-16
updated: 2026-04-16
---

# PLAN-R-18 — Slot generator reads snapped booking buffers (Round 2, Session B)

> **Status**: Draft — awaiting approval
> **Roadmap item**: R-18 in `docs/reviews/ROADMAP-REVIEW-2.md`
> **Source finding**: REVIEW-2 HIGH-2 (`docs/reviews/REVIEW-2.md`)
> **Baseline**: post-R-17, Pest suite **506 passed / 2172 assertions**
> **Related decisions**: D-066 (snapshot buffers + EXCLUDE GIST). No new decision required — this is a read/write consistency fix.

---

## 1. Goal

Align the slot-generator read path with the D-066 write-side invariant. D-066 persists the buffer snapshot on each booking (`bookings.buffer_before_minutes`, `bookings.buffer_after_minutes`) and derives the authoritative occupied interval via two Postgres generated columns (`effective_starts_at`, `effective_ends_at`). The DB enforces non-overlap through `bookings_no_provider_overlap` EXCLUDE USING GIST over those generated columns.

The read path in `SlotGeneratorService::conflictsWithBookings()` still rehydrates the occupied interval from `$booking->service->buffer_before` / `->buffer_after` — the live, mutable service values. Editing a service's buffers after bookings exist makes the slot generator disagree with the DB: the UI can advertise a slot that 409s at create time, or hide a slot the DB would now accept.

This session replaces the live-service-derived arithmetic in the slot generator with a direct read of the generated columns, so the read path is a literal mirror of the DB invariant.

## 2. Scope

### In scope

1. `SlotGeneratorService::conflictsWithBookings()` reads `effective_starts_at` / `effective_ends_at` directly from the `Booking` row and drops the local `subMinutes` / `addMinutes` arithmetic for the *other* (already-booked) side of the comparison.
2. Audit: grep confirms `conflictsWithBookings()` is the only read-path site that rehydrates occupied intervals from `$booking->service->buffer_*`. No other sites need migration.
3. Two regression tests locking the new behaviour: grow-after and shrink-after service-buffer edits must not change how existing bookings are treated by the slot generator.
4. `BookingBufferGuardTest.php` and `BookingOverlapConstraintTest.php` stay green; extended in place if that's the cleanest home for the regressions.
5. Session-close: HANDOFF rewrite, roadmap tick-through, Pint clean, full test suite green, plan file archived.

### Out of scope (roadmap-confirmed)

- Historical data migration for pre-D-066 bookings — project reseeds pre-launch; no backfill gap. Already recorded in `docs/BACKLOG.md`.
- Reclassifying `services.buffer_before` / `buffer_after` as pure read-only display values going forward — tracked as a future consolidation, not an R-18 deliverable.
- Frontend work — no UI surfaces change. `npm run build` will still be run as a belt-and-braces sanity check.

### Expressly not touched (per R-17 carry-over conventions)

- `Service::structurallyBookable` / `structurallyUnbookable` scopes and the `bookability` Inertia shared prop — R-17's centralisation stands.
- The `service` relation on `Booking` stays eager-loaded (`->with('service')` in `getBlockingBookings()`) because display sites depend on it. Only the slot-math branch stops reading from it.
- The D-030 explicit-UTC `CarbonImmutable::createFromFormat('Y-m-d H:i:s', ..., 'UTC')` pattern is preserved, just re-pointed at the two generated columns.

## 3. Confirmed design decisions (from the pre-plan round)

| Decision | Choice | Rationale |
| --- | --- | --- |
| Occupied-interval source | **(b) select `effective_starts_at` / `effective_ends_at` directly** | Literal mirror of the D-066 GIST invariant. If the generated-column formula ever grows a third buffer kind, the read path updates for free. No null-coalesces needed (generated columns are `NOT NULL`). |
| Carbon construction | **`CarbonImmutable::createFromFormat('Y-m-d H:i:s', $raw, 'UTC')`** applied to `effective_starts_at` / `effective_ends_at` | D-030 rationale unchanged — avoids Carbon testNow timezone pollution in tests. |
| Relation handling | **Keep `->with('service')` eager-load** | `generateSlotsFromWindow()` still reads `$service->slot_interval_minutes` / `buffer_before` / `buffer_after` / `duration_minutes` of the *new* slot's service; the same relation is needed by display code. Only the `conflictsWithBookings()` branch stops using it. |

## 4. Target behaviour

### 4.1 Read path semantics

For the *new* slot being generated, `generateSlotsFromWindow()` continues to compute the candidate occupied interval from the *current* service (`$service->buffer_before`, `$service->buffer_after`, `$service->duration_minutes`). This is correct — a new booking snaps the *current* buffers at creation, matching both controller write paths.

For each *existing* booking in `$bookings`, the occupied interval is read directly from the generated columns on the booking row itself, with no dependency on `$booking->service`:

```php
$bookingOccupiedStart = CarbonImmutable::createFromFormat(
    'Y-m-d H:i:s',
    $booking->getRawOriginal('effective_starts_at'),
    'UTC',
);
$bookingOccupiedEnd = CarbonImmutable::createFromFormat(
    'Y-m-d H:i:s',
    $booking->getRawOriginal('effective_ends_at'),
    'UTC',
);
```

The two-line arithmetic block (`subMinutes($bookingBufferBefore)` / `addMinutes($bookingBufferAfter)`) is removed. The overlap test (`start1 < end2 AND end1 > start2`) is unchanged.

### 4.2 Why `getRawOriginal` still works

- `bookings.effective_starts_at` / `effective_ends_at` are `STORED` generated columns — Eloquent's default `SELECT *` populates them, so `getRawOriginal()` returns the Postgres-rendered `Y-m-d H:i:s` string.
- They are not declared in `$fillable` / `$casts`, so no cast drift; no risk of Laravel swapping the value out from under us.
- `getBlockingBookings()` does not need to change — no explicit column list to extend.

### 4.3 Guard against accidental regression

A brief PHPDoc comment on `conflictsWithBookings()` explains the read must stay on the generated columns to preserve the D-066 invariant. Prose only — no behaviour change. The comment is the *only* added line; no refactor of surrounding code.

## 5. Implementation outline

### 5.1 `app/Services/SlotGeneratorService.php`

Single edit. Replace the body of `conflictsWithBookings()` (lines 172-201) with the `getRawOriginal('effective_starts_at'/'effective_ends_at')` read. The method signature, the `foreach` shape, and the overlap expression stay identical. Add a two-line doc comment citing D-066.

No change to `getBlockingBookings()`. No change to `generateSlotsFromWindow()`. No change to `getEligibleProviders()` / `leastBusyProvider()` / `assignProvider()`.

### 5.2 Audit step

Run:

```
grep -rn "->service->buffer_before\|->service->buffer_after" app/
```

Pre-audit result (already captured): only `SlotGeneratorService.php:178-179`. If the audit at implementation time surfaces anything new (e.g. a file that landed between this plan and approval), it is migrated in the same pass using the same pattern.

For completeness, confirm nothing else does ad-hoc `->service->buffer_*` reads on the read path:

```
grep -rn "buffer_before\|buffer_after" app/
```

Expected matches are confined to:

- controller write paths persisting the snapshot (keep as-is),
- Onboarding / Service form requests and the `Service` model fillable,
- `Dashboard\Settings\ServiceController` read for the service edit form,
- the `generateSlotsFromWindow()` read from the *current* service (correct — new slot snap).

Nothing else should touch `$booking->service->buffer_*` after the edit.

## 6. Tests

### 6.1 Regression test A — buffers grown after booking

Home: extend `tests/Feature/Booking/BookingBufferGuardTest.php` (closest shape — already has a service with `buffer_after = 15`, business hours, an availability rule, and a confirmed booking in fixture).

Shape (outline, final names and asserts finalised at implementation):

1. `beforeEach` fixture already seeds Service A with `buffer_after = 15`; reuse it.
2. Create a confirmed booking at 10:00–10:30 with `buffer_after_minutes = 15` — snapped interval 10:00–10:45 local.
3. After insert, update `$this->serviceA->update(['buffer_after' => 120])` — simulates admin widening buffers post-booking.
4. Call `SlotGeneratorService::getAvailableSlots()` for the same provider / service / date.
5. Assert the set of returned slots matches what the snapped 10:45 end implies (11:00 local is free) — **not** what the widened 12:30 live buffer would imply.
6. Cross-check: a direct `Booking::create()` at 10:45 local against the same provider **succeeds** (DB invariant confirms the snapshot is 10:45). The slot generator and DB agree.

### 6.2 Regression test B — buffers shrunk after booking

Mirror of 6.1 in the same file.

1. Same fixture, same booking at 10:00–10:30 with `buffer_after_minutes = 15`.
2. `$this->serviceA->update(['buffer_after' => 0])` — shrink post-booking.
3. Slot generator must still treat 10:30–10:45 local as blocked (the snapshot is authoritative).
4. Cross-check: a direct `Booking::create()` at 10:30 local against the same provider **fails with 23P01** — the DB also still treats 10:30–10:45 as blocked.

### 6.3 Existing tests

- `BookingBufferGuardTest.php` — three existing tests plus the two new regressions. All green.
- `BookingOverlapConstraintTest.php` — unchanged. Its assertions are already on the DB invariant directly; no read-path dependency.
- Suite-wide expectation: **508 passed** (+2 from R-17's 506). No existing test should need rewriting because no read path today asserts on the "live service buffers win on read" mis-behaviour — that mis-behaviour is the bug this session fixes.

If, during implementation, any existing test turns out to have implicitly relied on the old behaviour (e.g. a test that mutates service buffers mid-flight and expected slot visibility to follow), it is updated in place with a one-line note explaining the shift to snapshot semantics.

## 7. Decisions to record

None. R-18 is a consistency fix that aligns the read path with the already-stated D-066 write-side invariant. If the work surfaces an under-stated clause in D-066 (e.g. "buffers on a booking are immutable snapshots **for all purposes, read and write**"), a short clarifying note will be appended in place to D-066 in `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md`. No new `D-NNN` will be created just for this fix.

## 8. Risks and mitigations

| Risk | Mitigation |
| --- | --- |
| `getRawOriginal('effective_starts_at')` returns `null` on a row that was just created mid-request and not re-fetched. | `getBlockingBookings()` always uses `->get()` from a fresh query — generated columns are populated server-side on INSERT and returned in the next SELECT. Plus: the hot call path is "read bookings before generating slots", not "create then re-read". |
| Postgres returns `effective_starts_at` with a different string format in some edge case. | The generated-column definition is `TIMESTAMP(0)` — the Postgres driver formats as `Y-m-d H:i:s`, identical to the existing `starts_at` / `ends_at` read that already uses the same `createFromFormat` pattern. |
| A future `Booking` cast or mutator accidentally reshapes `effective_starts_at`. | `getRawOriginal` bypasses casts deliberately (that is why `starts_at` / `ends_at` already use it per D-030). Same guarantee carries over. |
| The `service` eager-load disappears in a future refactor, surprising display code that depends on it. | Orthogonal to this session. The eager-load stays; the slot-math branch simply no longer depends on it. |

## 9. Session-done checklist

- `php artisan test --compact` → ≥ 508 passed (baseline 506 + 2 regressions).
- `vendor/bin/pint --dirty --format agent` → `{"result":"pass"}`.
- `npm run build` → green (no frontend change expected, run as sanity check).
- `docs/HANDOFF.md` rewritten (overwrite, not append) reflecting R-18 closure and the new state.
- `docs/reviews/ROADMAP-REVIEW-2.md` — R-18 status line updated to **complete** with closing date 2026-04-16.
- `docs/decisions/` untouched unless the work surfaces a D-066 clarifying note (append in place only).
- `docs/plans/PLAN-R-18-SNAPPED-BUFFERS.md` moved to `docs/archive/plans/`.
- `docs/BACKLOG.md` — no additions needed; the two R-18 out-of-scope items (historical data migration, service-buffer reclassification) are already confirmed in the roadmap's Out-of-scope section and call for no new backlog entries.

## 10. Estimated change surface

- **Code**: 1 file, ~12 lines changed (`app/Services/SlotGeneratorService.php::conflictsWithBookings`).
- **Tests**: 1 file, +2 tests in `tests/Feature/Booking/BookingBufferGuardTest.php`.
- **Docs**: `docs/HANDOFF.md` rewrite, `docs/reviews/ROADMAP-REVIEW-2.md` tick.
- **Config / migrations / routes / i18n / frontend**: none.
