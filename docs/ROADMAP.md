# Roadmap Index

Stable entrypoint for every delivery roadmap in this project. Each row links to a file whose `status:` frontmatter is the authoritative state — this index is a discoverability table, not an authoritative state store.

## How to use this index

- Start here. Open a roadmap only when the current task belongs to it.
- Rows are grouped into three buckets: **In flight** (anything pre-terminal: `draft | planning | active`), **Shipped** (`shipped`), and **Superseded** (`superseded`).
- When a roadmap's `status:` changes, update **both** the frontmatter and the row here. A row may move between buckets as status flips.
- Roadmap files are **not** moved when they ship. The path stays stable; only the status flips.

## In flight

| File | Status | Scope | Updated |
|---|---|---|---|
| [ROADMAP-PAYMENTS](roadmaps/ROADMAP-PAYMENTS.md) | draft | Customer-to-professional Stripe Connect Express payments, TWINT-first, zero commission (6 sessions). Currently re-reviewing before Session 1 opens. | 2026-04-18 |
| [ROADMAP-E2E](roadmaps/ROADMAP-E2E.md) | active | Pest 4 browser coverage. E2E-1..6 shipped; stays open for new features. Live status: `tests/Browser/route-coverage.md` | 2026-04-17 |
| [ROADMAP-GROUP-BOOKINGS](roadmaps/ROADMAP-GROUP-BOOKINGS.md) | planning | Multi-customer-per-slot bookings (workshops, classes). Post-MVP draft. | 2026-04-15 |

## Shipped

| File | Status | Scope | Updated |
|---|---|---|---|
| [ROADMAP-MVP-COMPLETION](roadmaps/ROADMAP-MVP-COMPLETION.md) | shipped | MVPC-1..5: OAuth, Calendar sync, Cashier billing, Provider self-service, Calendar interactions | 2026-04-17 |
| [ROADMAP-MVP](roadmaps/ROADMAP-MVP.md) | shipped | Original MVP, Sessions 1–11 (foundation, engine, frontend, auth, onboarding, booking, dashboard, settings, notifications, calendar) | 2026-04-17 |

## Superseded

| File | Status | Supersedes / SupersededBy | Updated |
|---|---|---|---|
| [ROADMAP-FEATURES](roadmaps/ROADMAP-FEATURES.md) | superseded | → ROADMAP-MVP-COMPLETION (F1, F2, F3 folded in as MVPC-1, -4, -5) | 2026-04-17 |
| [ROADMAP-CALENDAR](roadmaps/ROADMAP-CALENDAR.md) | superseded | → ROADMAP-MVP-COMPLETION (Phase 2 folded in as MVPC-2); Phase 3 Outlook/Apple still post-MVP reference | 2026-04-17 |
