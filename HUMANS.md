# HUMANS.md

This file is for me, the human maintainer. It is a reminder of how I intend to work with AI agents on this project. Agents use `CLAUDE.md` / `AGENTS.md`; I use this file.

---

## Mental model

Work happens in three layers, in this order:

1. **WHAT** — a roadmap defines the outcomes, grouped into sessions.
2. **HOW** — an agent writes a plan for the current session before coding.
3. **CODE** — the agent implements the approved plan.

Each layer is a separate step. I never let an agent jump from a roadmap bullet straight to code.

---

## What agents already know (baseline context)

The repository instruction files (`CLAUDE.md`, `AGENTS.md`, `.claude/CLAUDE.md`, `.agents/AGENTS.md`) point every new agent session at `docs/README.md`, which classifies every doc as *Read First*, *Read When Relevant*, or *Archive/Reference*.

Because of that, the following docs are already part of the agent's loading flow. **I do not need to restate them in prompts:**

- `docs/README.md` — reading order
- `docs/HANDOFF.md` — last implementation state
- `docs/ROADMAP.md` — primary delivery roadmap
- `docs/SPEC.md` — product scope and domain model
- `docs/DECISIONS.md` — decision index
- `docs/ARCHITECTURE-SUMMARY.md` — current-state architecture digest
- `docs/UI-CUSTOMIZATIONS.md` — COSS UI / theming deviations
- `docs/DEPLOYMENT.md` — server / queue / mail / cron setup

When I brief an agent I only need to describe the *specific* task. The project context comes in for free.

---

## Roadmaps — the WHAT

Roadmaps define outcomes, **not** implementation details.

- `docs/ROADMAP.md` — the primary delivery roadmap (MVP Sessions 1–13). Content rotates as versions advance.
- `docs/reviews/ROADMAP-REVIEW.md` — prioritized remediation roadmap (R-1 through R-16) derived from `REVIEW-1.md`.
- `docs/roadmaps/` — secondary / future-oriented roadmaps (calendar integration phases, feature sessions, group bookings, etc.).

Every roadmap item is a session-sized unit of work. Agents are expected to reason about the HOW themselves at plan time.

---

## Plans — the HOW

A plan is a session-scoped implementation document.

- Active plans live in `docs/plans/`.
- Naming conventions: `PLAN-SESSION-{N}.md` for main roadmap sessions, `PLAN-R-{N}-{SHORT-TITLE}.md` for review-remediation sessions, `PLAN-{TOPIC}.md` for one-off topics.
- A plan is written **before** implementation. I review and approve it. Only then does the agent code.
- Plans list: context, goal, scope, decisions to record, step-by-step implementation, testing plan, files to create/modify, verification steps.
- When a session is complete, its plan moves from `docs/plans/` to `docs/archive/plans/`.

---

## Reviews and remediation

The pattern is identical to the product roadmap:

1. `docs/reviews/REVIEW-1.md` — the original audit. Evidence, affected files, reasoning.
2. `docs/reviews/ROADMAP-REVIEW.md` — actionable remediation roadmap. Splits the review into sessions R-1 through R-16.
3. For each session I want to run, I give an agent the R-N identifier. It reads both the remediation section and the original finding, then writes a plan at `docs/plans/PLAN-R-{N}-{SHORT-TITLE}.md`.
4. One R-N per session. No bundling.

---

## Decisions

- `docs/DECISIONS.md` is the stable index. It never bloats. It just points at topical files.
- Topical decisions live in `docs/decisions/DECISIONS-{TOPIC}.md`.
- Decision IDs (`D-001`, `D-002`, …) never get renumbered or recycled.
- Superseded / resolved decisions move to `docs/decisions/DECISIONS-HISTORY.md`.
- When an agent introduces a new architectural decision during a plan or implementation, it records it in the appropriate topical file.

---

## Archives

- `docs/archive/plans/` — completed implementation plans kept for historical rationale.
- `docs/archive/CLAUDE.md` / `AGENTS.md` — a guardrail telling agents **not** to read archives by default.
- The root-level "do not search by default" rule in `.claude/CLAUDE.md` reinforces this.

