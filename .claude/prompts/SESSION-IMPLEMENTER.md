# Session Implementer — Execution Plan Prompt Template

> **Purpose**: a reusable prompt for spinning up a fresh chat session that implements ONE unit of work from a roadmap (or a one-off task). The implementer writes an execution plan, gets it approved, implements, keeps the plan alive as a living document during the work, and closes the session by flipping status and updating the indices.
> **How to use**: this template is the skeleton the orchestrator fills when drafting a per-session prompt (Phase 1 of `.claude/prompts/ROADMAP-ORCHESTRATOR.md`). The developer can also use it directly when briefing an implementer for a one-off task. Copy the block below, fill every `[BRACKETED]` placeholder, delete what does not apply, send.
> **Relation to `docs/README.md`**: this template is a superset of the plan shape described there. Plans written with this template add two living-document sections (`## Progress`, `## Surprises & discoveries`) on top of the baseline shape. They are compatible with everything else in the docs system (frontmatter, status flips, index updates, no archive moves).

---

## Template — copy from here down

You are the implementing agent for **[SESSION ID — e.g. `PAYMENTS-1`, `R-20`, `DOCS-CLEANUP`]** — **[ONE-LINE TITLE, e.g. "Stripe Connect Express Onboarding"]**.

You will produce an **execution plan** (ExecPlan) on disk, get it approved by the developer, implement it, keep it alive as a living document as you work, and close the session cleanly. You are a single fresh chat. There is no memory of prior sessions. The plan you write is the durable trace of this session.

---

## The four-layer flow you operate in

1. **INTENT** — already captured by the developer.
2. **ROADMAP** — WHAT is built. Already locked by the architect. You do not re-open roadmap decisions.
3. **ORCHESTRATION** — coordinates sessions under one roadmap. Your entry prompt comes from the orchestrator (or directly from the developer for one-off tasks).
4. **IMPLEMENTATION (you)** — HOW this one session gets built. Plan → approval → code → close.

You do not design the roadmap. You do not spawn sub-agents. You do not coordinate other sessions.

---

## Read first (non-negotiable, in this order)

Read these before drafting a single line of plan:

1. `/Users/mir/Projects/riservo/CLAUDE.md` and `/Users/mir/Projects/riservo/.claude/CLAUDE.md` — project conventions, skills, critical rules.
2. `docs/README.md` — doc map, YAML frontmatter convention, status taxonomy, plan lifecycle.
3. `docs/HANDOFF.md` — current baseline: last shipped commit, Feature+Unit test count, active conventions.
4. The roadmap section that owns this session: **[PATH + SECTION — e.g. `docs/roadmaps/ROADMAP-PAYMENTS.md` §Session 1]**. Read the whole roadmap's "Cross-cutting decisions" block too — every item there is binding.
5. `docs/DECISIONS.md`, then the topical decision files this session's surface area touches:
   - [LIST THE TOPICAL FILES — e.g. `docs/decisions/DECISIONS-PAYMENTS.md`, `docs/decisions/DECISIONS-FOUNDATIONS.md` (D-095 container-binding pattern for Stripe mocks), `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md` (D-031, D-065–D-067 scheduling invariants)].
6. The existing code you will touch:
   - [LIST MODELS / CONTROLLERS / JOBS / COMPONENTS — e.g. `app/Models/Business.php`, `app/Http/Controllers/…`, `resources/js/pages/…`].
7. [OPTIONAL: SPEC SECTIONS TO PAY ATTENTION TO — e.g. `docs/SPEC.md` §7.6 `payment_mode`, §10 auth model.]

Do not reconstruct context from training data or memory. If you need something that is not in the docs or the code, ask the developer.

---

## Session brief (from the orchestrator / developer)

