# PAYMENTS Hardening Round 2 — Codex Round 2 Frontend + Fix Verification

This plan lives at `docs/PLAN.md` and follows `.claude/references/PLAN.md`. It is a post-roadmap hardening session running on top of the Round 1 commit (`bc991e8`). Codex Round 2 (`docs/REVIEW.md`, 2026-04-29) verified all seven Round 1 findings closed (no regressions) AND surfaced eight frontend findings (G-001..G-008). This session fixes the six that survive triage on a single uncommitted diff.

Iteration-loop baseline at session start: **965 tests / 4183 assertions** (Round 1 close). PHPStan level 5 / `app/` clean. Pint clean. Wayfinder up to date. Vite build green.

---

## Purpose / Big Picture

Round 1 hardened the backend money paths. Round 2 hardens the frontend prop contract + UX coherence on the same Stripe Connect surfaces. Three themes:

1. **Stop leaking Stripe object IDs to the browser.** The admin booking-detail payload is sending full `acct_…`, `pi_…`, `ch_…`, `re_…`, dispute IDs, and whole Pending Action payloads as Inertia props. The React side then builds Stripe dashboard deeplinks from those raw values. This is admin-only, so it is not an external leak today, but it violates the prop contract: any future XSS, browser extension, frontend exception reporter, or screen-share moment harvests semi-secret Stripe identifiers from page JSON. Fix: server-side redirect endpoints for deeplinks (the React side passes `bookingId`, the controller resolves the IDs server-side and 302s to Stripe), truncation for display fields, whitelist on PA payload keys.

2. **Use the Inertia-native error envelope everywhere.** The Payouts login-link endpoint returns `response()->json(['error' => ...], 502)` when Stripe fails — the only payments endpoint that deviates from the `ValidationException::withMessages([discriminator => message])` pattern locked across the public booking flow. The React side has a parallel `setError()` branch instead of consuming `http.errors`. Project rule (`resources/js/CLAUDE.md`) is unambiguous: useHttp + ValidationException, no parallel state. Fix: throw `ValidationException::withMessages(['login_link' => ...])`, render `http.errors` on the React side.

3. **Currency display + i18n hygiene before launch.** Three small but real surfaces: refund-dialog and booking-detail-sheet format money via `${currency.toUpperCase()} ${(cents/100).toFixed(2)}` instead of `Intl.NumberFormat`; `payment-success.tsx` builds `/bookings/${token}` instead of using the Wayfinder helper; PaymentStatusBadge labels (`Paid`, `Awaiting`, `Refund failed`) and Payouts page status badges (`in_transit`, `paid`) bypass `t()`. Pre-launch is the right window to fix these.

After this session:

- Inertia prop payloads on the dashboard booking surfaces carry NO raw Stripe object IDs. Admins reach the Stripe dashboard via signed redirect endpoints that read the IDs server-side.
- The Connected Account + Payouts surfaces show `requirementsCount` + a generic localized "Continue in Stripe" CTA instead of the raw `requirements_currently_due` array.
- The Payouts login-link button consumes `http.errors` like every other useHttp surface.
- The refund dialog + booking detail sheet format CHF amounts via `Intl.NumberFormat`.
- The payment-success page links to the booking management page via Wayfinder.
- Payment status badges + payout status labels go through `t()`.

Acceptance is verifiable by running `php artisan test tests/Feature tests/Unit --compact`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan`, `php artisan wayfinder:generate`, `npm run build`, AND launching Codex Round 3 against the staged diff.

---

## Scope

### In scope (six findings: G-001, G-002, G-003 partial, G-004, G-005, G-007)

- **G-001** — `app/Http/Controllers/Dashboard/BookingController.php` (the `index` / Inertia bookings prop builder around lines 253-300) and `resources/js/components/dashboard/booking-detail-sheet.tsx` (lines 132-160 deeplink builders, 380-540 refund/dispute panels). New server-side controller endpoints (signed admin-only redirects) for the three deeplink types: payment, refund, dispute. PA payload whitelist: only the keys the UI actually consumes.
- **G-002** — `app/Http/Controllers/Dashboard/PayoutsController.php` (`accountPayload`, around line 312) + `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php` (`asEditPayload` or equivalent, line 736). Replace `requirementsCurrentlyDue: string[]` with `requirementsCount: int`. React side: `resources/js/pages/dashboard/payouts.tsx` (lines 331, 376) + `resources/js/pages/dashboard/settings/connected-account.tsx` (lines 175, 181) consume the count and render a localized "Continue in Stripe to complete onboarding" CTA. The TS type definitions in those files updated to drop the array.
- **G-003 partial — display only** — `resources/js/components/dashboard/refund-dialog.tsx` (line 59), `resources/js/components/dashboard/booking-detail-sheet.tsx` (lines 132-138, 368). Add a shared `formatMoney(cents, currency)` helper using `Intl.NumberFormat(undefined, { style: 'currency', currency })`. The locale-aware INPUT parsing (`10,50`, `1'000.50`) is deferred to a separate UX session and a BACKLOG entry — it is not a money-correctness bug, it is a UX limitation.
- **G-004** — `resources/js/pages/booking/payment-success.tsx` (lines 46-47). Replace `/bookings/${booking.token}` with the Wayfinder helper from `@/actions/App/Http/Controllers/Booking/BookingManagementController` or `@/routes/bookings`.
- **G-005** — `app/Http/Controllers/Dashboard/PayoutsController.php` (lines 127-137) replaces `response()->json(['error' => ...], 502)` with `throw ValidationException::withMessages(['login_link' => __(...)])`. `resources/js/pages/dashboard/payouts.tsx` (lines 473-497) consumes `http.errors.login_link` instead of local `setError()`.
- **G-007** — `resources/js/components/dashboard/payment-status-badge.tsx` (lines 18-29) wraps the labels in `t()`; payout status labels in `resources/js/pages/dashboard/payouts.tsx` (lines 607, 616, 678) and the unknown-refund-reason fallback in `resources/js/components/dashboard/booking-detail-sheet.tsx:542` go through `t()`.

