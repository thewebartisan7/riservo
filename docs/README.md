# Documentation Guide

Four live files drive the current state of the project. Git history is the history — nothing moves on close, nothing gets an index.

## The four live files

1. **`docs/SPEC.md`** — product scope, domain model, rules. Read by every agent working on the project.
2. **`docs/ROADMAP.md`** — the currently active roadmap. Overwritten when a new one starts; the previous content stays in git.
3. **`docs/PLAN.md`** — the currently active session plan. Overwritten at the start of every new session. Includes a `## Review` section for codex findings when a review round runs.
4. **`docs/HANDOFF.md`** — state between sessions: what last shipped, what's next, conventions the next agent must respect. Rewritten (not appended) at each session close.

## Other live docs (read when relevant)

- `docs/DEPLOYMENT.md` — Laravel Cloud / queue / cron / mail setup.
- `docs/UI-CUSTOMIZATIONS.md` — divergences from out-of-the-box COSS UI / Tailwind. Read before any theming or primitive-upgrade work.
- `docs/TESTS.md` — test command matrix.
- `docs/BACKLOG.md` — deferred features, UX ideas, tech debt. Grow only by explicit developer instruction.

## Future-work parking lot

`docs/roadmaps/` holds roadmaps that are drafted but not yet active:

- `ROADMAP-E2E.md` — ongoing Pest 4 browser coverage, ticks up with each new feature.
- `ROADMAP-GROUP-BOOKINGS.md` — post-MVP.

When a parked roadmap becomes the next delivery target, its contents replace `docs/ROADMAP.md` wholesale and the draft file gets deleted.

## Decisions

`docs/decisions/DECISIONS-*.md` hold locked architectural decisions per domain (AUTH, BOOKING-AVAILABILITY, CALENDAR-INTEGRATIONS, DASHBOARD-SETTINGS, FOUNDATIONS, FRONTEND-UI, PAYMENTS, plus HISTORY for superseded). These are reference material for **roadmap-drafting agents** — agents executing a single session do not need to read them unless the session brief explicitly points at one.

Directory listing is the index. No `DECISIONS.md` index file.

## The four-layer workflow (mental model)

1. **INTENT** — the developer says what they want.
2. **ROADMAP** — an architect agent turns intent into `docs/ROADMAP.md` (overwriting or drafting). Full project context.
3. **PLAN** — an agent reads the active roadmap plus the relevant code, and writes `docs/PLAN.md` for the current session. Session-scoped context.
4. **EXECUTE** — an agent (possibly the same one that planned) implements the plan, keeps a `## Progress` log inside `docs/PLAN.md`, runs tests, stages changes for the developer's commit. Codex review findings go into a `## Review` section inside the same `PLAN.md`.

Files used:
- Layer 2 reads `SPEC.md` + `HANDOFF.md` + `docs/decisions/` + the relevant code, writes `ROADMAP.md`.
- Layer 3 reads `SPEC.md` + `HANDOFF.md` + `ROADMAP.md` + the relevant code, writes `PLAN.md`.
- Layer 4 reads `PLAN.md` + the code, updates `PLAN.md` as it works, rewrites `HANDOFF.md` at close.

Developer briefs agents directly. No template files, no brief skills, no orchestrator.

## Git is the history

- Want the last three plans that shipped? `git log --follow --oneline docs/PLAN.md | head -3` then `git show <hash>:docs/PLAN.md`.
- Want the roadmap that preceded the current one? Same pattern on `docs/ROADMAP.md`.
- Want to know when a decision was added to `DECISIONS-PAYMENTS.md`? `git blame` on the relevant lines.

No index file replicates what git already knows.

## One discipline rule

When an implementation session ends, before overwriting `PLAN.md` at the next session: **promote any new architectural decisions into `docs/decisions/DECISIONS-{TOPIC}.md`**. Decisions that stay in `PLAN.md` only are decisions that disappear on overwrite. (Better yet, write them directly into the topical file during implementation.)
