# riservo.ch — Scoped Agent Guide

This file is the scoped instruction entry point for work in this directory subtree.

---

## Project Overview

**riservo.ch** is a SaaS appointment booking platform built with:
- **Backend**: Laravel 13 (PHP)
- **Frontend**: Inertia.js + React + TypeScript
- **UI Library**: COSS UI — Tailwind CSS, copy-paste component library (no npm package)
- **Database**: Postgres 16 (all environments, managed on Laravel Cloud in production)
- **Billing**: Laravel Cashier (Stripe) on the `Business` model
- **Auth**: Custom controllers — no Laravel Fortify, no Jetstream

---

## Documentation Workflow

Start with `docs/README.md`. Four live files drive project state:

- `docs/SPEC.md` — product scope + domain + rules.
- `docs/ROADMAP.md` — active roadmap. Overwritten when a new one starts.
- `docs/PLAN.md` — current session plan. Overwritten at each new session. Codex review findings go in a `## Review` section inside the same file.
- `docs/HANDOFF.md` — state between sessions. Rewritten (not appended) at each close.

Architectural decisions live under `docs/decisions/DECISIONS-{TOPIC}.md`. The directory listing is the index — there is no `DECISIONS.md` index file. Roadmap-drafting agents read them; plan + execute agents only read the ones the session brief points at.

Parked roadmaps (future work) live in `docs/roadmaps/`: `ROADMAP-E2E.md` (ongoing), `ROADMAP-GROUP-BOOKINGS.md` (post-MVP). When one becomes active, its body replaces `docs/ROADMAP.md` and the draft file gets deleted.

**Git is the history.** No index files, no frontmatter status contract, no `docs/archive/`. To find a previous plan or roadmap: `git log --follow docs/PLAN.md` or `git log --follow docs/ROADMAP.md`.

---

## Session types

riservo has three session shapes. The developer picks which one by how they brief the agent. **Apply the rules for the one the developer named; ignore the others.**

### A. Roadmap session (architect / analyst)

**Purpose**: produce or revise `docs/ROADMAP.md` — the currently active delivery roadmap. WHAT-level planning only, not HOW.

**Trigger phrases**: *"let's plan the next roadmap"*, *"new roadmap for X"*, *"stress-test the current ROADMAP.md"*, *"review and revise the roadmap"*.

**Reference**: `.claude/references/ROADMAP.md` (canonical structure, probing checklist, two operating modes A/B, quality bar).

**Artifacts**: `docs/ROADMAP.md` (overwritten or revised in place). No code, no session plan, no commits from the agent.

**Flow**: interview the developer → read `SPEC.md` + `HANDOFF.md` + topical decision files + relevant code → draft or stress-test → iterate with the developer → developer commits.

### B. Plan + exec session (standard implementation)

**Purpose**: take one session from `docs/ROADMAP.md` and deliver it. Plan → implementation → review → commit.

**Trigger phrases**: *"ready for Session N of the roadmap"*, *"implement X"*, *"let's ship <session title>"*.

**Reference**: `.claude/references/PLAN.md` (canonical plan structure, quality bar, validation rules, Review section format).

