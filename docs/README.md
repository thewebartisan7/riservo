# Documentation Guide

Start here before reading any other project documentation. This file is the source of truth for what future agents should read first, what to open only when relevant, and what is historical reference material.

## Read First

1. `docs/HANDOFF.md` — latest project state, current conventions, and immediate follow-up guidance
2. `docs/roadmaps/ROADMAP-MVP-COMPLETION.md` — **active** delivery roadmap (Sessions 1–5 closing the MVP plus immediate post-MVP polish)
3. `docs/ROADMAP.md` — historical MVP roadmap (Sessions 1–11 shipped; 12–13 superseded by the active roadmap above)
4. `docs/SPEC.md` — product scope, rules, and domain model
5. `docs/DECISIONS.md` — decision index; from there, open only the topical decision files relevant to the task
6. `docs/ARCHITECTURE-SUMMARY.md` — concise implementation-oriented summary of the current system

## Read When Relevant

- `docs/UI-CUSTOMIZATIONS.md` — required for COSS UI, theming, or frontend polish work
- `docs/DEPLOYMENT.md` — required for deployment, queue, mail, cron, or environment work
- `docs/BACKLOG.md` — unscheduled UI follow-up, UX ideas, and technical debt notes
- `docs/plans/` — active implementation plans and current planning artifacts
- `docs/reviews/` — active review round only (empty between rounds); past rounds live under `docs/archive/reviews/`
- `docs/roadmaps/` — secondary or future-oriented roadmap documents (notably `ROADMAP-PAYMENTS.md` for the customer-to-professional Stripe Connect work)

## Archive / Reference

- `docs/archive/plans/` — completed implementation plans kept for history and rationale
- `docs/archive/reviews/` — closed review rounds and their remediation roadmaps (e.g., `REVIEW-1.md`, `ROADMAP-REVIEW-1.md`)
- `docs/design/ui.pen` — design asset reference
- `docs/decisions/DECISIONS-HISTORY.md` — superseded or resolved decision history

## Working Rules

- Record new architectural decisions in the appropriate topical file linked from `docs/DECISIONS.md`.
- Put new active implementation plans in `docs/plans/`.
- Move completed implementation plans into `docs/archive/plans/` once they are no longer part of the current working set.
- Keep the active review round in `docs/reviews/`; move closed rounds to `docs/archive/reviews/`, numbering the roadmap file on archive (`ROADMAP-REVIEW-{N}.md`).