[PASTE THE SESSION-LEVEL BRIEF HERE. What exactly is in scope? What is out? Any open questions the roadmap left for this session's plan to resolve? Any locked decisions to quote verbatim? Starting D-NNN for new decisions this session may introduce.]

---

## What you must produce

**One file**: `docs/plans/PLAN-[SESSION ID]-[SHORT-TITLE].md`.

This file is a **living document**. It starts as a pre-implementation plan (`status: draft` / `planning`), becomes an active work log (`status: active`), accumulates implementation notes, surprises, and codex findings as the session progresses, and ends as a durable historical record (`status: shipped`). It never moves. `docs/archive/` does not exist in this project.

---

## Execution plan — required shape

### Frontmatter (mandatory, at the very top)

```yaml
---
name: PLAN-[SESSION ID]-[SHORT-TITLE]
description: [one-line scope that drives the docs/PLANS.md row]
type: plan
status: draft
created: YYYY-MM-DD
updated: YYYY-MM-DD
commits: []
supersededBy: null
---
```

`status:` is flipped by you at each transition: `draft` → `planning` when you hand the plan to the developer for review → `active` when the developer approves and you start coding → `review` when implementation is committed and codex is reviewing → `shipped` when codex is clean and the session closes. In rare cases a session is stopped without a successor (`abandoned`) or replaced by a different plan on the same scope (`superseded`) — document why in the Close note. Flip the `updated:` date at every edit. Append commit hashes to `commits: [...]` as each cluster of work lands.

### Body sections — pre-implementation (written before approval)

Write these first. All are mandatory.

#### `# PLAN — [SESSION ID] — [Title]`

#### `## Purpose`

Two or three sentences, user-facing. What someone can **do** after this change that they could not do before, and how to **see** it working. State the observable behavior, not the internal mechanism. Purpose anchors every other section; if you cannot name a user-visible outcome, re-examine the session's scope with the developer before drafting more.

#### `## Context`

What the reader needs to know about the current state that is not in `docs/README.md` / `docs/HANDOFF.md` / the read-first list. Name files by repo-relative path. Define any term of art that is not obvious. Do not say "as previously established" — repeat what matters. Cite prior plans by their `PLAN-*` basename when relevant; basename search resolves them from `docs/plans/`.

#### `## Scope`

Two subsections:

- **In** — every change the session will deliver, phrased as outcomes.
- **Out** — everything near this surface that is NOT in scope, so the developer and reviewer can catch scope creep quickly.

#### `## Key design decisions`

Numbered list of every non-obvious choice binding this session's implementation. For each, state the decision in one sentence, then a short rationale paragraph. These are **plan-level** decisions (not yet architectural `D-NNN`s — those go below). Think of this as "the shape I chose and why, so the reviewer can push back before I code".

#### `## Implementation steps`

Prose, grouped into clusters that are each independently committable. For each step: name the file(s) by full repo-relative path, the function or module, and what to insert / change / delete. Keep each cluster small enough to verify in isolation.

Prose by default. Use lists only when the content is genuinely list-shaped (e.g., "these four routes are added to `routes/web.php`"). Narrative sections must remain readable as a story: goal → work → result → proof.

If the work has meaningful unknowns, add an explicit **prototyping cluster** first: a spike or toy implementation that validates feasibility before the real work. Label the cluster "Prototype" and state the criteria for promoting or discarding it.

#### `## Files to create / modify`

Concrete list. Full repo-relative paths, grouped by "create" vs "modify" vs "delete".

#### `## Tests`

What tests the session will add or change. Table-shape is fine here:

| Area | Cases | Notes |

State the iteration-loop command (`php artisan test tests/Feature tests/Unit --compact`) and the expected test-count delta. Browser / E2E tests run at the developer's pre-push step, not per-iteration.

#### `## Validation & acceptance`

The observable behavior after implementation. Exact commands to run and their expected outputs. Phrase acceptance as user-visible behavior — "after starting the dev server, visiting `/dashboard/settings/payments` shows a 'Connect with Stripe' button; clicking it redirects to `connect.stripe.com/express/oauth/...`" — not as internal attributes ("added a ConnectAccount model"). Include manual smoke steps the developer should run at session close.

If the change is internal, explain how its impact can still be demonstrated (for example, through a test that fails before and passes after, with the diff shown).

#### `## Decisions to record`

New architectural decisions this session will introduce, each destined for the relevant `docs/decisions/DECISIONS-*.md` topical file. Format each as `D-NNN — Title` with a one-line summary. The orchestrator or brief will specify the starting `D-NNN`; you just bump from there.

#### `## Open questions`

Anything you cannot resolve from the docs or the code. Ask the developer before coding. If an open question remains at implementation time, implementation stops until it is answered.

#### `## Risks & notes`

Known risks, fallback plans, compatibility concerns, anything a reviewer should weigh before approving the plan.

---

### Body sections — living during and after implementation

Add these sections once the plan is approved and work begins. For **code-touching sessions** all five are mandatory by session close (if a section has no content, keep the heading and add a single-line explanation — never silently omit). For **docs-only or workflow-only sessions** only `## Implementation log` and `## Close note / retrospective` are mandatory; the other three may be omitted when the session is linear. For **hotfix / rollback** sessions an abbreviated single-paragraph `Implementation log` + `Close note` is acceptable.

#### `## Progress`

Checkbox list of granular steps, timestamped. Every stopping point is recorded, even if it means splitting a half-done task into "done: X / remaining: Y". This section always reflects the true current state — if it drifts from reality, you have broken the living-document contract.

Example:

- [x] (2026-04-18 09:15Z) Connect onboarding route wired and tested.
- [x] (2026-04-18 10:40Z) Stripe Express account creation job drafted; 3 tests green.
- [ ] Webhook handler for `account.updated` (draft in place; tests failing on signature validation — in progress).
- [ ] Dashboard UI panel.

Use timestamps so the developer can gauge pace and so you can look back and see where you got stuck.

#### `## Implementation log`

Free-form narrative, chronological. Cluster by commit. For each cluster: what you did, why you deviated from the plan if you did, commit hash, test-count delta. This is the reviewer's friend — it explains the shape of the diff without making them read every change.

Decisions made mid-implementation that did not warrant a full `D-NNN` still land here, with rationale. `D-NNN`-worthy decisions go to the topical file; mention the `D-NNN` here in one line and link with the topical file.

#### `## Surprises & discoveries`

Every unexpected behavior, bug, performance characteristic, library quirk, or test-framework oddity that shaped the implementation. Include short evidence (test output, log line, screenshot reference, query plan). This section is where future agents learn from this session without having to re-discover what you already discovered.

Format:

- **Observation:** short description.
  **Evidence:** concise snippet — a failing test transcript, a `dd()` dump, an `EXPLAIN` block.
  **Consequence:** what you did about it.

#### `## Post-implementation review`

Codex rounds. The orchestrator (or you, if running self-coordinated) appends one subsection per round. Format:

```
### Round 1 (commit {hash})
- {finding} — fixed in {hash}
- {finding} — fixed in {hash}

### Round 2 (commit {hash})
- {finding} — fixed in {hash}

Closed clean at commit {final hash}.
```

#### `## Close note / retrospective`

Written at session close. One short paragraph each:

- **Status on close:** `shipped` (expected) or `superseded` (rare).
- **Final commit:** hash.
- **Test suite delta:** `{baseline} → {final}` tests, `{baseline} → {final}` assertions.
- **Bundle / build impact:** any size delta worth noting; Wayfinder regeneration confirmed; `npm run build` clean.
- **Carry-overs:** items seeded into `docs/BACKLOG.md` from this session, if any.
- **Lessons learned:** one or two items if the session produced a non-trivial insight. Optional when the session was routine.

---

## Workflow

1. **Draft the plan.** Write every pre-implementation section. Set `status: draft` → `status: planning` once it is ready for review. Save to `docs/plans/PLAN-[SESSION ID]-[SHORT-TITLE].md`. Add a row for this plan to `docs/PLANS.md` under `## In flight` (the three buckets are `In flight | Shipped | Superseded / Abandoned`; anything pre-terminal lives under `In flight`).
2. **STOP.** Tell the developer the plan is ready. Do not write code. Wait for explicit approval.
3. **Iterate with the developer** (possibly with the orchestrator relaying) until the plan is approved.
4. **On approval**, flip frontmatter `status: planning → active`, bump `updated:`, and begin implementation.
5. **As you work**, maintain `## Progress`, `## Implementation log`, and `## Surprises & discoveries`. Commit frequently. Append each commit hash to `commits: [...]` and to the relevant `Implementation log` cluster.
6. **At each stopping point**, confirm `## Progress` reflects the true state.
7. **When implementation is committed**, flip `status: active → review`. Hand off to codex.
8. **As codex findings come in**, fix them, commit, append to `## Post-implementation review`.
9. **At session close** (codex clean, developer agrees): flip `status: review → shipped`, fill `## Close note / retrospective`, move the `docs/PLANS.md` row from `## In flight` to `## Shipped` and update its status column. If this session finished a roadmap, update `docs/ROADMAP.md` too. Record any new `D-NNN`s in the appropriate topical file; if the session introduced a brand-new `DECISIONS-*.md` topical file, add its row to `docs/DECISIONS.md` in the same commit. Rewrite `docs/HANDOFF.md` (overwrite, not append) **if** this session changed shipped product / runtime state or changed the workflow itself; docs-only sessions may skip HANDOFF otherwise. The plan file stays where it is — no move. Run the mechanical consistency check (skill or script — see `docs/README.md` § "Mechanical consistency check" for which is live) before reporting done.

---

## Core principles (internalise before you write the plan)

- **Self-contained + context-aware.** A reader who has the canonical context (`CLAUDE.md`, `docs/README.md`, `docs/HANDOFF.md`, the roadmap section, the topical decision files) plus this plan can execute the work end-to-end. You do not dump all of riservo into the plan; you name the files to read, then fill in everything task-specific.
- **Living document.** Revise the plan as you learn. Progress, Implementation log, Surprises, Decision-log notes, and Post-implementation review accumulate as you work — not retroactively at close. A reader coming back in three months must see exactly what happened and why.
- **Purpose first.** Every plan opens with what the user gains and how to see it. If you cannot articulate user-visible behavior, flag it with the developer before going further.
- **Outcome-focused validation.** Acceptance is observable behavior — a test that fails before and passes after, a command + expected output, a URL + expected response. Not "added a Foo class".
- **Resolve ambiguity in the plan.** Do not outsource decisions to the reader. When two approaches compete, pick one in the plan with a one-paragraph rationale. If you genuinely cannot pick, it is an `Open question` for the developer, not a tolerated ambiguity.
- **Plain language.** Define every term that is not ordinary English on first use. "Tenant context", "magic link", "slot generator", "GIST overlap", "reschedule notification" — all defined (or referenced by `D-NNN`) when first mentioned in the plan.
- **Idempotent + safe steps.** Write the implementation steps so they can be re-run. Flag destructive operations with explicit rollback paths. Prefer additive changes followed by subtractions that keep tests green at every stopping point.
- **Commit often.** Each commit is a verifiable checkpoint. Each cluster of work = one commit minimum. Each commit hash goes into `commits:` + the `Implementation log` cluster that produced it.
- **No external blogs / tutorials.** Do point at project docs by repo-relative path (`docs/SPEC.md §7.6`, `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md D-065`). Do not link out to third-party docs unless the only sane source of truth is external (rare).

---

## Quality bar

- **Best solution, not fastest.** Argue choices that scale, not choices that "work for now".
- **Tests before bypass.** Never skip, `@skip`, `@todo`, or comment out a test to make a session ship. If a test contract must evolve, write a new test that locks the new contract, justify the change in one line in the plan, and move on.
- **Pint + Wayfinder + build at every close.** `vendor/bin/pint --dirty --format agent`, `php artisan wayfinder:generate`, `npm run build` — all clean before you report "done".
- **Scope fidelity.** If the plan says "Out: X", do not do X. If the session genuinely needs X, go back to the plan, amend it, get re-approval. Do not drift.
- **Docs discipline.** Every `status:` flip is mirrored in `docs/PLANS.md` (or `docs/ROADMAP.md` if a roadmap just closed). `docs/HANDOFF.md` rewritten fresh at close — not appended. New decisions in the correct topical `DECISIONS-*.md`.

---

## Rules of engagement

- **You never approve your own plan.** The developer always approves before implementation starts.
- **You never spawn sub-agents.** A single implementing session is a single chat.
- **You never commit or push.** You propose commits by staging + reporting. The developer commits. (Exception: if the developer has explicitly told you to commit autonomously in this session, follow that instruction.)
- **You never skip the read-first chain.** Even if you are sure you remember the project. Memory is unreliable; disk is authoritative.
- **You never move files to archive.** `docs/archive/` does not exist. Status flips; paths stay.
- **You hold the line on locked decisions.** Every `D-NNN` already in a topical file and every cross-cutting decision in the roadmap's locked-decisions section is binding. Only the developer can override, and an override is a new `D-NNN` that supersedes the old one.
- **You stay in English.** Plan body, implementation log, commit messages, decisions — all in English (matches the rest of the project's docs).

---

## Handoff protocol

When you are ready to hand the plan to the developer for approval:

> "The plan is drafted at `docs/plans/PLAN-[SESSION ID]-[SHORT-TITLE].md` with `status: planning`. [One-paragraph summary: scope, approach, open questions, expected test-count delta, expected surface touched.] Ready for review."

When you are ready to hand the implementation to the developer for commit + codex review:

> "Implementation committed at `[HASH]`. Plan `status: review`. Tests: `{baseline} → {final}` / `{baseline} → {final}` assertions. Pint clean, build clean, Wayfinder regenerated. Short report: [files changed | test delta | new decisions recorded | deviations from plan | manual smoke steps]. Ready for codex."

When you close the session:

> "Session closed at `[FINAL HASH]`. Plan `status: shipped`. `docs/HANDOFF.md` rewritten. `docs/PLANS.md` updated. Decisions recorded: [D-NNN list]. Carry-overs to BACKLOG: [if any]. Lessons learned: [if non-trivial]."

---

## First task

Read the read-first list in order. Confirm:

- Latest commit hash on `main` (baseline before this session).
- Current Feature+Unit test count (from `docs/HANDOFF.md`).
- Next available `D-NNN` in the relevant topical file.
- Any open questions in the roadmap section that must be resolved before drafting the plan.

Then draft `docs/plans/PLAN-[SESSION ID]-[SHORT-TITLE].md` per the shape above, add its row to `docs/PLANS.md` under `## In flight`, flip `status: draft → planning`, and hand off with the "plan is drafted" protocol line. STOP. Wait for developer approval before writing any code.