**Artifacts**: `docs/PLAN.md` (overwritten at session start — previous session's plan stays in git history), staged code, updated `docs/HANDOFF.md` at close if shipped state changed.

**Flow — ceremony sequence**:
1. **Plan**: the agent reads the relevant roadmap session + `SPEC.md` + `HANDOFF.md` + the code in scope, then writes `docs/PLAN.md` following `.claude/references/PLAN.md`. The agent does not write code until the developer approves the plan (gate one).
2. **Exec**: on approval, the agent implements, maintaining `## Progress` and `## Decision Log` inside `docs/PLAN.md` as it works. It stages frequently and **never commits**.
3. **Review**: when the work is staged and iteration-loop tests green, the developer triggers codex review against the **staged / working-tree state** (`/codex:review` or the companion script). If the review runs inside the plan+exec session's own chat, the agent reads the findings directly from the transcript; if run in a separate chat or terminal, the developer pastes them back. The agent applies the fixes under a `## Review` section in `docs/PLAN.md` on the same uncommitted diff. Multiple review rounds may stack pre-commit.
4. **Commit**: the developer commits once at the end with the full reviewed change bundled (gate two).
5. **Close**: the agent rewrites `docs/HANDOFF.md` if the session changed shipped product / runtime state, **promotes any new `D-NNN` into the matching `docs/decisions/DECISIONS-{TOPIC}.md` file**, and stages the close artifacts. The developer commits the close.

**Never write code before `docs/PLAN.md` is approved by the developer.**

**The one discipline rule**: promote new architectural decisions into `docs/decisions/DECISIONS-*.md` before the next session overwrites `PLAN.md`. Otherwise the decisions disappear from `PLAN.md` on overwrite. Better: write them directly into the topical file during implementation and reference the `D-NNN` from `PLAN.md`.

**Session Done Checklist (plan + exec only)**:
- Iteration-loop tests pass: `php artisan test tests/Feature tests/Unit --compact`.
- Code style is clean: `vendor/bin/pint --dirty --format agent`.
- Wayfinder regenerated: `php artisan wayfinder:generate`.
- Frontend builds: `npm run build`.
- `docs/HANDOFF.md` rewritten (overwrite, not append) if the session changed shipped product / runtime state. Docs-only sessions may skip.
- Any new architectural decisions promoted into the matching topical file under `docs/decisions/`.
- `docs/PLAN.md` reflects the final state: `## Progress` current, `## Decision Log` populated, `## Review` section present if codex ran, `## Outcomes & Retrospective` written.
- The developer performs the final commit.

### C. Quick fix session (small, no ceremony)

**Purpose**: a one-file change, a tiny bug fix, a small copy tweak, a dependency bump. Small enough that writing a plan costs more than the fix.

**Trigger phrases**: *"quick fix: …"*, *"just update X to Y"*, *"small change: …"*. The developer is explicit that this is a quick fix.

**Reference**: none — the absence of ceremony is the point.

**Artifacts**: staged code only. No `docs/PLAN.md` touched. No `docs/HANDOFF.md` rewrite unless the fix changes shipped behavior (rare for quick fixes; if in doubt, ask the developer before the close).

**Flow**: make the change → run the affected tests (`php artisan test --filter=…` for the subset, or the full iteration loop if uncertain) → `vendor/bin/pint --dirty --format agent` → stage → report the diff summary to the developer → developer commits.

**When in doubt, escalate to a plan + exec session**. If the "quick fix" touches more than ~3 files, crosses a domain boundary, adds a new test, or re-opens a locked `D-NNN`, it is not a quick fix — stop and propose a plan + exec session to the developer.

---

## Critical Rules

- **No Laravel Fortify** — auth is implemented with custom controllers
- **No Jetstream** — not used in this project
- **Tailwind CSS is used** — UI is built with COSS UI (copy-paste, Tailwind-based)
- **No Laravel Zap** — scheduling engine is custom
- **All datetimes in UTC** — convert to business timezone for display only
- **All user-facing strings use `__()`** — English base, translations added pre-launch
- **Multi-tenancy via `business_id` scoping** — every query on business-owned data must be scoped
- **Tests before code** — for the scheduling engine especially, write tests first

---

## Key Conventions

- **Slug**: every Business has a unique slug used in all public URLs (`riservo.ch/{slug}`)
- **Reserved slugs**: a blocklist prevents businesses from registering slugs that conflict with system routes
- **Customer model**: always separate from `users` table — guests have `user_id = null`
- **Booking source**: every booking has a `source` field (`riservo` | `google_calendar` | `manual`)
- **Magic links**: implemented with `URL::temporarySignedRoute()`, one-time use, 15–30 min expiry
- **File storage**: use Laravel's `Storage` facade — local in dev, Laravel Cloud managed object storage in prod
