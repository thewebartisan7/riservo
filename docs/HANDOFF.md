---
name: HANDOFF
description: "Current project state: what shipped last, what is active next, conventions future work must respect"
type: handoff
status: active
created: 2026-04-15
updated: 2026-04-18
---

# Handoff

**State**: MVP fully shipped + docs system restructured + workflow-orchestrator upgraded + brief-skills added. `docs/roadmaps/ROADMAP-PAYMENTS.md` is the next delivery target but is still `status: draft` (mid review; v1.1 post-review revision is in place, a further review round is pending). No PAYMENTS session has started yet. The canonical entry to spin up Layer 2 / Layer 3 of the workflow is now through two skills, not direct template reads.

**Date**: 2026-04-19
**Branch**: main — latest shipped commit `1a30413`. Three uncommitted workflow-template sessions are staged on top (all docs-only): `PLAN-WORKFLOW-AGENTIC-ORCHESTRATOR`, `PLAN-WORKFLOW-BRIEF-SKILLS`, and `PLAN-WORKFLOW-BRIEF-SKILLS-FIX`. On top of those, a multi-round codex adversarial-review loop against the same uncommitted stack landed further in-place edits to the workflow templates, the `/riservo-brief-orchestrator` skill, and this HANDOFF. All of it — three plans + review-loop fixes — is one pending commit the developer reviews and commits as a unit. **After that commit lands, this `1a30413` baseline is stale.** The next HANDOFF-rewriting session (or the developer, at commit time) should refresh the baseline commit line below to match the new post-commit HEAD — but the orchestrator no longer depends on it. `/riservo-brief-orchestrator` always derives the roadmap-start hash from `git rev-parse HEAD` at spawn time and captures a per-session `session_base:` at each session's Phase 1 for codex review pinning; a stale HANDOFF hash at most produces an advisory note in the emitted brief, never a base-selection prompt and never a blocked run.
**Tests**: Feature + Unit suite **693 passed / 2814 assertions** (iteration command `php artisan test tests/Feature tests/Unit --compact`). Browser/E2E suite under `tests/Browser` takes 2+ minutes and is the developer's pre-push / pre-release check, not part of the per-session iteration loop.
**Tooling**: Pint clean. Vite build clean (main app chunk 689 kB after MVPC-5's code splitting). Wayfinder regenerated.

---

## Workflow entry points (2026-04-18)

Two skills emit ready-to-paste prompts for the two coordinator layers. Use them — do not copy templates by hand.

- **`/riservo-brief-architect`** — run at the start of a new roadmap. Asks 2–4 shaping questions (intent, surface area, probing areas, settled decisions), fills `.claude/skills/riservo-brief-architect/assets/ROADMAP-ARCHITECT.md`, emits the block. Detects an existing draft roadmap for the topic and offers Mode-B (stress-test) if one matches.
- **`/riservo-brief-orchestrator`** — run at end of the architect session (or cold later against an already-active roadmap). Reads the active roadmap, HANDOFF, and topical decision files from disk; pre-flight-checks the codex plugin; fills `.claude/skills/riservo-brief-orchestrator/assets/ROADMAP-ORCHESTRATOR.md`; emits the block. No questions unless state is ambiguous.

For a one-off implementer session with no architect/orchestrator chain, read `.claude/skills/riservo-brief-orchestrator/assets/SESSION-IMPLEMENTER.md` directly and brief an implementer. No skill wraps this — the case is rare enough that direct template use is the right default.

---

## Workflow mechanics (unchanged by this session; hardened in 2026-04-18's prior session)

- Orchestrator is active, not passive. It spawns the per-session implementer via Claude Code's `Agent` tool (`subagent_type: "general-purpose"`, `run_in_background: true`) and continues it across plan → implementation → codex-fix rounds via `SendMessage`.
- Codex is invoked by the orchestrator through Bash on the codex-companion script, with the review range pinned explicitly per session: `node "${CLAUDE_PLUGIN_ROOT}/scripts/codex-companion.mjs" review --background --base <session_base> --scope branch` (or `adversarial-review … --base <session_base> --scope branch [focus text]` for security / concurrency / payments / auth surfaces). `<session_base>` is **session-scoped, not roadmap-scoped**: the orchestrator captures it via `git rev-parse HEAD` at Phase 1 spawn time for each session (before the sub-agent makes any commits), and the implementer records it in that session's plan frontmatter as `session_base: <hash>`. Phase 5 reads it from the plan file, so the base survives orchestrator respawn and each session's review range stays tight (only that session's commits + its own codex-fix rounds — not prior shipped sessions). **Both flags are mandatory**: without them the script falls back to `auto` scope, which reviews working-tree state or picks its own base — both wrong targets. The precondition before invoking codex is that HEAD equals the just-recorded session commit hash; working-tree dirt from Phase 4's plan-file bookkeeping (status flip, `commits: [...]` append, log append) is expected and does not affect a `--scope branch --base <hash>` review, which sees only the commit range. Then `status <id>`, then `result <id>`. The plugin's `/codex:*` slash commands all declare `disable-model-invocation: true` and cannot be self-invoked by agents — the companion script is the only machine path.
- Status lock: `status: active` means implementer works, codex does not; `status: review` means codex works, implementer is frozen. The implementer flips `planning → active` once on approval. The implementer flips `active → review` ONLY when the orchestrator's `[COMMIT HASH RECORDED]` envelope arrives carrying the developer's commit hash — never on staged-but-uncommitted changes. The orchestrator alone flips `review → active` for codex-fix rounds; the implementer never flips that direction.
- Triage authority split: clear bugs route autonomously from orchestrator to implementer; judgment calls pause for the developer.
- Developer gates are exactly two per session: plan approval and commit/push. No agent commits. No agent pushes.
- Parallel-sub-agent exception: a roadmap may declare `parallel_sessions: [S1, S2a, …]` in its frontmatter naming the specific sessions whose sub-agents may run concurrently. Session-scoped, not roadmap-global — unnamed sessions stay serial (e.g. `ROADMAP-E2E.md`'s per-route browser-test sessions). Off by default.
- Context escape hatch: when the orchestrator crosses the halfway mark of its 1M-token budget, it writes a handoff brief for a fresh orchestrator.
- All prompt templates live under each skill's `assets/`:
  - `.claude/skills/riservo-brief-architect/assets/ROADMAP-ARCHITECT.md`
  - `.claude/skills/riservo-brief-orchestrator/assets/ROADMAP-ORCHESTRATOR.md`
  - `.claude/skills/riservo-brief-orchestrator/assets/SESSION-IMPLEMENTER.md`

---

## Docs system (unchanged from 2026-04-17's restructure)

The docs directory is driven by YAML frontmatter, indexed for discoverability, and does not move files on close. Headline rules:

- Every roadmap / plan / review / handoff file carries a YAML frontmatter block with a `status:` field. Seven-status taxonomy: `draft | planning | active | review | shipped | superseded | abandoned`. Which statuses apply to which `type:` is defined in `docs/README.md` § "Status taxonomy".
- **`docs/archive/` does not exist.** Plans, roadmaps, and reviews live at their canonical paths for their full lifetime; `status:` flips on close rather than the file moving.
- **Indices vs directory-only:** plans and roadmaps drive a row in `docs/PLANS.md` / `docs/ROADMAP.md` respectively. Reviews and handoff are frontmatter-only, not indexed — reviews are directory-scoped (cross-cutting audits only), handoff is a single file.
- **Three-bucket indices.** Both `docs/PLANS.md` and `docs/ROADMAP.md` group rows into `## In flight` (anything pre-terminal), `## Shipped`, and `## Superseded` (plus `Abandoned` for PLANS.md).
- **Plan files hold the whole session lifecycle**: pre-impl sections plus five living sections appended during work (`## Progress`, `## Implementation log`, `## Surprises & discoveries`, `## Post-implementation review`, `## Close note / retrospective`). Which living sections are mandatory depends on session type — see `docs/README.md` § "Session lifecycle in a plan file".
- **HANDOFF is conditional**: required for sessions that change shipped product / runtime state or the workflow itself; docs-only sessions that do neither may skip it.
- **Mechanical consistency check**: `php artisan docs:check` (truth engine) and the `/riservo-status` skill (ergonomic wrapper) verify the frontmatter ↔ index contract. Run before commit on any docs-touching session.
- **Four-layer work flow**: INTENT → ROADMAP (architect) → ORCHESTRATION (orchestrator) → IMPLEMENTATION (per-session implementer). Entry skills: `/riservo-brief-architect`, `/riservo-brief-orchestrator`. Authoritative conventions in `docs/README.md`.

---

## What is shipped

The MVP is complete. Two roadmaps delivered, in order:

### Original MVP roadmap (`docs/roadmaps/ROADMAP-MVP.md`)
Sessions 1–11 shipped: data layer, scheduling engine, frontend foundation, authentication, onboarding wizard, public booking flow, business dashboard, settings, email notifications, calendar view. Sessions 12–13 were rescoped into the second roadmap below rather than shipped from the original.

### MVP Completion roadmap (`docs/roadmaps/ROADMAP-MVP-COMPLETION.md`)
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

## What is next

`docs/roadmaps/ROADMAP-PAYMENTS.md` (v1.1 — post-review revision; **`status: draft`**, a further review round is pending). Customer-to-professional Stripe Connect Express integration, TWINT-first, zero riservo commission. Six sessions:

1. Stripe Connect Express Onboarding
2a. Payment at Booking — Happy Path
2b. Payment at Booking — Failure Branching + Admin Surface
3. Refunds (Customer Cancel, Admin Manual, Business Cancel) + Disputes
4. Payout Surface + Connected Account Health
5. `payment_mode` Toggle Activation + UI Polish

28 cross-cutting decisions locked in the roadmap's decisions section. Implementing agents record new IDs starting at **D-109** in `docs/decisions/DECISIONS-PAYMENTS.md`.

**No PAYMENTS session has started, and no session can start while the roadmap is `draft`.** The developer's next action is to close out the remaining architect review round: run `/riservo-brief-architect` in Mode B (stress-test against the existing draft) to finalise the roadmap and flip `status: draft → active`. Only after that flip is the orchestrator legal — `/riservo-brief-orchestrator` refuses to run on a non-`active` roadmap. Once active, the orchestrator skill emits the filled prompt for Session 1.

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
- **Dogfood the active orchestrator + brief skills** against PAYMENTS-1. First real run will surface whatever the templates got wrong; expect at least one patch to the orchestrator template's Phase 5 codex-invocation shape.
- **500k-token escape-hatch threshold** is a guess. Track whether it fires during PAYMENTS; bump or drop accordingly.

---

## For the next session agent

1. Read this file, then `docs/README.md` (frontmatter + status convention + four-layer flow).
2. **If `docs/roadmaps/ROADMAP-PAYMENTS.md` is still `status: draft` (expected):** run `/riservo-brief-architect`. It detects the existing draft and offers the Mode-B stress-test brief; fill it out and paste into a fresh chat. Iterate on the stress-test report until satisfied, then give the architect an explicit sign-off ("approved, apply them"). Per the architect template's § Mode B step 5, that sign-off triggers the architect to apply the agreed edits to `ROADMAP-PAYMENTS.md`, flip `status: draft → planning → active`, update the matching `docs/ROADMAP.md` row, and run `docs:check` — all in-session. Do NOT hand-edit the roadmap yourself before sign-off; do NOT ask the architect to flip status without an explicit approval phrase. Once the architect reports `status: active` and clean docs:check, proceed to step 3.
3. **Once the roadmap is `status: active`:** run `/riservo-brief-orchestrator`. It reads `docs/roadmaps/ROADMAP-PAYMENTS.md`, extracts Session 1's title and context, pulls baseline from this HANDOFF, confirms the codex plugin is installed, and emits a filled orchestrator prompt.
4. Paste the emitted block into a **fresh chat**. That chat is the PAYMENTS orchestrator; keep it open for the full roadmap.
5. The orchestrator spawns the Session 1 implementer via `Agent`, relays plan-review + approval + codex-fix envelopes via `SendMessage`, and closes when codex returns clean. You approve the plan and commit the code.
6. The next free decision ID is **D-109** in `docs/decisions/DECISIONS-PAYMENTS.md`.
