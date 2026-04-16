# Handoff

**Session**: R-7 — Server-side enforcement of `allow_provider_choice`
**Date**: 2026-04-16
**Status**: Complete

---

## What Was Built

R-7 closed the server-side enforcement gap for the `allow_provider_choice`
business setting. Before this session, the setting was respected by the
multi-step React flow but not by any of the four server surfaces that
know about `provider_id` (`PublicBookingController::providers`,
`availableDates`, `slots`, `store`). A crafted POST could target a
specific provider even when the business had disabled provider choice; a
preselected-service URL dropped the customer onto the provider picker
regardless of the setting. The setting was effectively a client-side
suggestion.

D-068 is the new architectural decision recorded in
`docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md`. It establishes the
"gate in controller, silent ignore, empty-list for GET" pattern for
future business-setting enforcements.

### Backend — one helper, four surgical method changes

`app/Http/Controllers/Booking/PublicBookingController.php`:

- New private helper `resolveProviderIfChoiceAllowed(Business, ?int): ?Provider`
  centralises the single expression used across the three availability /
  store methods. Returns `null` when the setting is off or no
  `provider_id` was supplied; otherwise looks the provider up via
  `$business->providers()->where('id', ...)->firstOrFail()`.
- `providers()` — short-circuits with `200 { providers: [] }` before the
  service lookup when the setting is off.
