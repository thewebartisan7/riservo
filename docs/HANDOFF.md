# Handoff

**State (2026-04-24):** PAYMENTS Session 4 (Payout Surface + Connected Account Health) shipped. The active `docs/ROADMAP.md` (PAYMENTS) has one session left: 5.

**Branch**: main.
**Feature+Unit suite**: 954 passed / 4079 assertions (baseline 938 / 3918 at Session 3 close; Session 4 added 16 tests / 161 assertions across exec + one self-review round).
**Full suite**: 2+ minutes because of `tests/Browser`. Developer pre-push check.
**Tooling**: Pint clean. PHPStan (level 5, `app/` only) clean. Vite build clean (main app chunk ~583 kB; pre-existing >500 kB warning unaffected). Wayfinder regenerated.
**Codex review**: rate-limited at session close — replaced with a self-review (1 round, 3 findings, all applied). Findings: P1 TS error on `<Tooltip delay={150}>` (Base UI's `delay` prop lives on `TooltipProvider`, not Root); P2 TS error on `formatSchedule(payouts?.schedule, t)` passing `T | null | undefined` to a `T | null` signature; P2 weak cross-tenant test (only proved B sees B, did not exercise the session-pinning attack admin-of-A → B). All three fixed on the same uncommitted diff before commit.

---

## What shipped

MVP (Sessions 1–11) + MVP Completion (MVPC-1..5): data layer, scheduling engine, frontend foundation, auth, onboarding, public booking, dashboard, settings, email notifications, calendar view, Google OAuth, bidirectional Google Calendar sync, Cashier subscription billing, provider self-service settings, advanced calendar interactions. E2E-0..6 also shipped.

**PAYMENTS Session 1 (2026-04-23, commit `b520250`):** Stripe Connect Express onboarding + connected account model + settings page + Connect webhook with `account.*` + dispute stubs. Pending Actions table generalised (D-113).

**PAYMENTS Session 2a (2026-04-23, commit `36879f0` + CI fix `5bdbe4e`):** happy-path online payment — booking creation in `pending + awaiting_payment`, Stripe Checkout minting, webhook `checkout.session.completed` + `.async_payment_succeeded` promotion via the shared `CheckoutPromoter` service (D-151), success-page synchronous retrieve (locked decision #32), D-158 account pin, D-156 fail-closed on mismatch, D-157 + D-159 paid-cancel guards across all cancel paths.

**PAYMENTS Session 2b (2026-04-23, commit `8fd5e53`):** failure branching — `checkout.session.expired`, `checkout.session.async_payment_failed`, `payment_intent.succeeded` late-refund path, expiry reaper with defense-in-depth (pre-flight retrieve + grace buffer + late-webhook refund), admin booking-detail payment panel, unpaid badge + filters. `booking_refunds` table + `RefundService` skeleton (one reason — `cancelled-after-payment`) + `mockRefundCreate` / `mockRefundCreateFails`.

**PAYMENTS Session 3 (2026-04-24, commit `737f2e5`):** RefundService expanded to five reasons + admin-manual variant + 4-arg signature; cancel paths rewired to dispatch refunds inline; admin-manual refund UI; dispute webhook handling (`charge.dispute.*` create/update/close + admin emails + dispute PA); refund-settlement webhooks (`charge.refunded`, `charge.refund.updated`, `refund.updated`); admin Payment & refunds panel on booking detail; public refund status line; D-169..D-175 promoted; 938 / 3918 baseline.

**PAYMENTS Session 4 (this session, 2026-04-24):**

- **`Dashboard\PayoutsController`**: new admin-only controller at `app/Http/Controllers/Dashboard/PayoutsController.php` with `index(Request): Response` and `loginLink(Request): JsonResponse`. Tenant-scoped via `tenant()->business()->stripeConnectedAccount` (locked decision #45 — no inbound business id, cross-tenant access impossible by construction). Sits inside `auth → billing.writable → role:admin` route stack: SaaS-lapsed admin can GET (read-only), POST blocked by `billing.writable`'s mutation-edge gate (D-090 + D-116).
- **Two-layer cache**: 60s in-payload **freshness** check (via `fetched_at` ISO timestamp) + 24h cache **TTL** (long-lived fallback). The originally-planned `Cache::remember(60s)` shape was wrong — short-circuits on still-fresh cache *before* the try block, so a Stripe failure can never mark `stale: true` when a prior cached value exists. The new shape: `Cache::get` first, return as-is if `isFresh()` (~60s window); else attempt Stripe → cache on success → fall back to prior cached payload with `stale: true` on failure (or empty `error: 'unreachable'` payload when no cache exists). `FRESHNESS_SECONDS = 60`, `CACHE_TTL_SECONDS = 86400`. Cache key `payouts:business:{id}` is business-scoped — no cross-tenant leak possible.
- **Inertia page**: `resources/js/pages/dashboard/payouts.tsx` (new, ~680 lines). Five top-level branches: not-connected (CTA → `/dashboard/settings/connected-account`); pending/incomplete (resume-onboarding alert); disabled (panel + verbatim Stripe `requirements_disabled_reason` + mailto support); active / unsupported_market (full payouts UI). Within active: health strip (3 chips with icon+text — colour-blind safe), banners (Stripe Tax not configured per locked decision #11; non-CH per locked decision #43; "couldn't refresh" stale fallback; "couldn't load" unreachable), available + pending balance cards, payout schedule card, "Manage payouts in Stripe" button, recent-payouts table.
- **Stripe Express dashboard login link**: `POST /dashboard/payouts/login-link` mints a fresh single-use URL on click (NOT pre-minted on `index` — Stripe expires login links within seconds; pre-minting would burn the link). Returns JSON `{url: '…'}` on success, `{error: '…'}` with 502 on Stripe failure. Frontend uses `useHttp` + `window.open(url, '_blank', 'noopener')` per the project's `resources/js/CLAUDE.md` rule. Inertia `<Form>` + `Inertia::location` was rejected because it cannot target `_blank` — same-tab navigation would yank the admin out of riservo.
- **Country gate is config-driven**: the React reads the supported set from a new `supportedCountries` Inertia prop (NOT a hardcoded `'CH'` literal anywhere). Server-side, the unsupported-market state derives from `StripeConnectedAccount::verificationStatus()` (D-150) which already folds in `config('payments.supported_countries')` (D-127). The "config-flip" test sets supported_countries to `['CH', 'DE']` mid-test and asserts a DE-country account no longer shows the unsupported_market banner — proves the seam is genuinely config-driven.
- **`FakeStripeClient` extended**: 4 new mocks. Connected-account-level (header asserted PRESENT): `mockBalanceRetrieve`, `mockPayoutsList`, `mockTaxSettingsRetrieve`. Platform-level (header asserted ABSENT): `mockLoginLinkCreate`. The Tax mock chains a two-level Mockery setup to model `$stripe->tax->settings->retrieve(...)`. The login-link mock hangs off the existing `accounts` service (Stripe SDK's `createLoginLink` is a method on `AccountService`, not a sub-service).
- **Navigation entry**: new "Payouts" item in `authenticated-layout.tsx` between Customers and Settings, admin-only, conditional on `connectedAccount !== null` (the existing `auth.business.connected_account` shared prop returns null when no row exists).
- **Tests**: 16 new feature cases in `tests/Feature/Dashboard/PayoutsControllerTest.php`. Coverage: happy path; staff-403; cross-tenant attack neutralised by ResolveTenantContext (admin of A pinned to B's id is silently re-pinned to A); admin-of-B sees only B (positive scoping); Stripe failure with cached prior state → stale flag; Stripe failure with empty cache → unreachable error; pending account → onboarding CTA with zero Stripe calls; disabled account → disabled panel; Tax pending status; non-CH unsupported_market; config-flip seam-open proof; login-link mint asserts header absent + URL in JSON; staff-403 on login-link; 404 on login-link without a row; 422 on login-link for disabled account; cache-key isolation between businesses.
- **Self-review round (1 round, 3 findings, all applied)**: P1 TS `<Tooltip delay={150}>` removed (`delay` is a `TooltipProvider` prop, not `Tooltip` Root); P2 TS `formatSchedule(payouts?.schedule ?? null, t)` coercion; P2 weak cross-tenant test replaced with a stronger one that registers ONLY A's Stripe mocks and proves Mockery would surface a leak as "method not expected". A pre-existing identical TS error in `resources/js/components/calendar/booking-hover-card.tsx:52` (same `delay` pattern on a Tooltip) is **out of scope for Session 4** — it predates this branch and `npm run build` does not strict-type-check the React side, so it's not flagged by CI.

**No new architectural decisions** (D-NNN). All behaviour flows from existing decisions: locked decisions #11 / #19 / #22 / #24 / #43 / #45 from the roadmap, plus D-112 (config-driven country gate), D-127 (`canAcceptOnlinePayments` includes country), D-138 (`requirements_disabled_reason` short-circuit), D-141 (canonical `Business.country`), D-150 (`verificationStatus()` returns `'unsupported_market'`).

97 architectural decisions (D-080..D-175) recorded across `docs/decisions/DECISIONS-*.md`. Next free decision ID: **D-176** (unchanged from Session 3).

---

## What is next

`docs/ROADMAP.md` — **PAYMENTS Session 5, `payment_mode` Toggle Activation + UI Polish**. Read the roadmap section under `## Session 5 — payment_mode Toggle Activation + UI Polish` for the brief.

Prerequisites met: every prior session is shipped. Session 5 lifts the hide-the-options ban on Settings → Booking, makes `online` and `customer_choice` user-facing, polishes copy across every touched surface, audits the codebase for any branching on `Business.payment_mode` that may have rotted under the option-hidden assumption, and adds the multi-condition gate (canAcceptOnlinePayments AND supported-country AND no other policy disable). Session 5 closes the PAYMENTS roadmap.

Session 4's payouts surface stays stable through Session 5 — Session 5 may polish the Payouts page copy as part of its UI-polish pass but should not change the controller, the cache layer, or the Stripe call shape.

Parked in `docs/roadmaps/`: `ROADMAP-E2E.md` (ongoing coverage) and `ROADMAP-GROUP-BOOKINGS.md` (post-MVP, not scheduled).

---

## Workflow (minimal)

1. Developer briefs an architect agent to review / revise `docs/ROADMAP.md`.
2. Developer briefs a planning agent for a single session. The agent reads `SPEC.md` + `HANDOFF.md` + `ROADMAP.md` + the relevant code, writes `docs/PLAN.md`, stops for developer approval.
3. On approval, the same agent (or a fresh one) implements the plan, keeps `## Progress` current in `docs/PLAN.md`, runs tests, stages the work. Never commits.
4. Developer reviews the diff. May also run codex review (`/codex:review` or the companion script) against the staged state — if run inside the plan+exec chat, the agent sees findings directly in the transcript; otherwise developer pastes them back. Agent applies fixes under a `## Review` section in `docs/PLAN.md` on the same uncommitted diff. Developer commits once at the end (single commit bundles exec + review fixes).
5. Agent rewrites `HANDOFF.md` if the session changed shipped state, promotes any new `D-NNN` into the matching `docs/decisions/DECISIONS-*.md` file, stages close artifacts. Developer commits.
6. At the start of the next session, `docs/PLAN.md` gets overwritten. Git keeps the previous plan.

Two developer gates per session: plan approval, commit.

---

## Conventions that future work must not break

All MVP-era conventions remain. Highlights most relevant to Session 5 onward:

- **Stripe SDK mocking via container binding (D-095)**. Every Stripe call flows through `app(StripeClient::class, ...)`. Tests mock via the `FakeStripeClient` builder in `tests/Support/Billing/`. Session 4 extended the platform-level bucket with `mockLoginLinkCreate` (header asserted ABSENT) and the connected-account-level bucket with `mockBalanceRetrieve`, `mockPayoutsList`, `mockTaxSettingsRetrieve` (header asserted PRESENT). The Tax mock chains a two-level Mockery setup to model `$stripe->tax->settings->retrieve(...)`. `mockRefundCreate` is still capped at `->once()` per registration so stacked mocks consume in order (Session 3 contract).
- **Connect webhook at `/webhooks/stripe-connect` (D-109, D-110)**. Unchanged through Session 4. Session 4 does not subscribe to new events; the payouts page is a live-read via `stripe.balance.retrieve` + `stripe.payouts.all` + `stripe.accounts.retrieve` + `stripe.tax.settings.retrieve`, scoped to a 60s freshness window via in-payload `fetched_at`.
- **Outcome-level idempotency (locked roadmap decision #33)**. Unchanged.
- **`CheckoutPromoter` is the single promotion service (D-151)**. Unchanged.
- **`RefundService` is the single refund executor**. Unchanged through Session 4.
- **Row-UUID idempotency key (D-162)**. Unchanged.
- **D-158 account pin**. Unchanged.
- **`payment_mode_at_creation` snapshot invariant (locked decision #14)**. Unchanged.
- **`Booking::pendingActions(): HasMany` is unfiltered**. Unchanged through Session 4.
- **Payment PAs are admin-only** (locked decisions #19 / #31 / #35 / #36). Unchanged.
- **Cancel paths dispatch `RefundService::refund` automatically**. Unchanged.
- **`BookingReceivedNotification` has four contexts**. **`BookingCancelledNotification` has the `$refundIssued` flag (D-175).** Session 4 doesn't touch notifications.
- **Direct charges on connected accounts for PAYMENTS** (locked roadmap decision #5). Unchanged.
- **GIST overlap constraint on bookings** (D-065 / D-066). Unchanged.
- **Tenant context via `App\Support\TenantContext` (D-063)** — every admin-only dashboard endpoint enforces `abort_if($business !== null && resource->business_id === tenant()->businessId(), 404)` or reads via `tenant()->business()->relation`. Session 4's `Dashboard\PayoutsController` uses the latter pattern: no inbound business id at all, both `index` and `loginLink` resolve via `tenant()->business()->stripeConnectedAccount`. Cross-tenant attack is neutralised by the ResolveTenantContext middleware's self-heal (admin of A pinning session to B's id is silently re-pinned to A).
- **Two-layer cache pattern for read-only Stripe surfaces** (PAYMENTS Session 4): in-payload `fetched_at` ISO timestamp drives the freshness check (60s); cache TTL is much longer (24h) to keep a fallback around for stale-on-failure. Future sessions that need the same pattern should consider lifting it into a shared helper (e.g. `app/Support/Billing/CachedStripeRead.php`); for now it's inlined in `PayoutsController`.
- **No hardcoded `'CH'` literal anywhere** (locked decision #43, D-112). React reads the supported set from Inertia props; PHP reads it from `config('payments.supported_countries')`; tests assert a config flip changes behaviour. Session 5's Settings → Booking gate inherits the same contract.

---

## Test / build commands

Iteration loop (agents):
```bash
php artisan test tests/Feature tests/Unit --compact
vendor/bin/pint --dirty --format agent
php artisan wayfinder:generate
./vendor/bin/phpstan
npm run build
```

Full suite (developer, pre-push):
```bash
php artisan test --compact
```

---

## Open follow-ups

See `docs/BACKLOG.md`. Most relevant post-Session-4 carry-overs:

- **Pre-existing TS error in `resources/js/components/calendar/booking-hover-card.tsx:52`** — same `delay` / `closeDelay` pattern on Base UI Tooltip Root that Session 4 fixed in `payouts.tsx`. `npm run build` does not strict-type-check the React side, so the error is not CI-blocking but the IDE flags it. One-line fix; out of scope for Session 4 because it predates this branch.
- **`stripe_charge_id` backfill on promotion** (D-173). Session 3 deferred — Session 4 confirmed the deferral (the payouts page lists payouts, not charges, so no need to backfill yet). Future session can backfill once there's a per-charge reconciliation use case.
- **Resend payment link for `unpaid` customer_choice bookings** — still deferred (post-MVP).
- **Formal support-contact surface (PAYMENTS Session 1, D-118)**. Connected Account disabled-state CTA + Session 4's payouts disabled-state CTA both mailto a placeholder; pre-launch needs a real flow.
- **Per-Business country selector for Stripe Express onboarding (D-121 superseded by D-141)**.
- **Collect country during business onboarding** (D-141 / D-143 follow-up).
- **Pre-role-middleware signed-URL session pinner (D-147 false-negative)**.
- **Tighten billing freeload envelope** (MVPC-3 D-089).
- **WeekScheduleEditor rename** from `components/onboarding/` to `components/settings/` (MVPC-4 cleanup).
- **R-16 frontend code splitting pass** on the whole bundle (the >500 kB Vite warning is pre-existing and unrelated; Session 4's payouts page added ~680 lines of TSX inside the main app chunk).
- **Calendar carry-overs** (R-17, R-19, R-9 items).
- **Prune old decisions**. `DECISIONS-HISTORY.md` plus anything superseded by D-080+.
- **Refund reason vocabulary promotion to enum** — only if Session 5 adds a sixth reason (D-174 keeps it as strings for now).
- **Lift the two-layer cache pattern from `PayoutsController` into a shared helper** (`app/Support/Billing/CachedStripeRead.php`) once a second consumer exists.
