# Writing / revising the active roadmap — `docs/ROADMAP.md`

This document specifies the shape of a **roadmap session** for riservo.ch. A roadmap session produces or revises `docs/ROADMAP.md` — the single file that holds the currently active delivery roadmap. Git history preserves every prior roadmap.

The developer triggers a roadmap session by briefing an architect / analyst agent with something like *"Let's plan the next roadmap — new topic is X"* (fresh draft) or *"Review `docs/ROADMAP.md` and stress-test it"* (revising an existing draft). The agent reads this reference, interviews the developer, and writes or revises `docs/ROADMAP.md`.

Roadmap sessions produce **no code, no session plan, no commits**. The artifact is the roadmap markdown. The developer commits when satisfied.

## How to use this reference

When authoring or revising a roadmap, follow this document to the letter. If it is not in your context, refresh by reading the whole file. Read broadly — `docs/SPEC.md`, `docs/HANDOFF.md`, the relevant `docs/decisions/DECISIONS-*.md` files, the code the roadmap will touch. Roadmap-drafting agents have the widest context in the workflow; use it.

**Interview, don't guess.** Roadmap work is the one layer where you must ask the developer clarifying questions up front. Scope boundaries, acceptance bars, commercial model, failure-mode tolerance, deployment constraints, localisation, what's explicitly out of scope — these are product / policy calls. Never silently guess on them. Ask 2–5 shaping questions before drafting, then iterate.

**You never write application code.** No controllers, no migrations, no React components, no tests. The roadmap describes WHAT, not HOW. The per-session implementer (see `.claude/references/PLAN.md`) decides the HOW at plan time.

**You never write a session plan.** Session plans live at `docs/PLAN.md` and are written when a session starts against your roadmap. That is not your job.

**You never commit.** The developer commits the roadmap when satisfied. There is no agent-commits carve-out.

## Non-negotiable requirements

- The roadmap must be **self-contained** for a future planning agent. A planning agent handed `SPEC.md + HANDOFF.md + ROADMAP.md + the relevant DECISIONS-*.md topical files` must be able to draft Session 1 of the roadmap without any other context.
- Every session in the roadmap must be a **focused, reviewable unit**. Sessions that span multiple independent surface areas should be split.
- Every session must have a **single clear owner** (one agent) and a **verifiable acceptance criterion** (either a user-visible behavior or a tests-pass gate).
- Every non-obvious architectural choice that binds more than one session must be **locked as a numbered decision** in the Cross-cutting Decisions block. Locked means: "implementing agents must not re-open this; only the developer can override, via a new decision that supersedes the old one."
- Every term of art must be **defined in plain language** on first use.

## Two operating modes

The developer tells you which mode in the brief; if ambiguous, ask.

### Mode A — Draft from intent

The developer has a new idea ("I want to add X") and no prior roadmap file. You:

1. Read `SPEC.md`, `HANDOFF.md`, the relevant topical decision files, and the code the roadmap will touch.
2. Interview the developer: scope boundary, acceptance bar, commercial model if relevant, failure-mode tolerance, out-of-scope items, localisation if user-facing.
3. Draft `docs/ROADMAP.md` from scratch, overwriting whatever's there (git preserves the prior roadmap).
4. Share the draft with the developer for review. Iterate until satisfied.
5. Developer commits when ready.

### Mode B — Stress-test and revise

The developer has `docs/ROADMAP.md` on disk and wants it hardened before Session 1 opens. You:

1. Read the existing `docs/ROADMAP.md` in full.
2. Read `SPEC.md`, `HANDOFF.md`, the relevant topical decision files, and the code.
3. Produce a **stress-test report** — text only, no file writes yet. Format below.
4. The developer decides which concerns to act on. You iterate with them in conversation, proposing concrete edits in text.
5. When the developer explicitly signs off ("approved, apply them"), you apply the agreed edits to `docs/ROADMAP.md` in-session.
6. Developer commits.

## Read first (in this order)

Project grounding — always:

1. `/Users/mir/Projects/riservo/CLAUDE.md` and `/Users/mir/Projects/riservo/.claude/CLAUDE.md` — project conventions, critical rules.
2. `docs/README.md` — doc map.
3. `docs/HANDOFF.md` — current project state, last shipped sessions, next free `D-NNN`.
4. `docs/SPEC.md` — product scope, rules, domain model.
5. `docs/ROADMAP.md` — the current active roadmap (if any — the file is overwritten per roadmap, so you either revise it or replace it).
6. The relevant `docs/decisions/DECISIONS-*.md` topical files for the domain the roadmap will touch. Directory listing: `DECISIONS-AUTH.md`, `DECISIONS-BOOKING-AVAILABILITY.md`, `DECISIONS-CALENDAR-INTEGRATIONS.md`, `DECISIONS-DASHBOARD-SETTINGS.md`, `DECISIONS-FOUNDATIONS.md`, `DECISIONS-FRONTEND-UI.md`, `DECISIONS-HISTORY.md`, `DECISIONS-PAYMENTS.md`.
7. Code grounding — the models, controllers, components, migrations the roadmap will touch. Name them in the brief or ask the developer which files are in scope.

Do not reconstruct context from training data or memory. If something is unclear, ask.

## Generic probing checklist — apply to every roadmap

Walk through these before finalising the roadmap. Findings become either locked decisions or flagged open questions.

- **Concurrency & races.** What happens when two users act on the same entity within milliseconds? Do existing DB locks and constraints (GIST on `bookings` per D-065 / D-066, unique indexes, composite keys) still hold under the new flow?
- **External service failure.** External APIs (Stripe, Google Calendar, mailer, queue driver) down, slow, or flaky. Webhooks that never arrive, arrive twice, arrive out of order. Our handler crashes mid-mutation. How does the roadmap's flow degrade?
- **Data-lifecycle edges.** Soft-deleted rows, deactivated records, records still referenced by external data, records whose owning parent has been removed.
- **Tenant isolation.** Every query on business-owned data scoped via `business_id` (D-063). Any cross-tenant path this roadmap introduces must be called out explicitly.
- **Existing invariants this roadmap must honour.** Common trip-wires: D-031, D-051, D-063, D-065, D-066, D-067, D-083, D-088, D-090, D-095. If the roadmap conflicts with any locked decision, surface it — do not silently re-open.
- **Auth boundaries.** Admin vs. staff vs. provider vs. customer (D-081, D-096). Which roles can take which actions in the new flow?
- **Test seams.** Does the roadmap extend existing mocking patterns (e.g., the `StripeClient` container binding per D-095, the `FakeStripeClient` helper), or introduce a new seam? Is the contract clear enough that implementing agents will get it right?
- **Localisation.** Public surfaces are multi-lang (IT / DE / FR / EN per D-008). Any hosted-third-party page (Stripe Checkout, Google OAuth) needs the `locale` parameter. Any new user-facing string must use `__()`.
- **Docs completeness.** Will the roadmap's closing session update `SPEC.md`, seed `BACKLOG.md` with carry-overs, and add decisions to the matching topical `DECISIONS-*.md` file?

## Output shape — `docs/ROADMAP.md`

Write as plain markdown. No YAML frontmatter. Header + prose + table for the session summary + sections per session. Follow this order:

```markdown
# riservo.ch — <Topic> Roadmap

> Status: draft | planning | active (one-line human-readable status — no ceremony).
> Scope: <one-line scope — what's in, what's out>.
> Format: WHAT only. HOW is decided per-session in `docs/PLAN.md`.
> Each session is a focused, reviewable unit handed to a single agent. Sessions run strictly sequentially; prerequisites are listed per-session below.

---

## Overview

<One or two paragraphs: the problem this roadmap solves, the user-visible outcome, why now, how it relates to what's already shipped.>

| # | Session | Prerequisites | Outcome |
|---|---------|---------------|---------|
| 1 | <Title> | <deps> | <one-line user-visible outcome> |
| 2 | <Title> | <deps> | … |
| … | … | … | … |

<Optional: a paragraph on alternatives considered and rejected before this roadmap was settled. Brief — one or two sentences per alternative, no essay.>

---

## Cross-cutting decisions locked in this roadmap

<Numbered list of product / architectural decisions binding every session. Each item: one sentence of decision, one short paragraph of rationale. These are locked — implementing agents must not re-open them.>

1. **<Decision name>**. <Decision in one sentence.> <Rationale in one short paragraph.> Locked.
2. **<Decision name>**. <...> Locked.
…

---

## Session 1 — <Title>

**Owner**: single agent. **Prerequisites**: <what must ship first>. **Unblocks**: <Session N>.

<One paragraph on the deliverable: what ships, what the user can do after, what's deliberately deferred.>

### <Subsection per surface area touched — e.g., "Data layer", "Controllers and routes", "Webhook reception", "Dashboard UI", "Tests + ops">

- [ ] <Concrete deliverable — file path, function name, or migration name where possible.>
- [ ] <...>

**Out of scope**: <explicitly listed non-deliverables that a future reader might wrongly assume are in scope>.

---

## Session 2 — <Title>

<Same shape.>

…

---

## Cleanup tasks (closing session)

<Doc updates the closing session must perform: SPEC sections to touch, BACKLOG entries to seed, DECISIONS-*.md topical file growth. Keep it minimal — the closing session's primary job is shipping the final feature, not bureaucracy.>

---

## Open questions for the developer

<Anything you couldn't resolve without a product / policy call. One question per item, one line each.>

---

*This roadmap defines the WHAT. The HOW is decided per session by the implementing agent in `docs/PLAN.md` (one live file, overwritten each session). Each session leaves the iteration-loop tests green, Pint clean, Wayfinder regenerated, and the Vite build clean before the developer commits.*
```

## Mode B — Stress-test report format

Text only, no file writes on the stress-test turn.

```
# Stress-test report on `docs/ROADMAP.md`

## Strengths
(3–5 bullets, no over-validation.)

## Critical gaps (must address before approving)
Decisions or scenarios that would cause a real bug or product failure if shipped as-is. For each: cite the section and the relevant line, and propose a concrete change.

## Significant concerns (worth a discussion before approval)
Decisions that are defensible but where an alternative might be better; ambiguities; under-specified areas. Same citation + proposal format.

## Minor refinements (optional polish)
Nice-to-haves that do not block approval.

## Questions for the developer
Things you cannot resolve from the docs or the code alone.
```

Share the report with the developer, discuss, iterate. When the developer explicitly signs off ("approved, apply them"), apply the agreed edits to `docs/ROADMAP.md` in the same session.

## Quality bar

- **Be concrete.** For every concern, name the section, quote the relevant line, propose a specific change. Vague "you might want to consider…" is not useful.
- **Be honest.** If the roadmap is fundamentally sound and concerns are minor, say so. If you find a deal-breaker, say so clearly.
- **Solve the problem, not move paperwork.** A roadmap whose sessions are cleanly scoped, whose cross-cutting decisions are locked, and whose handoff to an implementing agent is obvious is worth more than one with every theoretical concern footnoted.
- **Respect locked prior decisions.** If a locked `D-NNN` in a topical file conflicts with something you want to propose, surface the conflict — do not silently re-open it. The developer is the only override authority.
- **Stay in English.** Roadmap bodies and stress-test reports are in English (matches the rest of the project's docs).

## Rules of engagement

- You never write application code. No controllers, migrations, React components, tests.
- You never write a session plan. That's the implementer's job against `docs/PLAN.md`.
- You never spawn other agents.
- You interview; you do not assume. Product / policy / scope calls surface as clarifying questions or open-questions, not silent guesses.
- You respect locked prior decisions. If a locked decision conflicts with something you want to propose, surface the conflict.
- You never commit or push. The developer commits when the roadmap is ready.

## Handoff

When the developer is satisfied with the roadmap, the session ends. The developer commits. The next step is a plan+exec session against Session 1 of the new roadmap, which the developer triggers in a fresh chat, pointing at `.claude/references/PLAN.md`.
