# Handoff

**Session**: R-5 — Provider lifecycle coherence (historical bookings) + R-6 — Customer-facing timezone rendering
**Date**: 2026-04-16
**Status**: Complete

---

## What Was Built

R-5 and R-6 were bundled because they touch the same customer-facing
controllers and pages and are both pure display-correctness fixes. The
original R-5 concern ("deactivated providers still appear in NEW booking
flows") was already fully fixed by D-061's shift to `SoftDeletes` on
`Provider`; the R-5 investigation surfaced an adjacent latent 500 where
display code would crash the moment an admin deactivated a provider with
history. R-6 was a stale-TZ rendering bug on the customer-facing views.

D-067 is the new architectural decision recorded in
`docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md`.

### Core fix (R-5): historical provider is safe to dereference

`app/Models/Booking.php` — the `provider()` relation is overridden to
resolve the related row regardless of `deleted_at`:

```php
/** @return BelongsTo<Provider, $this> */
public function provider(): BelongsTo
{
    return $this->belongsTo(Provider::class)->withTrashed();
}
```

This is the single asymmetry in the codebase. Everywhere else, the default
`SoftDeletingScope` on `Provider` continues to exclude trashed providers:
`SlotGeneratorService::getEligibleProviders`, `$service->providers()`,
`$business->providers()`, `PublicBookingController::providers`,
`PublicBookingController::store`, and `BelongsToCurrentBusiness`
validation all inherit the scope unchanged. A booking whose provider has
been soft-deleted now renders its original provider name on every display
page instead of throwing a null-dereference 500.

### Read-side payload plumbing (`is_active` + `timezone`)

Four display controllers were updated to expose
`provider.is_active = ! $booking->provider->trashed()` so the UI can render
a "(deactivated)" marker:

- `app/Http/Controllers/Dashboard/CalendarController.php`
- `app/Http/Controllers/Dashboard/BookingController.php::index`
- `app/Http/Controllers/Dashboard/DashboardController.php::index`
  (today's bookings)
- `app/Http/Controllers/Dashboard/CustomerController.php::show`
  (customer booking history)
- `app/Http/Controllers/Booking/BookingManagementController.php::show`
- `app/Http/Controllers/Customer/BookingController.php::index`

The last controller additionally gained a `business: { name, timezone }`
entry on every booking in the `formatBooking` helper (R-6). Previously the
customer's "My Bookings" view had no access to the business's timezone and
therefore rendered UTC strings in the browser's locale.

### Frontend — shared datetime helpers + deactivated marker

Customer-facing pages now consume the business timezone via the shared
`resources/js/lib/datetime-format.ts` helpers instead of raw
`new Date(...).toLocale*()`:

- `resources/js/pages/bookings/show.tsx` — uses `formatDateMedium` /
  `formatTimeShort` with `booking.business.timezone`.
- `resources/js/pages/customer/bookings.tsx::BookingItem` — same.

`formatDateMedium` in `datetime-format.ts` grew an optional `timezone`
parameter to match the other helpers.

Both pages render the deactivated marker at every provider-name site via
`t(':name (deactivated)', { name: provider.name })`. The dashboard got the
same treatment in five places:

- `resources/js/pages/dashboard.tsx` (today's bookings table)
- `resources/js/pages/dashboard/bookings.tsx` (full bookings list)
- `resources/js/pages/dashboard/customer-show.tsx` (customer history)
- `resources/js/components/dashboard/booking-detail-sheet.tsx`
- `resources/js/components/calendar/calendar-event.tsx` (tight-popover)

The new translation key `:name (deactivated)` is wired through
`lang/en.json`.

### TypeScript types updated

`resources/js/types/index.d.ts`:

- `BookingDetail.provider`, `BookingSummary.provider`,
  `DashboardBooking.provider`, `TodayBooking.provider`, and
  `CustomerBookingHistory.provider` all gained `is_active: boolean`.
- `BookingSummary.business` gained `timezone: string` to support the R-6
  fix on `customer/bookings.tsx`.

### New test coverage (+9 tests)

R-5 regression (trashed providers stay out of NEW booking flows — locks
D-061's invariant against future regressions):

- `tests/Feature/Services/SlotGeneratorServiceTest.php` —
  `soft-deleted provider is excluded from eligible providers`.
- `tests/Feature/Booking/ProvidersApiTest.php` —
  `soft-deleted provider is not returned`.
- `tests/Feature/Booking/BookingCreationTest.php` —
  `soft-deleted provider_id is rejected on public store`. Asserts 422
  with `provider_id` validation error (not 409 as the plan speculated —
  the Form Request's inline closure rejects the ID before the 409 path
  is reached).

R-5 gap (display of HISTORICAL bookings with a trashed provider):

- `tests/Feature/Dashboard/CalendarControllerTest.php` —
  `calendar renders bookings for a deactivated provider with is_active=false`.
- `tests/Feature/Dashboard/DashboardBookingsTest.php` —
  `bookings list renders bookings for a deactivated provider with is_active=false`.
- `tests/Feature/Booking/BookingManagementTest.php` —
  `booking management page renders with deactivated provider`.
- `tests/Feature/Customer/BookingsListTest.php` —
  `customer bookings list renders with deactivated provider` (new file).

R-6 contract (customer-facing pages receive `business.timezone`):

- `tests/Feature/Booking/BookingManagementTest.php` —
  `booking management page passes business.timezone through to the page`.
- `tests/Feature/Customer/BookingsListTest.php` —
  `customer bookings list passes business.timezone per booking`.

### Incidental test-stability fix

`tests/Feature/Dashboard/CustomerDirectoryTest.php` — "customer detail
shows booking history" was using `Booking::factory()->dateTimeBetween(...)`
with random intervals on the same provider. The GIST constraint from
D-066 correctly flagged the occasional overlap as a bug in the test.
Rewrote to deterministic `CarbonImmutable::parse('2026-05-01 09:00',
'UTC')->addDays($i)`, matching the pattern used in the R-4B pagination
fix (commit 67d7e16).

---

## Current Project State

- **Backend**: `Booking::provider()` is the only relation that resolves a
  trashed row. Every other provider query path keeps the default
  `SoftDeletingScope`. Eight read-side payloads now expose
  `provider.is_active`; two customer-facing payloads now carry
  `business.timezone`.
- **Frontend**: customer-facing and dashboard pages render provider names
  with a "(deactivated)" marker for trashed providers, and customer-facing
  date/time rendering now honours the business timezone via shared
  helpers.
- **Routes**: no changes.
- **Tests**: full Pest suite green on Postgres — **461 passed, 1810
  assertions**. +9 from the R-4B baseline of 452.
- **Decisions**: D-067 appended to
  `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md`.
- **Migrations**: none.
- **i18n**: one new key added to `lang/en.json` —
  `":name (deactivated)"`.

---

## How to Verify Locally

```bash
php artisan migrate:fresh --seed
php artisan test --compact
npm run build
```

Manual smoke:

- Soft-delete a provider (`Provider::find($id)->delete()` via tinker)
  that has historical bookings.
- Visit `/my-bookings` (as the customer), `/bookings/{token}` (public
  cancellation page), `/dashboard` (today's bookings), `/dashboard/bookings`
  (full list), `/dashboard/customers/{id}` (customer detail), and the
  dashboard calendar. In each place the provider name renders with
  "(deactivated)" appended; no 500s.
- On `/my-bookings` and `/bookings/{token}`, temporarily set the business
  timezone to something far from your browser's (e.g. `Asia/Tokyo`) and
  verify the rendered date/time matches the business-local time, not the
  browser-local time.

---

## What the Next Session Needs to Know

R-5 is complete. R-6 is complete. R-4 (both halves) remains complete.

The roadmap-review checklist (`docs/reviews/ROADMAP-REVIEW.md`) moves on
to **R-7 — provider-choice enforcement**. R-7 is intentionally scoped
differently: it touches `PublicBookingController::store` and the
multi-step booking flow (`resources/js/pages/booking/show.tsx`), and its
concern is policy enforcement on POST plus React step-init — not display
correctness. Bundling it into this session would have hurt reviewability.

When adding new booking-related display code, note:

- `$booking->provider` is always a row. Trashed providers are still
  returned. Use `$booking->provider->trashed()` (or equivalent) to gate
  any action-ish UI.
- For NEW work (eligibility, availability, auto-assignment, validation),
  continue to go through `Provider::query()`, `$service->providers()`,
  `$business->providers()`, or `BelongsToCurrentBusiness(Provider::class)`.
  These all keep the default scope and will continue to exclude trashed
  providers.
- Customer-facing display payloads should include `business.timezone` so
  the frontend can format times correctly. Use the helpers in
  `resources/js/lib/datetime-format.ts`; do not call
  `new Date(...).toLocale*()` directly.

---

## Open Questions / Deferred Items

- **R-7 — provider-choice enforcement** — next in the remediation
  roadmap.
- **`docs/ARCHITECTURE-SUMMARY.md` stale terminology** — noted during
  this session: several places still say "collaborator" where the code
  has moved to "provider". Not blocking; clean up in a dedicated docs
  pass.
- **Real-concurrency smoke test** — carried over from R-4B; deterministic
  simulation remains authoritative.
- **Availability-exception race** — carried over from R-4B; out of scope.
- **Parallel test execution** (`paratest`) — carried over from R-4A;
  revisit only if the suite grows painful.
- **Multi-business join flow + business-switcher UI (R-2B)** — carried
  over from earlier sessions; still deferred.
- **Dashboard-level "unstaffed service" warning** — carried over from
  R-1B; still deferred.