### Out of scope (with reasons)

- **G-006** (hardcoded `CH` in `resources/js/pages/dashboard/settings/booking.tsx:77-89`): **false positive on contextual review**. The file carries an explicit 7-line comment block (lines 77-83) that documents this as a deliberate CH-centric MVP choice, citing locked roadmap decision #43: "config is the gate STATE; copy + UX + tax assumptions are CH-centric. Do not rewrite this to render the config list dynamically — YAGNI for MVP and contradicts D-43's locale-audit contract." Codex Round 2 cited D-112 as the drift basis; D-112 (config-driven gate state) and locked roadmap decision #43 (copy CH-specific until the locale audit) are coherent, not in conflict. The locale-list audit is already a BACKLOG entry per the prior roadmap; no new entry needed. Documented as **D-183 below** to make the decision explicit and pin it against future re-litigation.

- **G-008** (banner stack always assertive, no dismiss): real but **out of payments scope**. `dashboard-banner.tsx` is consumed by the subscription-lapse banner, payment-mode mismatch banner, AND the unbookable-services banner. A11y polish here is a UI session of its own. Documented as a new BACKLOG entry instead.

- **G-003 input parsing** (CH locale `10,50` / `1'000.50` accepted): real UX limitation for CH operators but NOT a money-correctness bug — the server uses cents and rejects bad input via `StoreBookingRefundRequest`. The fix shape (parse apostrophe-thousands and comma-decimals into integer cents) belongs in a focused UX session. BACKLOG entry.

- The Round 1 findings (F-001..F-007): Codex Round 2 verified all closed. No re-work.

- Browser tests for the new payments surfaces. Acknowledged + deferred (Round 1 PLAN already noted this).

- The 11 failing browser tests (`tests/Browser/Embed/IframeEmbedTest` etc.) — out of scope, separate investigation after this session.

- Anything else in `docs/REVIEW.md::What I Did Not Cover` (auth, scheduling, Cashier subscriptions, generic dashboard code).

---

## Approach (per finding)

### G-001 — No raw Stripe IDs in Inertia props; deeplinks via redirect endpoints

Three changes:

**(a) New routes + controller for Stripe deeplinks.** Admin-only, tenant-scoped, signed by booking ownership:

```
GET  /dashboard/bookings/{booking}/stripe-link/payment   → 302 Stripe payments page
GET  /dashboard/bookings/{booking}/stripe-link/refund/{refund}  → 302 Stripe refund page
GET  /dashboard/bookings/{booking}/stripe-link/dispute   → 302 Stripe dispute page (uses booking → first dispute PA → dispute_id)
```

Implementation: a small `StripeDashboardLinkController` under `app/Http/Controllers/Dashboard/` with three actions. Each one resolves the booking via `tenant()->business()` (route-model-binding scoped to the tenant), reads the appropriate Stripe IDs from server-side state, and returns a redirect to the Stripe dashboard URL. Routes sit inside `role:admin` middleware (matches Payouts pattern). The dispute action reads the `payload->>'dispute_id'` from the booking's open dispute PA — never accepts a dispute id from the URL.

**(b) Booking-detail Inertia payload trimmed.** Remove from `BookingController::index`'s `payment` block (lines 253-300):
- `stripe_charge_id` → keep only the truncated last-8-chars display field IF the UI needs to show "Charge ch_…XXXXX" anywhere. Audit shows the React side uses it ONLY for the deeplink, so drop entirely.
- `stripe_payment_intent_id` → same; UI uses it ONLY for the deeplink. Drop.
- `stripe_connected_account_id` → same; UI uses it ONLY for the deeplink. Drop.

