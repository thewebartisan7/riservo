# Documentation Guide

Start here before reading any other project documentation. This file is the source of truth for what future agents should read first, what to open only when relevant, and what is historical reference material.

## Read First

1. `docs/HANDOFF.md` — latest project state, current conventions, and immediate follow-up guidance
2. `docs/roadmaps/ROADMAP-PAYMENTS.md` — **active** delivery roadmap (customer-to-professional Stripe Connect Express integration; 6 sessions)
3. `docs/SPEC.md` — product scope, rules, and domain model
4. `docs/DECISIONS.md` — decision index; from there, open only the topical decision files relevant to the task
5. `docs/ARCHITECTURE-SUMMARY.md` — concise implementation-oriented summary of the current system

## Read When Relevant

- `docs/UI-CUSTOMIZATIONS.md` — required for COSS UI, theming, or frontend polish work
- `docs/DEPLOYMENT.md` — required for deployment, queue, mail, cron, or environment work
- `docs/BACKLOG.md` — unscheduled UI follow-up, UX ideas, and technical debt notes
- `docs/plans/` — active implementation plans and current planning artifacts
- `docs/reviews/` — active review round only (empty between rounds); past rounds live under `docs/archive/reviews/`
- `docs/roadmaps/ROADMAP-E2E.md` — end-to-end browser-test roadmap (independent of the payments work)
- `docs/roadmaps/ROADMAP-GROUP-BOOKINGS.md` — post-MVP planning doc for multi-customer-per-slot bookings

## Archive / Reference

- `docs/archive/plans/` — completed implementation plans kept for history and rationale
- `docs/archive/roadmaps/ROADMAP-MVP.md` — original MVP roadmap, Sessions 1–11 shipped (historical)
- `docs/archive/roadmaps/ROADMAP-MVP-COMPLETION.md` — MVP completion roadmap, MVPC-1..5 fully shipped 2026-04-17 (OAuth, Calendar Sync, Cashier billing, Provider self-service, Advanced calendar interactions)
- `docs/archive/roadmaps/ROADMAP-FEATURES.md` and `ROADMAP-CALENDAR.md` — superseded by ROADMAP-MVP-COMPLETION
- `docs/archive/reviews/` — closed review rounds and their remediation roadmaps (e.g., `REVIEW-1.md`, `ROADMAP-REVIEW-1.md`)
- `docs/design/ui.pen` — design asset reference
- `docs/decisions/DECISIONS-HISTORY.md` — superseded or resolved decision history

## Working Rules

- Record new architectural decisions in the appropriate topical file linked from `docs/DECISIONS.md`.
- Put new active implementation plans in `docs/plans/`.
- Move completed implementation plans into `docs/archive/plans/` once they are no longer part of the current working set.
- Keep the active review round in `docs/reviews/`; move closed rounds to `docs/archive/reviews/`, numbering the roadmap file on archive (`ROADMAP-REVIEW-{N}.md`).