- `availableDates()` — replaced the inline `$request->filled(...)
  ? firstOrFail() : null` with a helper call; submitted `provider_id` is
  silently ignored when the setting is off (falls through to "any
  provider").
- `slots()` — same treatment as `availableDates()`.
- `store()` — replaced the inline block with a helper call and retained
  the service-membership re-check as a second guard. The existing 409
  for "provider exists in the business but is not attached to the
  service" is preserved; when the setting is off the 409 branch is
  unreachable because the helper returns null.

`StorePublicBookingRequest` is unchanged: it still validates that
`provider_id` (when non-null) names a real provider in the business, so
a crafted ID still returns a 422 from the Form Request regardless of the
setting. The policy gate lives in the controller by design — see D-068
for the rationale (three GET endpoints have no Form Request; the Form
Request should not couple to a business-model read).

### Frontend — one-line step-init fix

`resources/js/pages/booking/show.tsx:36` — the `useState` initial value
now honours `business.allow_provider_choice`:

```tsx
const [step, setStep] = useState<BookingStep>(
    preSelectedService
        ? (business.allow_provider_choice ? 'provider' : 'datetime')
        : 'service',
);
```

All other step transitions (`handleServiceSelect`, `goBack`,
`totalSteps`, `stepOrder`) already respected the setting; this was the
one escaped branch.

### New test coverage (+6 tests)

Policy enforcement on the four server surfaces:

- `tests/Feature/Booking/ProvidersApiTest.php` —
  `returns empty list when allow_provider_choice is false`.
- `tests/Feature/Booking/AvailableDatesApiTest.php` —
  `ignores provider_id when allow_provider_choice is false`. Two
  providers; A has Monday availability, B has none. Request with
  `provider_id = B` returns A's dates (proving the ID was ignored).
  Cross-checked against a control request without `provider_id`.
- `tests/Feature/Booking/SlotsApiTest.php` —
  `ignores provider_id when allow_provider_choice is false`. Same shape
  as above, for `slots()`.
- `tests/Feature/Booking/BookingCreationTest.php` —
  `ignores provider_id when allow_provider_choice is false and
  auto-assigns`. Submits `provider_id = B` when only A has Monday
  availability; asserts `booking.provider_id === A.id`.
- `tests/Feature/Booking/BookingCreationTest.php` —
  `honours provider_id when allow_provider_choice is true`. Regression
  pin for the helper refactor: submits `provider_id = B` when both A
  and B have Monday availability and the setting is on; asserts the
  booking lands on B.

Backend Inertia prop contract the React step-init depends on:

- `tests/Feature/Booking/PublicBookingPageTest.php` —
  `preselected service page exposes allow_provider_choice = false when
  setting is off`. Locks the page props contract; the React ternary on
  line 36 is a trivial derivation from these props.

---

## Current Project State

- **Backend**: the four public-booking surfaces that know about
  `provider_id` now gate on `$business->allow_provider_choice`. The
  single helper is the authoritative expression; future additions (a
  new availability endpoint, a new write path) should route through it.
- **Frontend**: the step-init fix means the honest customer flow skips
  the provider step when the setting is off — the 4-step flow
  (service → datetime → details → summary → confirmation) is used when
  both the setting is off and a service was preselected via URL.
- **Routes**: no changes.
- **Tests**: full Pest suite green on Postgres — **467 passed, 1835
  assertions**. +6 from the R-5/R-6 baseline of 461.
- **Decisions**: D-068 appended to
  `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md`.
- **Migrations**: none.
- **i18n**: no new keys. The existing `"Selected provider is not
  available for this service."` copy is reused for the retained 409
  branch.

---

## How to Verify Locally

```bash
php artisan migrate:fresh --seed
php artisan test --compact
npm run build
```

Manual smoke:

1. As admin, visit `/dashboard/settings/booking`, set **Allow customer
   to choose collaborator = OFF**, save.
2. In a fresh incognito window, visit `/{slug}/{service-slug}`
   (preselected-service URL). The flow should open on the date-time
   picker, not the provider picker; the step indicator should show the
   4-step sequence.
3. Complete a booking through the flow. The booking is created, the
   customer receives confirmation, and the provider is auto-assigned
   via `first_available`.
4. Open DevTools Network tab: `GET /booking/{slug}/providers?service_id=...`
   is not fired (the step was skipped). Hit the URL directly in a new
   tab: `{ providers: [] }`.
5. Manually POST to `/booking/{slug}/book` with `provider_id` set to a
   valid provider via DevTools console or curl. The booking is created,
   but the `provider_id` is the auto-assigned one (visible in
   `/dashboard/bookings` or the DB).
6. Toggle the setting back ON. Repeat step 2. The flow now opens on the
   provider picker. Happy path still works end-to-end.

---

## What the Next Session Needs to Know

R-7 is complete. The remediation roadmap moves on to R-8, R-9, or R-10
— the developer picks based on priority. None depends on R-7.

- **R-8 — Calendar hydration + mobile view switcher.** Medium priority.
  Files: `resources/js/components/calendar/week-view.tsx`,
  `current-time-indicator.tsx`, `calendar-header.tsx`, `day-view.tsx`,
  `month-view.tsx`, `calendar-event.tsx`. Priority: fix the confirmed
  hydration warning first; mobile view switcher second.
- **R-9 — Popup embed service prefilter + modal robustness.** Medium
  priority. Files: `public/embed.js`,
  `resources/js/pages/dashboard/settings/embed.tsx`. Priority: canonical
  service-prefilter contract (needs a decision) → per-service popup
  snippet → focus trap / scroll lock / duplicate-overlay guard.
- **R-10** — next in the remediation roadmap; consult
  `docs/reviews/ROADMAP-REVIEW.md` for the latest scope.

When adding new public-booking code that touches `provider_id`:

- Go through `PublicBookingController::resolveProviderIfChoiceAllowed`
  (or D-068's pattern if you're outside this controller).
- Do not re-add the setting check in `StorePublicBookingRequest` — the
  gate is the controller's responsibility; the Form Request stays as a
  pure existence-and-tenant-scope validator.
- Manual booking (`Dashboard\BookingController::store`) is explicitly
  out of scope for this setting per SPEC §7.6 / D-051. Staff always
  pick the provider (or auto-assign) for manual bookings; the customer
  setting does not apply.

---

## Open Questions / Deferred Items

- **R-8 — Calendar hydration + mobile view switcher** — next candidate
  in the remediation roadmap.
- **R-9 — Popup embed service prefilter + modal robustness** — next
  candidate in the remediation roadmap.
- **R-10 and beyond** — consult `docs/reviews/ROADMAP-REVIEW.md`.
- **`docs/ARCHITECTURE-SUMMARY.md` stale terminology** — carried over
  from R-5/R-6: several places still say "collaborator" where the code
  has moved to "provider". Not blocking; clean up in a dedicated docs
  pass.
- **Real-concurrency smoke test** — carried over from R-4B;
  deterministic simulation remains authoritative.
- **Availability-exception race** — carried over from R-4B; out of
  scope.
- **Parallel test execution** (`paratest`) — carried over from R-4A;
  revisit only if the suite grows painful.
- **Multi-business join flow + business-switcher UI (R-2B)** — carried
  over from earlier sessions; still deferred.
- **Dashboard-level "unstaffed service" warning** — carried over from
  R-1B; still deferred.