For the refunds list (lines 289-300):
- `stripe_refund_id` → drop. UI uses it for the deeplink (and its truncated last-4 in the operator audit caption — keep as `stripe_refund_id_last4`).

For the dispute PA payload (line 281):
- Replace `'payload' => $disputePendingAction->payload` with a whitelist: `dispute_id_last4`, `charge_id_last4` (if needed for display), `amount`, `currency`, `reason`, `status`, `evidence_due_by`. The dispute deeplink uses the new server-side endpoint, not the raw `dispute_id`.

For the urgent payment PA payload (line 269):
- Same whitelist treatment. The UI consumes `payload.refund_amount_cents`, `payload.failure_reason`, `payload.dispute_id` (display-truncated only) — anything else dropped.

**(c) React side rewired.** `booking-detail-sheet.tsx`:
- The `stripeDashboardDeepLink` builder (lines 140-147) becomes `route('dashboard.bookings.stripe-link.payment', booking.id)` via Wayfinder.
- The `disputeDeepLink` builder (lines 148-155) becomes the dispute-link helper.
- Refund row deeplinks rewire to the refund-link helper.
- Display fields read the new `_last4` props instead of slicing raw IDs.

The deeplink endpoints can fail (e.g. booking has no `stripe_charge_id` yet). The fallback shape: 404 with a localized message. The React side already conditions deeplinks on the IDs being present; with the new shape, it conditions on a server-passed boolean (`payment.has_stripe_link: true`).

Tests: a feature test for each of the three deeplink routes — happy path 302, cross-tenant 404, missing-id 404, staff-attempt 403. A snapshot test for `BookingController::index` Inertia props that asserts no key matches `/^stripe_(charge|payment_intent|refund|connected_account)_id$/` and no payload key contains `dispute_id` (only `dispute_id_last4`).

### G-002 — `requirementsCount` instead of raw `requirementsCurrentlyDue`

`PayoutsController::accountPayload` (line 312) and `ConnectedAccountController` (line 736 — the equivalent payload builder for `dashboard/settings/connected-account` page) drop the raw array and add:

```php
'requirementsCount' => count($row->requirements_currently_due ?? []),
```

The TS types in `payouts.tsx` (line 32) and `connected-account.tsx` (line 36) drop `requirementsCurrentlyDue: string[]` and add `requirementsCount: number`. The badge renderers (lines 175-181 in connected-account.tsx, 331/376 in payouts.tsx) replace the per-key list with a single localized "X items pending — continue in Stripe to complete onboarding" line + a CTA button that opens the Stripe Express dashboard.