If I want an agent to use an archived plan, I reference it explicitly.

---

## Top-level state docs

These three capture *current state* that shifts between sessions:

- `docs/HANDOFF.md` — what the last implementation session produced; the next session's starting point. **Implementation-only**: docs-cleanup sessions do not touch this file.
- `docs/DEPLOYMENT.md` — operational information agents add for me during implementation. I use it when I deploy.
- `docs/BACKLOG.md` — unscheduled follow-up, UX ideas, deferred engineering cleanup. Informal; items graduate to the roadmap when I decide to schedule them.

---

## What agents read by default

Auto-loaded every session (via `CLAUDE.md` / `AGENTS.md`):

- Root `CLAUDE.md` / `.claude/CLAUDE.md` — Laravel Boost guidelines + scoped project rules.
- Any scoped `CLAUDE.md` in the path they're working in (frontend rules, UI primitives rules).

Read early in a session (via the `docs/README.md` reading order):

- `docs/README.md`, `docs/HANDOFF.md`, `docs/ROADMAP.md`, `docs/SPEC.md`, `docs/DECISIONS.md`, `docs/ARCHITECTURE-SUMMARY.md`.

Read when task-relevant:

- `docs/UI-CUSTOMIZATIONS.md`, `docs/DEPLOYMENT.md`, `docs/BACKLOG.md`, a specific file under `docs/plans/` or `docs/roadmaps/` or `docs/reviews/`.

---

## What agents avoid by default

Scoped guardrails and a root-level rule tell agents not to bulk-read:

- `docs/archive/` — historical material.
- `docs/reviews/` — open the specific review file named by the current task, not the whole directory.
- `docs/plans/` — open only the plan file relevant to the current task.

If I need an agent to dig into one of these, I say so explicitly.

---

## Mirrored instruction files

Codex (`AGENTS.md`) and Claude (`CLAUDE.md`) pairs must stay identical. The canonical list of pairs and the ownership split between root and scoped files lives in `.claude/MAINTENANCE.md`. I keep this out of every session's loaded context so it doesn't burn tokens.

---

## Session flow (how I actually work)

1. I pick one unit of work: either a roadmap session, an `R-N` review session, or a specific task.
2. I open a new chat and paste a short prompt that names the identifier (e.g. `R-1 — Admin as Provider`). Baseline context loads on its own.
3. The agent reads the relevant section and the current code, then writes a plan to `docs/plans/PLAN-*.md`.
4. I review the plan. I push back until it's right.
5. The agent implements the approved plan.
6. The agent updates `docs/HANDOFF.md`, any affected roadmap checkboxes, and records new decisions in the appropriate topical file.
7. When the session is complete, the plan moves to `docs/archive/plans/`.

---

## Possible future improvements (not committed)

These are ideas I'm considering. They are *not* yet part of the workflow.

- **`docs/baseline/` for foundational docs.** Move `SPEC.md`, `ARCHITECTURE-SUMMARY.md`, `UI-CUSTOMIZATIONS.md` into a `baseline/` subdirectory to make their "foundational" status explicit through path, not just through `README.md` categorization.
- **`docs/roadmaps/` consolidation.** Move `docs/ROADMAP.md` into `docs/roadmaps/` alongside the secondary roadmaps, either keeping `docs/ROADMAP.md` at the root as a stable pointer that rotates, or versioning them as `ROADMAP-MVP.md`, `ROADMAP-V2.md`.
- **Trim the root of `docs/`.** Keep only `README.md`, `HANDOFF.md`, `DEPLOYMENT.md`, `BACKLOG.md` at the docs root; push everything else into subdirectories.
- **`docs/decisions/README.md`.** Move the decision index inside `docs/decisions/` so the directory is self-contained.
- **Archive `REVIEW-1.md` once remediation is done.** When all `R-*` sessions land, move `REVIEW-1.md` into `docs/archive/reviews/` and keep only `ROADMAP-REVIEW.md` or its replacement.

I'll revisit these once the current review-remediation roadmap is well underway.
