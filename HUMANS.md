# HUMANS.md

This file is for me, the human maintainer. It is a reminder of how I intend to work with AI agents on this project. Agents use `CLAUDE.md` / `AGENTS.md`; I use this file.

---

## Mental model

The canonical workflow has four layers (see `docs/README.md` § "The four-layer work flow"):

1. **INTENT** — I say what I want.
2. **ROADMAP** — an architect agent turns intent into a roadmap.
3. **ORCHESTRATION** — an orchestrator agent coordinates the roadmap's sessions.
4. **IMPLEMENTATION** — a per-session implementer agent plans, codes, and closes.

For quick mental bookkeeping I sometimes compress these into three: **WHAT** (layers 1–2: roadmap defines outcomes), **HOW** (layer 4: the plan before code), **CODE** (the implementation). Same pipeline, fewer labels.

Each layer is a separate step. I never let an agent jump from a roadmap bullet straight to code.

---

## Docs system at a glance

Three indices sit at `docs/`:

- `docs/ROADMAP.md` — every delivery roadmap, bucketed by status.
- `docs/PLANS.md` — every session plan, bucketed by status.
- `docs/DECISIONS.md` — every architectural decision topic, pointing at topical files.

Every roadmap / plan / review / handoff file has a YAML frontmatter block at the top. The authoritative lifecycle state is its `status:` field: `draft | planning | active | review | shipped | superseded | abandoned`. See `docs/README.md` § "Status taxonomy" for which statuses apply to which type.

**Files do not move when they ship.** `docs/archive/` does not exist any more. A plan stays in `docs/plans/` forever; only its `status:` flips as the session progresses.

---

## What agents already know (baseline context)

The repository instruction files (`CLAUDE.md`, `AGENTS.md`, `.claude/CLAUDE.md`, `.agents/AGENTS.md`) point every new agent session at `docs/README.md`, which describes the indices, status taxonomy, and reading order.

Because of that, the following docs are already part of the agent's loading flow. **I do not need to restate them in prompts:**

- `docs/README.md` — reading order, frontmatter convention, status taxonomy
- `docs/HANDOFF.md` — last implementation state
- `docs/ROADMAP.md` — roadmap index
- `docs/PLANS.md` — plan index
- `docs/SPEC.md` — product scope and domain model
- `docs/DECISIONS.md` — decision index
- `docs/ARCHITECTURE-SUMMARY.md` — current-state architecture digest
- `docs/UI-CUSTOMIZATIONS.md` — COSS UI / theming deviations
- `docs/DEPLOYMENT.md` — server / queue / mail / cron setup

When I brief an agent I only need to describe the *specific* task. The project context comes in for free.

---

## Roadmaps — the WHAT

Roadmaps define outcomes, **not** implementation details.

- `docs/roadmaps/` — all roadmaps, active or shipped or superseded. Status lives in frontmatter.
- `docs/ROADMAP.md` — the index across them.

Every roadmap item is a session-sized unit of work. Agents are expected to reason about the HOW themselves at plan time.

---

## Plans — the HOW

A plan is a session-scoped implementation document.

- Plans live in `docs/plans/`. They stay there forever.
- Naming conventions:
  - `PLAN-SESSION-{N}.md` for main roadmap sessions
  - `PLAN-R-{N}-{SHORT-TITLE}.md` for review-remediation sessions
  - `PLAN-PAYMENTS-{N}-{TITLE}.md` for roadmap-scoped sessions (e.g. PAYMENTS)
  - `PLAN-{TOPIC}.md` for one-off topics
- A plan is written **before** implementation. I review and approve it. Only then does the agent code.
- Plans list: context, goal, scope, decisions to record, step-by-step implementation, testing plan, files to create/modify, verification steps.
- As the session progresses, the plan grows with five living sections: `## Progress`, `## Implementation log`, `## Surprises & discoveries`, `## Post-implementation review`, `## Close note / retrospective`. Which are mandatory depends on session type — see `docs/README.md` § "Session lifecycle in a plan file".
- When the session ships, the plan's `status:` flips to `shipped`. The file stays put.

---

## Reviews

Per-session codex reviews live **inside the plan file** under `## Post-implementation review`. That is where the durable trace of bugs-caught-by-codex lives.

`docs/reviews/` is reserved for cross-cutting audits (security review, accessibility audit, full-suite perf audit, compliance sweep). The closed round-based reviews (REVIEW-1, REVIEW-2) stay there with `status: shipped` for historical reference.

---

## Decisions

- `docs/DECISIONS.md` is the stable index. It never bloats — it just points at topical files.
- Topical decisions live in `docs/decisions/DECISIONS-{TOPIC}.md`.
- Decision IDs (`D-001`, `D-002`, …) never get renumbered or recycled.
- Superseded / resolved decisions move to `docs/decisions/DECISIONS-HISTORY.md`.
- When an agent introduces a new architectural decision during a plan or implementation, it records it in the appropriate topical file.

---

## Top-level state docs

These three capture *current state* that shifts between sessions:

- `docs/HANDOFF.md` — what the last implementation session produced; the next session's starting point. Rewritten fresh (overwrite, not append) when a session changes shipped product / runtime state or changes the workflow / canonical reading order. Docs-only sessions that do neither may skip HANDOFF. If HANDOFF exceeds 200 lines, split carry-overs into `docs/BACKLOG.md`.
- `docs/DEPLOYMENT.md` — operational information agents add for me during implementation. I use it when I deploy.
- `docs/BACKLOG.md` — unscheduled follow-up, UX ideas, deferred engineering cleanup. Informal; items graduate to the roadmap when I decide to schedule them.

---

## Session flow (how I actually work)

1. I pick one unit of work: a roadmap session, an `R-N` review session, or a specific task.
2. I open a new chat and paste a short prompt that names the identifier (e.g. `PAYMENTS-1 — Stripe Connect Express Onboarding`). Baseline context loads on its own.
3. The agent reads the relevant section and the current code, then writes a plan at `docs/plans/PLAN-*.md` with `status: draft` or `planning`.
4. I review the plan. I push back until it's right.
5. On approval, the plan's `status:` flips to `active` and the agent implements.
6. The agent updates `docs/HANDOFF.md`, any affected roadmap checkboxes, the plan's `## Implementation log`, and records new decisions in the appropriate topical file.
7. When the session is complete, the plan's `status:` flips to `shipped` (or `review` if codex is still running). The file stays in place.
8. The matching row in `docs/PLANS.md` (or `docs/ROADMAP.md`) is updated to reflect the new status.

---

## Mirrored instruction files

Codex (`AGENTS.md`) and Claude (`CLAUDE.md`) pairs must stay identical. The canonical list of pairs and the ownership split between root and scoped files lives in `.claude/MAINTENANCE.md`.

---

## Possible future improvements (not committed)

- **Light `scripts/docs-check.sh`** — a 30-line bash sanity check that greps frontmatter and cross-references the indices. Add only if drift shows up in practice.
- **Hybrid GitHub Issues + Milestones** — for bug tracking post-launch and release-tagging respectively. Evaluated separately; not in scope of the current docs system.
- **`docs/baseline/` for foundational docs.** Move `SPEC.md`, `ARCHITECTURE-SUMMARY.md`, `UI-CUSTOMIZATIONS.md` into a `baseline/` subdirectory to make their "foundational" status explicit through path, not just through `README.md` categorization.
- **`docs/decisions/README.md`.** Move the decision index inside `docs/decisions/` so the directory is self-contained.
