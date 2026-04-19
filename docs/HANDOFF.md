# Handoff

**State (2026-04-19):** MVP shipped. Docs system collapsed to 4 live files (`SPEC.md`, `ROADMAP.md`, `PLAN.md`, `HANDOFF.md`) plus the decisions directory and a handful of reference docs — see `docs/README.md`. `ROADMAP-PAYMENTS` content is now at `docs/ROADMAP.md` (status: draft, pending a further architect review before Session 1 opens). No PAYMENTS session has started yet.

**Branch**: main.
**Feature+Unit suite**: 695 passed / 2819 assertions measured 2026-04-19 after the docs-system collapse (13-test `DocsCheckCommandTest` removed; other tests accrued since prior baseline). Re-measure with `php artisan test tests/Feature tests/Unit --compact` if in doubt.
**Full suite**: 2+ minutes because of `tests/Browser`. Developer pre-push check.
**Tooling**: Pint clean. Vite build clean (main app chunk 689 kB post-MVPC-5 split). Wayfinder regenerated.

---

## What shipped

MVP (Sessions 1–11) + MVP Completion (MVPC-1..5): data layer, scheduling engine, frontend foundation, auth, onboarding, public booking, dashboard, settings, email notifications, calendar view, Google OAuth, bidirectional Google Calendar sync, Cashier subscription billing, provider self-service settings, advanced calendar interactions. E2E-0..6 also shipped. See `git log --oneline --all --since=2026-04-14` for the ledger.

29 architectural decisions (D-080–D-108) recorded in `docs/decisions/DECISIONS-*.md` across the MVPC rounds. Next free decision ID: **D-109**.

---

## What is next

`docs/ROADMAP.md` — **customer-to-professional Stripe Connect payments**, TWINT-first, zero commission. Six sessions:

1. Stripe Connect Express Onboarding
2a. Payment at Booking — Happy Path
2b. Payment at Booking — Failure Branching + Admin Surface
3. Refunds + Disputes
4. Payout Surface + Connected Account Health
5. `payment_mode` Toggle Activation + UI Polish

28 cross-cutting decisions locked inside `docs/ROADMAP.md`'s body. New decision IDs start at **D-109** in `docs/decisions/DECISIONS-PAYMENTS.md` (currently empty apart from the header).

Status: `draft`. A further architect review round is pending before Session 1 opens. Once the roadmap is finalised, the developer briefs an agent to plan Session 1 into `docs/PLAN.md`.

Parked in `docs/roadmaps/`: `ROADMAP-E2E.md` (ongoing coverage, ticks up with each feature) and `ROADMAP-GROUP-BOOKINGS.md` (post-MVP, not scheduled).

---

## Workflow (minimal)

1. Developer briefs an architect agent to review / revise `docs/ROADMAP.md`.
2. Developer briefs a planning agent for a single session. The agent reads `SPEC.md` + `HANDOFF.md` + `ROADMAP.md` + the relevant code, writes `docs/PLAN.md`, stops for developer approval.
3. On approval, the same agent (or a fresh one) implements the plan, keeps `## Progress` current in `docs/PLAN.md`, runs tests, stages the work. Never commits.
4. Developer reviews the diff. May also run codex review (`/codex:review` or the companion script) against the staged state — if run inside the plan+exec chat, the agent sees findings directly in the transcript; otherwise developer pastes them back. Agent applies fixes under a `## Review` section in `docs/PLAN.md` on the same uncommitted diff. Developer commits once at the end (single commit bundles exec + review fixes).
5. Agent rewrites `HANDOFF.md` if the session changed shipped state, promotes any new `D-NNN` into the matching `docs/decisions/DECISIONS-*.md` file, stages close artifacts. Developer commits.
6. At the start of the next session, `docs/PLAN.md` gets overwritten. Git keeps the previous plan.

Two developer gates per session: plan approval, commit. No orchestrator, no brief skills, no index files.

---

## Conventions that future work must not break

All MVP-era conventions remain. Highlights most relevant to PAYMENTS:

- **Stripe SDK mocking via container binding (D-095)**. Every Stripe call in Cashier flows through `app(StripeClient::class, ...)`. Tests mock via `app()->bind(StripeClient::class, fn () => $mock)`; shared helper at `tests/Support/Billing/FakeStripeClient.php`. PAYMENTS extends this for the Connect API surface.
- **Webhook endpoints under `/webhooks/*`**. MVPC-2 `/webhooks/google-calendar`, MVPC-3 `/webhooks/stripe`. Connect adds `/webhooks/stripe-connect` per locked PAYMENTS decision #38. All CSRF-excluded, signature-validated, idempotent via cache-layer event-id dedup (D-092). Connect uses a **distinct webhook secret** from subscriptions.
- **`billing.writable` middleware (D-090)** wraps every mutating dashboard route via the outer group. Any new mutating `/dashboard/*` route inherits the read-only gate automatically; `ReadOnlyEnforcementTest` is the structural canary.
- **Server-side automation runs unconditionally**, even for read-only businesses. `AutoCompleteBookings`, `SendBookingReminders`, `calendar:renew-watches`, `PullCalendarEventsJob`, `StripeWebhookController` — all keep firing for existing data regardless of subscription state. The gate is HTTP-only (D-090 §4.12).
- **Direct charges on connected accounts for PAYMENTS** (locked roadmap decision #5). Professionals are merchant of record; `Stripe-Account` header on every Connect call; no `application_fee_amount`.
- **GIST overlap constraint on bookings** (D-065 / D-066) is the race-safe backstop. PAYMENTS Session 2's reserve-then-pay pattern relies on it during the Checkout window.
- **`Booking::shouldSuppressCustomerNotifications()` / `shouldPushToCalendar()` (D-088, D-083)** — every booking mutation site uses them. PAYMENTS extends; never bypasses.
- **Tenant context via `App\Support\TenantContext` (D-063)** — never inject `Business` from the request directly; read via `tenant()`.
- **Shared Inertia props on `auth.business`** — `subscription` (MVPC-3), `role` and `has_active_provider` (MVPC-4). PAYMENTS Session 1 adds connected-account state in the same shape.

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

---

## Open follow-ups

See `docs/BACKLOG.md`. Most relevant post-MVP carry-overs:

- **Resend payment link for `unpaid` customer_choice bookings** (PAYMENTS Session 2 carry-over).
- **Tighten billing freeload envelope** (MVPC-3 D-089 — `past_due` write-allowed window bounded by Stripe's dunning).
- **WeekScheduleEditor rename** from `components/onboarding/` to `components/settings/` (MVPC-4 cleanup).
- **R-16 frontend code splitting pass** on the whole bundle.
- **Calendar carry-overs** (R-17, R-19, R-9 items).
- **Prune old decisions**. `DECISIONS-HISTORY.md` plus anything superseded by D-080+. Judgment call per entry.
