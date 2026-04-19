# HUMANS.md

This file is for me, the human maintainer. It is a reminder of how I intend to work with AI agents on this project. Agents use `CLAUDE.md` / `AGENTS.md`; I use this file.

---

## Mental model

Four layers, in order:

1. **INTENT** — I say what I want.
2. **ROADMAP** — an architect agent turns intent into `docs/ROADMAP.md`. Full project context.
3. **PLAN** — an agent reads the active roadmap + the relevant code and writes `docs/PLAN.md` for one session. Session-scoped context.
4. **EXECUTE** — an agent (same or fresh) implements the plan, keeps `## Progress` alive inside `docs/PLAN.md`, runs tests, stages. I commit.

Two gates per session: plan approval, commit/push. Agents never commit.

---

## Docs system at a glance

**Four live files**:

- `docs/SPEC.md` — product scope + domain + rules.
- `docs/ROADMAP.md` — the active roadmap. Overwritten when a new one starts.
- `docs/PLAN.md` — the current session plan. Overwritten at the start of every new session. Codex review findings go in a `## Review` section inside the same file.
- `docs/HANDOFF.md` — state between sessions. Rewritten (not appended) at each close.

Plus reference material I don't touch often: `DEPLOYMENT.md`, `UI-CUSTOMIZATIONS.md`, `TESTS.md`, `BACKLOG.md`, the `docs/decisions/DECISIONS-*.md` topical files, and `docs/roadmaps/` as a parking lot for future-work roadmaps (E2E, GROUP-BOOKINGS).

**Git is the history.** No `PLANS.md` index, no `DECISIONS.md` index, no `docs/archive/`, no frontmatter-status contract, no `docs:check`. If I want a prior plan, `git log --follow docs/PLAN.md`.

---

## Session types

Three shapes. I pick one per session by how I brief the agent:

- **Roadmap session** — architect / brainstorming. Output: `docs/ROADMAP.md`. Brief cites `.claude/references/ROADMAP.md`.
- **Plan + exec session** — standard implementation. Output: `docs/PLAN.md` + staged code + final commit. Brief cites `.claude/references/PLAN.md`.
- **Quick fix session** — small change, no plan file, no ceremony. No reference file. Brief is freestyle: *"quick fix: X"*.

The references are loaded only when I cite them, so agents doing quick fixes or roadmap work don't pay the token cost of the plan+exec template.

## Plan + exec session flow (the main one)

1. I pick one session from `docs/ROADMAP.md`.
2. I brief an agent, pointing at `docs/SPEC.md`, `docs/HANDOFF.md`, the relevant roadmap section, the code files in scope, and `.claude/references/PLAN.md`.
3. The agent reads, then **writes `docs/PLAN.md`** (overwriting whatever the previous session left).
4. I review the plan. Push back until it's right.
5. On approval, the agent implements. It maintains `## Progress` and `## Decision Log` inside `docs/PLAN.md` as it works.
6. When work is staged and iteration-loop tests green, **I run codex review on the working tree** (`/codex:review` or companion script). If I run it inside the plan+exec chat itself, the agent sees the findings in the transcript directly — no paste needed. If I run it from a separate chat or terminal, I paste the findings back. Either way, the agent applies fixes on the same uncommitted diff under a `## Review` section in `PLAN.md`.
7. **I commit once at the end** with everything bundled — exec + review fixes together. The sequence is plan → approve → exec → review → commit, not plan → approve → exec → commit → review → commit.
8. At session close, the agent:
   - Rewrites `docs/HANDOFF.md` if the session changed shipped state.
   - Promotes any new `D-NNN` into `docs/decisions/DECISIONS-{TOPIC}.md` (BEFORE the next session overwrites `PLAN.md`).
   - Stages close artifacts; I commit.

## Roadmap session flow (when I'm architecting)

1. I brief an architect agent, pointing at `.claude/references/ROADMAP.md`.
2. The agent interviews me (2–5 shaping questions on scope / acceptance / out-of-scope / localisation / failure modes).
3. The agent reads `SPEC.md`, `HANDOFF.md`, the relevant topical decisions files, the code the roadmap will touch.
4. The agent either drafts `docs/ROADMAP.md` from scratch (Mode A) or produces a stress-test report on the existing `docs/ROADMAP.md` (Mode B) and revises in-session once I sign off.
5. I commit.

## Quick fix flow (when ceremony costs more than the fix)

1. I describe the fix in one sentence. No reference file cited.
2. The agent makes the change, runs affected tests + Pint, stages, reports the diff.
3. I commit.
4. If the "quick fix" turns out to touch more than ~3 files or crosses a domain boundary, the agent is supposed to stop and propose a plan + exec session.

---

## Decisions

- Live in `docs/decisions/DECISIONS-{TOPIC}.md`. One file per domain.
- Read by roadmap-drafting agents. NOT needed by plan+execute agents unless the session brief points at one.
- Directory listing is the index. No `DECISIONS.md` index file.
- Prune obsolete entries whenever I feel like it — no ceremony.

---

## BACKLOG and parked roadmaps

- `docs/BACKLOG.md` — deferred features, UX ideas, tech debt. I ask the agent to add an entry when something comes up mid-session.
- `docs/roadmaps/ROADMAP-E2E.md` — ongoing browser coverage. Ticks up with each feature.
- `docs/roadmaps/ROADMAP-GROUP-BOOKINGS.md` — post-MVP, not scheduled.

When a parked roadmap becomes the next delivery target, its body replaces `docs/ROADMAP.md` and the draft file gets deleted.

---

## The one discipline rule

Before the next session overwrites `docs/PLAN.md`, make sure any new architectural decisions have been promoted into `docs/decisions/DECISIONS-{TOPIC}.md`. Otherwise they disappear. (Better: the agent writes them straight into the topical file during implementation.)

---

## Mirrored instruction files

Codex (`AGENTS.md`) and Claude (`CLAUDE.md`) pairs must stay byte-identical in body. See `.claude/MAINTENANCE.md` for the canonical list.