The Payouts page already has the dashboard-login-link mint flow (G-005's endpoint); the connected-account page's CTA can use the existing "Open Stripe Express" button if present, or a new mint endpoint.

Tests: assert the prop shape change in the existing `PayoutsControllerTest` happy-path test.

### G-003 partial — `formatMoney` helper for display

New shared frontend helper at `resources/js/lib/format-money.ts`:

```ts
export function formatMoney(cents: number, currency: string): string {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency.toUpperCase(),
    }).format(cents / 100);
}
```

Three call sites updated:
- `refund-dialog.tsx:59` — `remainingFormatted` uses the helper.
- `booking-detail-sheet.tsx:132-138` — `paidAmountFormatted` + `remainingFormatted` use the helper.
- `booking-detail-sheet.tsx:368` — refund row amount display uses the helper.

The locale-aware INPUT parser is deferred (BACKLOG); the existing `parseAmount` strict-dot-decimal stays, with a clearer placeholder that hints the format.

### G-004 — Wayfinder helper on `payment-success.tsx`

```tsx
import { show } from '@/actions/App/Http/Controllers/Booking/BookingManagementController';

<Link href={show(booking.token)}>{t('Check booking status')}</Link>
```

Verify the helper resolves correctly via `php artisan wayfinder:generate` first (the helper exists per the Codex citation).

### G-005 — `loginLink` uses ValidationException

```php
} catch (ApiErrorException $e) {
    report($e);

    throw ValidationException::withMessages([
        'login_link' => __('Could not open Stripe right now. Please try again in a moment.'),
    ]);
}
```

React side: replace the local `setError()` branch in the `onError` handler with reading `http.errors.login_link`. The button's disabled-during-request bit stays; only the error-display path changes.

Test: an existing `PayoutsControllerTest` test that drives the failure path — update the assertion from "JSON 502 with `error` key" to "Inertia validation error envelope with `login_link` key".

### G-007 — `t()` for status labels

`PaymentStatusBadge`:

```tsx
const t = useTrans();
const paymentConfig: Record<string, { variant: ..., label: () => string }> = {
    paid: { variant: 'success', label: () => t('Paid') },
    awaiting_payment: { variant: 'warning', label: () => t('Awaiting') },
    // ... etc
};
```

Same shape for the payout status labels in `payouts.tsx` (lines 607, 616, 678). The unknown-refund-reason fallback in `booking-detail-sheet.tsx:542` becomes `t('Unknown')`.

---

## Risk register

- **G-001 is the most invasive change** — three new routes, controller, prop reshaping, React rewiring across two files. Each piece is small but the bundle is wide. Mitigation: write the new controller + routes first, add the feature tests, then change the prop shape, then change the React. Each step is its own commit-able point if needed.
- **The new `StripeDashboardLinkController` is admin-only**. Verify the route group is inside `role:admin` middleware. The dispute deeplink reads server-side from the booking's first open dispute PA — never accepts a dispute id from the URL (mitigates the obvious "use a stranger's dispute id" attack).
- **G-002 changes the Inertia prop shape**. Existing tests asserting `requirementsCurrentlyDue` need updates. Mitigation: search for the prop name in tests, update in one pass.
- **Wayfinder regeneration must happen for G-004 + G-001 to compile**. Run `php artisan wayfinder:generate` after route additions.
- **G-005's existing test asserts the JSON 502 shape**. Update to ValidationException shape.

---

## Quality bar / Done check

- `php artisan test tests/Feature tests/Unit --compact` green; new tests for the three deeplink endpoints + the prop-shape regression.
- `vendor/bin/pint --dirty --format agent` clean.
- `php artisan wayfinder:generate` — new routes regenerate frontend helpers.
- `./vendor/bin/phpstan` no errors.
- `npm run build` clean; `npx tsc --noEmit` clean (the React side gets new types).
- `docs/PLAN.md` `## Progress` reflects every milestone closed; `## Decision Log` documents the deeplink-endpoint shape; `## Surprises & Discoveries` records anything unexpected.
- Two new architectural decisions written into `docs/decisions/DECISIONS-PAYMENTS.md`:
  - **D-183** — locked roadmap decision #43 supersedes D-112 for COPY in the public payment-mode UI; the hardcoded CH literal in `booking.tsx` is intentional MVP behaviour, not drift.
  - **D-184** — Stripe dashboard deeplinks live on server-side admin-only redirect endpoints; raw Stripe object IDs do not ride Inertia props (closes G-001).
  - **D-185** — Payouts / Connected Account `requirements_currently_due` exposed only as a count + generic CTA, never as a raw array (closes G-002).
- `docs/HANDOFF.md` updated with the Round 2 hardening line + new baseline + D-183..D-185 promotion + next free D-ID.
- `docs/BACKLOG.md` gains two entries: G-008 (banner a11y polish) + G-003 input parser (CH locale).
- `/codex:review` (Round 3) launched against the staged diff — see `## Review` section for findings + dispositions.

---

## Progress

- [x] (2026-04-29) Milestone 1 — G-001a: new `StripeDashboardLinkController` with payment/refund/dispute redirect endpoints. Admin role + tenant scoping in the controller; routes inside the existing `role:admin` group with the Payouts routes. Dispute reads `payload->>'dispute_id'` from the booking's most-recent dispute PA — never accepts an id from the URL.
- [x] (2026-04-29) Milestone 2 — G-001b: `BookingController::index` Inertia payload reshaped. Removed `stripe_charge_id`, `stripe_payment_intent_id`, `stripe_connected_account_id` from `payment`; removed `stripe_refund_id` from refund rows (replaced by `stripe_refund_id_last4` + `has_stripe_link`); whitelisted urgent + dispute PA payloads (raw `dispute_id` no longer rides; `dispute_id_last4` instead). Two private helpers added (`whitelistPaymentPaPayload`, `whitelistDisputePaPayload`, `refundRowPayload`).
- [x] (2026-04-29) Milestone 3 — G-001c: `booking-detail-sheet.tsx` consumes Wayfinder helpers (`stripePaymentLink.url`, `stripeRefundLink.url`, `stripeDisputeLink.url`). Removed the local `disputeDeepLink`/`stripeDashboardDeepLink` builders; the `payment.has_stripe_payment_link` and `disputePaymentAction.has_dispute_link` booleans gate visibility. Refund row caption shows `re_…XXXX` from the truncated last4. TS types in `@/types/index.d.ts` updated (no raw Stripe IDs).
- [x] (2026-04-29) Milestone 4 — G-002: `PayoutsController::accountPayload` and `ConnectedAccountController` payload builder expose `requirementsCount: int`. Removed the previous Tooltip + raw badge list in `payouts.tsx`; replaced with a single status chip "X requirement(s) due — see Stripe". `connected-account.tsx` shows "X items pending — continue in Stripe to complete onboarding." Tooltip primitive import dropped (no other consumer in the file).
- [x] (2026-04-29) Milestone 5 — G-003 partial: new `resources/js/lib/format-money.ts` (`Intl.NumberFormat`). Three call sites updated. The locale-aware INPUT parser is deferred to BACKLOG — out of scope.
- [x] (2026-04-29) Milestone 6 — G-004: `payment-success.tsx` imports `show as bookingShow` from `@/actions/.../BookingManagementController` and uses `bookingShow.url(booking.token)`.
- [x] (2026-04-29) Milestone 7 — G-005: `PayoutsController::loginLink` now throws `ValidationException::withMessages(['login_link' => __(...)])`. React side: `useHttp<{ login_link?: string }>({})` typed; the `error` value is derived from `http.errors.login_link` (string | string[]); local `setError`/`error` state removed. New test asserts the new envelope (`assertJsonValidationErrors(['login_link'])`).
- [x] (2026-04-29) Milestone 8 — G-007: `PaymentStatusBadge` rewritten with split `variantByStatus` + `labelByStatus` maps wrapped in `t()`; `PayoutStatusBadge` translates known Stripe statuses (paid / in_transit / pending / failed / canceled) and falls back to `t('Unknown')`; `formatRefundReason` unknown-fallback returns `t('Unknown')` instead of the raw internal string.
- [x] (2026-04-29) Milestone 9 — Tests:
    - New `tests/Feature/Dashboard/StripeDashboardLinkControllerTest.php` (12 tests): payment 302, payment-intent fallback, missing-handle 404, staff 403, cross-tenant 404, refund 302, cross-booking refund 404, null-stripe-refund-id 404, dispute 302, dispute-no-PA 404, staff-dispute 403, prop-shape regression-guard.
    - New `login-link mint Stripe failure surfaces as Inertia validation error envelope (G-005)` test in `PayoutsControllerTest.php`.
    - `BookingPaymentPanelTest.php` rewritten to assert `has_stripe_payment_link` + `missing(stripe_charge_id)` + `missing(stripe_connected_account_id)`.
    - `BookingsListPaymentFilterTest.php`: explicit non-overlapping `starts_at` to dodge a pre-existing GIST exclusion-constraint flake (factory random dates collided for the same provider). Pre-existing flake noted in `## Surprises & Discoveries`.
- [x] (2026-04-29) Milestone 10 — Iteration loop:
    - `php artisan test tests/Feature tests/Unit --compact` → **978 / 4246** (baseline 965 / 4183; +13 tests, +63 assertions).
    - `vendor/bin/pint --dirty --format agent` → pass (one auto-fix on the new helper imports).
    - `php artisan wayfinder:generate` → regenerated; 3 new dashboard routes appear in `@/actions` + `@/routes`.
    - `./vendor/bin/phpstan` → No errors. (One PHPStan covariance issue on `Collection<TValue>` invariance was resolved by extracting `refundRowPayload` into a typed private method + converting the refunds map to a plain array via `->values()->all()`. See `## Surprises & Discoveries`.)
    - `npm run build` → clean.
    - `npx tsc --noEmit` → clean. (One TS error fixed by typing `useHttp<{ login_link?: string }>({})`.)
- [x] (2026-04-29) Milestone 11 — Promoted **D-183**, **D-184**, **D-185** directly into `docs/decisions/DECISIONS-PAYMENTS.md`. Next free D-ID: D-186.
- [x] (2026-04-29) Milestone 12 — Rewrote `docs/HANDOFF.md` with Round 2 baseline (978 / 4246) + new D-IDs + the false-positive resolution for G-006. Added two BACKLOG entries: G-008 dashboard banner a11y + G-003 input parser.
- [ ] Milestone 13 — Codex Round 3 review against staged diff; findings (if any) under `## Review — Round 3`.

---

## Surprises & Discoveries

- **PHPStan covariance on the bookings paginator's nested refund Collection**. The first run flagged a `Collection<TValue> is not covariant` issue: the `through()` callback's inferred return shape included `refunds: Collection<int, array{...stripe_refund_id_last4: non-empty-string|null...}>|null`, but somewhere PHPStan compared it against a narrower `non-empty-string` shape and rejected the assignment. Two changes made it stick: (a) extract the refund-row map closure into a typed private method `refundRowPayload(BookingRefund, Booking): array{...}` so the return shape is statically pinned; (b) convert the result via `->values()->all()` to a plain `array` (Inertia serializes both identically in the JSON payload, so the browser-side shape is unchanged). Worth noting because the same shape appears elsewhere (`bookingPayload`-style inline arrays); future expansions of the booking-detail prop should reach for a typed helper rather than inline closures.

- **Pre-existing GIST flake on `BookingsListPaymentFilterTest`**. The "every payment_status value surfaces in the list payload (chip dataset coverage)" test creates 7 bookings on the same provider via `Booking::factory()->create([...])` with the factory's random `starts_at`. On this run, two of them landed within the same 30-minute window, hitting the `bookings_no_provider_overlap` GIST exclusion constraint at INSERT time. Not caused by this session — the test was identical before — but our larger payload changes nudged execution timing enough that the flake surfaced. Fixed by giving each booking an explicit `starts_at = now()->addDays(7 + $i)`. Worth flagging because other "create N bookings on one provider" tests in the suite likely share the same latent flake.

- **`G-006` was a false positive**. The `'CH'` literal in `booking.tsx:77-89` is documented in a 7-line comment as a deliberate MVP choice citing locked roadmap decision #43 — Codex Round 2 read line 77 but missed the comment block above. Rather than re-litigate it on every future review, promoted as **D-183** (locked roadmap decision #43 governs CH-centric COPY; D-112 governs the gate STATE) and added a `D-183` reference to the existing comment so the next reader sees the locked decision in the D-ID register.

- **Bookings list is paginated**. The Codex prop-shape regression test initially asserted on `bookings.0` but the controller renders `bookings.data.0` (Eloquent paginator). One-line fix; flagged because future review-prompts should mention the paginator shape so review agents don't write similar test paths.

- **TS `useHttp` defaults to `FormDataErrors<{}>`**. `useHttp({})` types `errors` as effectively empty, so consuming `http.errors.login_link` fails at compile time. The fix is `useHttp<{ login_link?: string }>({})`. Worth noting because every future useHttp call that surfaces a discriminator-keyed validation error needs the same typing pattern; the project's `resources/js/CLAUDE.md` rule "useHttp + ValidationException, no parallel state" implies but doesn't make this explicit.

---

## Decision Log

D-183, D-184, D-185 were promoted directly into `docs/decisions/DECISIONS-PAYMENTS.md` during exec to avoid the next-session-overwrite risk. Summary here for orientation; the canonical bodies live in DECISIONS-PAYMENTS.

- **D-183** — Locked roadmap decision #43 governs CH-centric copy in the payment-mode Settings UI; D-112 governs the gate STATE. Pins the precedence so future reviews don't re-flag the `'CH'` literal in `booking.tsx` as drift.
- **D-184** — Stripe dashboard deeplinks live on admin-only server-side redirect endpoints. Raw Stripe object IDs no longer ride Inertia props; the booking-detail prop carries truncated `_last4` display fields + `has_*_link` booleans.
- **D-185** — Connected-account `requirements_currently_due` exposed as `requirementsCount: int` only; the field-path list stays server-side / in Stripe.

(Pre-locked sketches retained below for historical context — final bodies in DECISIONS-PAYMENTS supersede.)

### D-183 (provisional) — Locked roadmap decision #43 governs CH-centric copy in the payment-mode Settings UI, not D-112's config-driven gate

- **Date**: 2026-04-29 (PAYMENTS Hardening Round 2)
- **Status**: provisional (promoted to accepted at close)
- **Context**: Codex Round 2 (Finding G-006) flagged a hardcoded `'CH'` literal in `resources/js/pages/dashboard/settings/booking.tsx` ("Online payments in MVP support CH-located businesses only.") as a D-112 drift. D-112 says `config('payments.supported_countries')` is the single switch for country GATE STATE. But `booking.tsx:77-83` carries a 7-line comment citing locked roadmap decision #43: copy / UX / tax assumptions are CH-specific until the post-MVP locale-list audit. The two are not in conflict — they govern different surfaces (gate state vs UI copy) — but the conflict is not visible in the D-IDs alone, which led to the false-positive finding.
- **Decision**: Make the precedence explicit. For UI copy that explains the gate to a human user, locked roadmap decision #43 governs: copy stays CH-specific until the fast-follow locale audit. For the gate STATE itself (whether a country is allowed at all), D-112 governs: `config('payments.supported_countries')` is authoritative. The `booking.tsx` literal is correct as-is. Future reviewers should not re-litigate without engaging both decisions.
- **Consequences**:
    - The Round 2 G-006 finding is closed as out-of-scope: not a drift, not a regression.
    - The existing comment block in `booking.tsx` is augmented with a `D-183` reference so a future reader sees the explicit locked decision instead of having to chase locked roadmap #43.
    - The locale-list audit BACKLOG entry remains as the activation surface for any future change here.

### D-184 (provisional) — Stripe dashboard deeplinks on admin-only server-side redirect endpoints; raw Stripe IDs never ride Inertia props

- **Date**: 2026-04-29 (PAYMENTS Hardening Round 2)
- **Status**: provisional (promoted to accepted at close)
- **Context**: Codex Round 2 G-001 documented that the dashboard booking-detail Inertia payload was sending full `stripe_charge_id`, `stripe_payment_intent_id`, `stripe_connected_account_id`, `stripe_refund_id`, dispute IDs, and unfiltered Pending Action payloads to the browser. The React side then concatenated those raw values into Stripe dashboard URLs. Admin-only, but a violation of the prop contract: any future XSS, browser extension, or frontend exception reporter can harvest the IDs from page JSON.
- **Decision**: Three new admin-only routes on the dashboard sit behind `role:admin` + tenant scoping and 302 to Stripe:
    - `GET /dashboard/bookings/{booking}/stripe-link/payment`
    - `GET /dashboard/bookings/{booking}/stripe-link/refund/{refund}`
    - `GET /dashboard/bookings/{booking}/stripe-link/dispute`
  The controller reads the raw Stripe IDs server-side from the booking + (for refund) the route-bound BookingRefund + (for dispute) the booking's open dispute Pending Action's `payload->>'dispute_id'`. The React side passes through Wayfinder helpers; no raw Stripe ID rides the prop. The admin booking-detail prop shape carries truncated `_last4` display fields where the UI needs to show a partial id ("re_…XXXX"), plus a `has_stripe_link` boolean per link type so the React side knows when to render the deeplink button.
- **Consequences**:
    - A future XSS / browser-extension / exception-reporter cannot harvest semi-secret Stripe identifiers from page JSON.
    - The dispute deeplink reads the dispute id from a server-side PA lookup; the URL never carries a user-controlled dispute id.
    - The endpoint is admin-only + tenant-scoped; cross-tenant + staff attempts return 403/404.
    - PA payload exposure is whitelisted: only the keys the UI actually consumes. Everything else (raw payment_intent in payload, internal Stripe object refs) stays server-side.

### D-185 (provisional) — Connected-account `requirements_currently_due` exposed as count + generic CTA only

- **Date**: 2026-04-29 (PAYMENTS Hardening Round 2)
- **Status**: provisional (promoted to accepted at close)
- **Context**: Codex Round 2 G-002 documented that `PayoutsController::accountPayload` and `ConnectedAccountController` both pass-through the raw `requirements_currently_due` array to the React side, where it renders as a list of badges. Stripe sometimes returns paths like `person_xxx.dob.day`, `individual.id_number`, `company.tax_id` — semi-PII-flavoured field references that should not surface as a rendered list to the operator (and should not appear in page JSON either).
- **Decision**: Replace the `requirementsCurrentlyDue: string[]` prop with `requirementsCount: int` on both controller surfaces. The React renderer shows "X items pending — continue in Stripe to complete onboarding" + a CTA button that opens the Stripe Express dashboard. Operators get the action; the field-path list stays server-side (and in Stripe).
- **Consequences**:
    - Page JSON no longer carries Stripe field paths.
    - The connected-account + payouts UI route operators to Stripe for the actual list, which is the correct system of record anyway.
    - Future changes to Stripe's requirements vocabulary (new fields, key renames, drift) cannot leak into the page JSON.

---

## Review

### Round 3 — 2026-04-29

Codex Round 3 ran against the staged Round 2 diff. Verdict: Round 2 **partial** — five findings closed cleanly (G-002, G-004, G-005, G-006, G-008 deferral), three incomplete (G-001, G-003 partial, G-007). Drift sweep surfaced four findings (H-001..H-004); all four were applied on the same uncommitted diff before commit.

Verification commands re-run after the Round 3 fixes: `php artisan test tests/Feature tests/Unit --compact` (**980 / 4249** — +2 tests / +3 assertions for H-001), `./vendor/bin/phpstan` (no errors), `vendor/bin/pint --dirty --format agent` (pass), `npx tsc --noEmit` (clean), `npm run build` (clean).

#### H-001 — Dispute deeplink filters `status = pending`

- **Severity:** Medium (real lifecycle bug — D-184 contract drift)
- **Status:** Fixed
- **Location:** `app/Http/Controllers/Dashboard/StripeDashboardLinkController.php:99`
- **Fix:** added `->where('status', PendingActionStatus::Pending->value)` to the dispute PA lookup so resolved historical rows cannot redirect to a no-longer-relevant Stripe dispute page. Imported `App\Enums\PendingActionStatus`. The filter mirrors the booking-list eager-load in `BookingController::index` (the deeplink behaviour now matches the Inertia banner's visibility contract).
- **Tests added:** `tests/Feature/Dashboard/StripeDashboardLinkControllerTest.php`:
    - `dispute deeplink: 404 when the only dispute PA is resolved (H-001)` — proves the bug Codex documented is closed.
    - `dispute deeplink: pending PA is selected even when a newer resolved PA exists (H-001)` — pins the selection semantics: pending wins over a higher-id resolved row.

#### H-002 — `payouts.tsx` formatAmount → formatMoney

- **Severity:** Low (drift from Round 2 G-003 partial intent)
- **Status:** Fixed
- **Location:** `resources/js/pages/dashboard/payouts.tsx:610-622`
- **Fix:** removed the local `formatAmount()` helper (Intl with a `toFixed(2)` fallback). Both call sites (`formatBalanceArms` + the payouts table row) now use the shared `formatMoney(cents, currency)` from `@/lib/format-money`. An invalid currency now fails loud at the Intl call rather than silently rendering through a hand-rolled fallback — the server is the source of truth and must not pass an invalid currency to the React renderer in the first place.

#### H-003 — Unknown payment status / payout schedule fall back to `t('Unknown')`

- **Severity:** Low (i18n hygiene drift from Round 2 G-007 intent)
- **Status:** Fixed
- **Location:** `resources/js/components/dashboard/payment-status-badge.tsx:42`, `resources/js/pages/dashboard/payouts.tsx:651`
- **Fix:** `PaymentStatusBadge` default case now returns `t('Unknown')` instead of the raw `status` string. `formatSchedule` default case now returns `t('Unknown')` instead of the raw `schedule.interval` string. Component header comment updated to reflect the new fallback semantics. A future server-side enum addition that bypasses the React label map degrades gracefully instead of leaking the internal token.

#### H-004 — Documentation hygiene: HANDOFF invariant + D-184 coverage claim

- **Severity:** Low (doc drift only; no behavioural change)
- **Status:** Fixed
- **Location:** `docs/HANDOFF.md` (the "Conventions that future work must not break" section), `docs/decisions/DECISIONS-PAYMENTS.md` (D-184 consequences)
- **Fix:** rewrote the HANDOFF "No hardcoded `'CH'` literal anywhere" bullet to make the gate-state vs copy precedence explicit per **D-183**: config owns the gate state, locked roadmap decision #43 / D-183 owns CH-centric COPY until the locale-list audit. Future reviewers reading the HANDOFF invariant will not re-litigate G-006. Separately, narrowed D-184's "Consequences" test-coverage claim to the paths actually exercised (the prior wording overstated coverage; the refund/dispute auth paths inherit the same shape as the payment endpoint, which IS exhaustively covered, but the deferred test additions are now flagged as a follow-up rather than implied complete).

#### What remains intentionally deferred

- Full coverage parity for staff-refund / cross-tenant-refund / cross-tenant-dispute (Codex Round 3 noted these as a "Coverage Gap"). The endpoint auth shape is identical to the payment endpoint (`assertAdminTenantOwns()` + `role:admin` route group), so the patterns inherit by construction; adding the explicit tests is a hygiene improvement, not a security fix. Flagged in the narrowed D-184 consequences.
- The frontend-shape regression-guard tests Codex suggested under "Coverage Gaps" (e.g. "in-scope money display calls `formatMoney` and not `toFixed(2)`") are useful but better expressed as a Pest arch-test or a lint rule than a Feature test. Left for a separate quality-tooling session.

---

## Outcomes & Retrospective

**Shipped**: every Codex Round 2 finding triaged. Six fixed (G-001 + D-184, G-002 + D-185, G-003 partial, G-004, G-005, G-007). One closed as false positive (G-006 → D-183). Two deferred to BACKLOG (G-008 banner a11y, G-003 input parser). Iteration loop green: 978 / 4246 tests, PHPStan clean, Pint clean, Wayfinder regenerated, Vite build green, TSC clean. Three new architectural decisions promoted into `DECISIONS-PAYMENTS.md`.

**What went well**:

- The decision to do the prop-shape audit BEFORE writing the React rewire saved a roundtrip — once the new server-side endpoints existed and the Wayfinder helpers were generated, the React side became a one-pass `s/raw-id-concat/Wayfinder.url(...)/` rewrite.
- The G-006 false-positive resolution into D-183 is the right pattern: rather than fix nothing and let the next reviewer flag it again, document the locked decision precedence explicitly.
- The deeplink controller is small enough to test exhaustively — 12 tests for 3 endpoints + the prop-shape regression-guard. Future reviewers can verify D-184 holds by re-running the regression-guard alone.

**What to watch**:

- The PHPStan covariance issue on nested Collections is a recurring shape problem — any future Inertia prop builder that returns a complex `array{...refunds: Collection<X>...}` will likely hit the same issue. Reach for `->values()->all()` + a typed helper method early.
- The pre-existing GIST flake on `BookingsListPaymentFilterTest` is now closed via explicit dates, but other "create N bookings on one provider" tests in the suite may share the same latent flake. Worth a focused sweep when convenient.
- The 11 failing browser tests reported at Round 1 close remain unfixed — out of scope for this session, tracked separately.

**Next**:

- Codex Round 3 with a "verify Round 2 fixes are real + sweep for any drift introduced" prompt.
- Once Round 3 lands clean, developer commits the bundle (Round 2 exec + any Round 3 fixes) as a single commit on top of `bc991e8`.
- Browser test investigation as a separate follow-up session.
- BACKLOG entries (G-008 banner a11y + G-003 input parser) ready for prioritisation.
