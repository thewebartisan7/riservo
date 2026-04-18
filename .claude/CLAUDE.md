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

- Start with `docs/README.md`. It is the source of truth for what to read first, what to read only when relevant, and how the docs system works.
- Use `docs/DECISIONS.md`, `docs/ROADMAP.md`, and `docs/PLANS.md` as stable indices. Open individual files only when their row matches the current task.
- Every roadmap / plan / review / handoff file carries a YAML frontmatter block whose `status:` is the authoritative lifecycle state (`draft | planning | active | review | shipped | superseded | abandoned`). See `docs/README.md` § "Status taxonomy" for which statuses apply to which type.
- Files **do not move** when they ship. Path stays stable; `status:` flips.
- New architectural decisions go in the topical file listed in `docs/DECISIONS.md`. Never add a decision body directly to `DECISIONS.md`.

---

## Directories to Not Search by Default

- `docs/plans/` — do not bulk-read. Use `docs/PLANS.md` to find the plan relevant to the current task, then open only that file.
- `docs/roadmaps/` — same: open via `docs/ROADMAP.md`.
- `docs/reviews/` — cross-cutting audits only; open a specific file by name.
- `docs/decisions/` — open via `docs/DECISIONS.md`.

---

## Session Workflow

1. The developer starts a new chat with the task or session context.
2. The agent reads `docs/README.md`, then follows its read-first links before planning.
3. The agent asks clarification questions before planning when intent is unclear.
4. The agent writes a task plan in `docs/plans/PLAN-{ID}.md` (status: `draft` / `planning`) and waits for approval before coding.
   - `PLAN-SESSION-{N}.md` for main roadmap sessions
   - `PLAN-R-{N}-{SHORT-TITLE}.md` for review-remediation sessions
   - `PLAN-{TOPIC}.md` for one-off topics
5. On approval, the agent flips the plan's `status:` to `active` and implements it.
6. During / after implementation, the agent maintains the living-document sections on the plan (`## Progress`, `## Implementation log`, `## Surprises & discoveries`) with deviations, surprises, and commit hashes.
7. If the session changed shipped product / runtime state or changed the workflow itself, the agent rewrites `docs/HANDOFF.md` (overwrite, not append). Docs-only / workflow-only sessions that do neither may skip HANDOFF. Roadmap checklists always get updated when relevant.
8. New architectural decisions are recorded in the appropriate topical file listed in `docs/DECISIONS.md`.
9. At session close, the agent flips the plan's `status:` to `shipped` (or `review` if a codex pass is pending, `abandoned` if the session is stopped without a successor, `superseded` if a replacement plan takes over), moves its `docs/PLANS.md` row to the matching bucket, and the file stays in place — no move.

**Never write code before the plan is approved by the developer.**

---

## Session Done Checklist

Before closing an implementation session, the agent verifies:

- All tests pass: `php artisan test --compact` (iteration loop uses `php artisan test tests/Feature tests/Unit --compact`).
- Code style is clean: `vendor/bin/pint --dirty --format agent`.
- Frontend builds: `npm run build`.
- `docs/HANDOFF.md` is rewritten (overwrite, not append) **if** the session changed shipped product / runtime state or changed the workflow / canonical reading order. Docs-only sessions that do neither may skip HANDOFF. If HANDOFF exceeds 200 lines, split carry-over items to `docs/BACKLOG.md`.
- Affected roadmap checklists are updated.
- New decisions are recorded in the appropriate topical file under `docs/decisions/`. If the session introduced a brand-new `DECISIONS-*.md` topical file, its row is added to `docs/DECISIONS.md` in the same commit.
- Plan file: `status:` flipped, `updated:` bumped, `commits: [...]` populated. Code-touching sessions have all five living sections populated (`## Progress`, `## Implementation log`, `## Surprises & discoveries`, `## Post-implementation review`, `## Close note / retrospective`); empty sections keep the heading plus a one-line "no X this session" note. Docs-only and hotfix sessions may omit `## Progress`, `## Surprises & discoveries`, and `## Post-implementation review`.
- `docs/PLANS.md` row for this plan is moved from `## In flight` to `## Shipped` and its status column updated.
- If a roadmap finished, `docs/ROADMAP.md` row reflects the new status.
- Mechanical consistency between frontmatter and indices verified. See `docs/README.md` § "Mechanical consistency check" for the convention and the current checker (skill or script, whichever is live).

Docs-only or workflow-only sessions that do not change the workflow itself may skip the HANDOFF update, but still flip the plan's `status:` and move its `docs/PLANS.md` row.

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
