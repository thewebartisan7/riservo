# riservo.ch — Scoped Agent Guide

This file is the scoped instruction entry point for work in this directory subtree.

---

## Project Overview

**riservo.ch** is a SaaS appointment booking platform built with:
- **Backend**: Laravel 13 (PHP)
- **Frontend**: Inertia.js + React + TypeScript
- **UI Library**: COSS UI — Tailwind CSS, copy-paste component library (no npm package)
- **Database**: SQLite (local dev), MariaDB (production)
- **Billing**: Laravel Cashier (Stripe) on the `Business` model
- **Auth**: Custom controllers — no Laravel Fortify, no Jetstream

---

## Documentation Workflow

- Start with `docs/README.md`. It is the source of truth for what to read first, what to read only when relevant, and what is historical.
- Use `docs/DECISIONS.md` as the decision index. Read only the topical decision files relevant to the current task.
- Active implementation plans live in `docs/plans/`.
- Completed implementation plans live in `docs/archive/plans/`.
- Review remediation plans live in `docs/reviews/`.

---

## Directories to Not Search by Default

- `docs/archive/` — historical material; read only when explicitly asked or when a task depends on a specific archived file.
- `docs/reviews/` — review findings and remediation roadmaps; read the specific review file when named, not the whole directory.
- `docs/plans/` — read only the plan file relevant to the current task; do not scan the directory.

---

## Session Workflow

1. The developer starts a new chat with the task or session context.
2. The agent reads `docs/README.md`, then follows its read-first links before planning.
3. The agent asks clarification questions before planning when intent is unclear.
4. The agent writes a task plan in `docs/plans/` and waits for approval before coding.
   - `PLAN-SESSION-{N}.md` for main roadmap sessions
   - `PLAN-R-{N}-{SHORT-TITLE}.md` for review-remediation sessions
   - `PLAN-{TOPIC}.md` for one-off topics
5. The agent implements the approved plan.
6. The agent updates `docs/HANDOFF.md` (overwrite, not append) and any affected roadmap or checklist files.
7. New architectural decisions are recorded in the appropriate topical file listed in `docs/DECISIONS.md`.
8. When the session is complete, the agent moves its plan file from `docs/plans/` to `docs/archive/plans/`.

**Never write code before the plan is approved by the developer.**

---

## Session Done Checklist

Before closing an implementation session, the agent verifies:

- All tests pass: `php artisan test --compact`
- Code style is clean: `vendor/bin/pint --dirty --format agent`
- Frontend builds: `npm run build`
- `docs/HANDOFF.md` is rewritten to reflect the new state
- Affected roadmap checkboxes are updated
- New decisions are recorded in the appropriate topical file under `docs/decisions/`
- The completed plan file is moved from `docs/plans/` to `docs/archive/plans/`

Docs-only or workflow-only sessions may skip the HANDOFF update, but still move the completed plan to `docs/archive/plans/`.

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
- **File storage**: use Laravel's `Storage` facade — local in dev, Hostpoint disk in prod
