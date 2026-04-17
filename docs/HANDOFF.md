# Handoff

**State**: MVP fully shipped. Between roadmaps — `docs/roadmaps/ROADMAP-PAYMENTS.md` is the next active delivery plan; no session of it has started yet.

**Date**: 2026-04-17
**Branch**: main — latest commit `eae8051`
**Tests**: Feature + Unit suite **693 passed / 2814 assertions** (iteration command `php artisan test tests/Feature tests/Unit --compact`). Browser/E2E suite under `tests/Browser` takes 2+ minutes and is the developer's pre-push / pre-release check, not part of the per-session iteration loop.
**Tooling**: Pint clean. Vite build clean (main app chunk 689 kB after MVPC-5's code splitting). Wayfinder regenerated.

---

## What is shipped

The MVP is complete. Two roadmaps delivered, in order:

### Original MVP roadmap (`docs/archive/roadmaps/ROADMAP-MVP.md`)
Sessions 1–11 shipped: data layer, scheduling engine, frontend foundation, authentication, onboarding wizard, public booking flow, business dashboard, settings, email notifications, calendar view. Sessions 12–13 were rescoped into the second roadmap below rather than shipped from the original.

### MVP Completion roadmap (`docs/archive/roadmaps/ROADMAP-MVP-COMPLETION.md`)
All five sessions shipped:

| Session | Commit | Outcome |
|---|---|---|
| MVPC-1 | `5388d8a` | Google OAuth foundation (Socialite + shared admin+staff settings group) |
| MVPC-2 | `132535e` | Google Calendar bidirectional sync (push + pull + webhooks + pending actions) |
| MVPC-3 | `8da1c5d` | Subscription billing via Cashier (single tier, indefinite trial, read-only gate after cancellation) |
| MVPC-4 | `3eb5bab` + `da6ac3c` | Provider self-service settings (Account + Availability opened to staff with a Provider row) |
| MVPC-5 | `21f7e00` + `eae8051` | Advanced calendar interactions (drag, resize, click-to-create, hover preview, bundle code-split) |

29 new decisions recorded (D-080 through D-108) across `DECISIONS-FOUNDATIONS.md`, `DECISIONS-AUTH.md`, `DECISIONS-DASHBOARD-SETTINGS.md`, `DECISIONS-CALENDAR-INTEGRATIONS.md`, `DECISIONS-FRONTEND-UI.md`, and `DECISIONS-BOOKING-AVAILABILITY.md`. See `docs/DECISIONS.md` for the index.

---

## What is active next

`docs/roadmaps/ROADMAP-PAYMENTS.md` (v1.1 — post-review revision). Customer-to-professional Stripe Connect Express integration, TWINT-first, zero riservo commission. Six sessions:

1. Stripe Connect Express Onboarding
2a. Payment at Booking — Happy Path
2b. Payment at Booking — Failure Branching + Admin Surface
3. Refunds (Customer Cancel, Admin Manual, Business Cancel) + Disputes
4. Payout Surface + Connected Account Health
5. `payment_mode` Toggle Activation + UI Polish

28 cross-cutting decisions locked in the roadmap's decisions section. Implementing agents record new IDs starting at **D-109** in `docs/decisions/DECISIONS-PAYMENTS.md` (created empty and ready; added to the `DECISIONS.md` index).

No PAYMENTS session has started. The developer spawns an orchestrator / implementing agent when ready to begin.

---

## Conventions that future work must not break

All MVP-era conventions remain. Highlights most relevant to PAYMENTS work:

- **Stripe SDK mocking via container binding (D-095)**. Every Stripe call in Cashier flows through `app(StripeClient::class, ...)`. Tests mock via `app()->bind(StripeClient::class, fn () => $mock)`; `instance()` bindings are bypassed because Cashier passes constructor parameters. The shared test helper lives at `tests/Support/Billing/FakeStripeClient.php`. PAYMENTS' Connect work extends this pattern for the Connect API surface.
- **Webhook endpoints follow the `/webhooks/*` convention** (MVPC-2 `/webhooks/google-calendar`, MVPC-3 `/webhooks/stripe`). Connect adds a third at `/webhooks/stripe-connect` per locked PAYMENTS decision. All three are CSRF-excluded in `bootstrap/app.php`, signature-validated, and idempotent via the cache-layer event-id dedup pattern (D-092). Connect uses a **distinct webhook secret** from subscriptions — different Stripe dashboard endpoint.
- **`billing.writable` middleware (D-090)** wraps every mutating dashboard route via the outer group. Any new mutating route in `/dashboard/*` inherits the read-only gate automatically; the structural `Route::getRoutes()` introspection test in `ReadOnlyEnforcementTest` is the canary.
- **Server-side automation runs unconditionally**, even for read-only businesses. `AutoCompleteBookings`, `SendBookingReminders`, `calendar:renew-watches`, `PullCalendarEventsJob`, `StripeWebhookController` all keep firing for existing data regardless of subscription state. The gate is HTTP-only (D-090 §4.12).
- **Direct charges on connected accounts for PAYMENTS** (locked roadmap decision #5) — professionals are the merchant of record; receipts / invoices brand them, not riservo. `Stripe-Account` header on every Connect call. No `application_fee_amount` anywhere.
- **GIST overlap constraint on bookings** (D-065 / D-066) is the race-safe backstop. MVPC-5's reschedule endpoint catches `23P01` and returns 409. PAYMENTS Session 2's reserve-then-pay pattern relies on the same constraint during the Checkout window.
- **`Booking::shouldSuppressCustomerNotifications()` and `Booking::shouldPushToCalendar()` helpers (D-088, D-083)** — every booking mutation site uses them. PAYMENTS extends; it does not bypass.
- **Tenant context via `App\Support\TenantContext` (D-063)** — never inject a `Business` from the request directly; read via `tenant()`.
- **Shared Inertia props on `auth.business`** — `subscription` (MVPC-3), `role` and `has_active_provider` (MVPC-4). PAYMENTS Session 1 will add connected-account state in the same shape.

---

## Test / build commands

Iteration loop (agents):
```bash
php artisan test tests/Feature tests/Unit --compact
vendor/bin/pint --dirty --format agent
php artisan wayfinder:generate
npm run build
```

Full suite (developer, pre-push):
```bash
php artisan test --compact
```

`docs/TESTS.md` has the full command matrix.

---

## Open follow-ups

See `docs/BACKLOG.md`. Most relevant post-MVP carry-overs:

- **Resend payment link for `unpaid` customer_choice bookings** (PAYMENTS Session 2 carry-over, deferred to a future focused mini-session).
- **Tighten billing freeload envelope** (MVPC-3 D-089 consequence — `past_due` write-allowed window bounded by Stripe's dunning policy).
- **WeekScheduleEditor rename** from `components/onboarding/` to `components/settings/` (MVPC-4 cleanup, no-op refactor).
- **R-16 frontend code splitting pass** on the whole bundle (MVPC-5 cut the calendar chunk; a broader pass is still possible).
- **Calendar carry-overs** (R-17, R-19, R-9 items) remain as listed.

---

## For the next session agent

1. Read this file, then `docs/README.md`.
2. Read `docs/roadmaps/ROADMAP-PAYMENTS.md` in full (Cross-cutting decisions section + your assigned session).
3. Read the relevant topical decision files per the session's read-first list.
4. Follow the workflow: write your plan to `docs/plans/PLAN-PAYMENTS-N-{TITLE}.md`, STOP, wait for approval, implement, verify, close.
5. The next free decision ID is **D-109**.
