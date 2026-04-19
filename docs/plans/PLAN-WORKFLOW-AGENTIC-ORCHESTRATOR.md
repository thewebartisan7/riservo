---
name: PLAN-WORKFLOW-AGENTIC-ORCHESTRATOR
description: "Evolve the orchestrator template from passive coordinator to active sub-agent spawner + direct codex plugin integration"
type: plan
status: shipped
created: 2026-04-18
updated: 2026-04-18
commits: []
supersededBy: null
---

# PLAN — WORKFLOW-AGENTIC-ORCHESTRATOR — Active orchestrator with sub-agent spawning and direct codex integration

## Purpose

After this change, the developer spins up one orchestrator agent per roadmap and the orchestrator itself does the work a clerk used to do manually: it spawns the per-session implementer sub-agent via Claude Code's `Agent` tool, continues that same sub-agent through plan → implementation → codex-fix rounds via `SendMessage`, invokes `/codex:review --background` directly against the plugin, polls `/codex:status`, reads `/codex:result`, and routes clear-bug findings back to the implementer autonomously. The developer stays in the loop at exactly two gates: plan approval and commit/push. Everything else becomes orchestrator-driven.

You can see it working by reading the rewritten `.claude/prompts/ROADMAP-ORCHESTRATOR.md`: the Phase 1 section now opens with a concrete `Agent` tool call rather than "hand the prompt to the developer", Phase 5 opens with a concrete `/codex:review --background` invocation rather than "produce an instruction for the developer to give codex", and the rules-of-engagement block declares the status-lock + serial-execution invariant in plain terms.

## Context

riservo.ch uses a four-layer work flow (INTENT → ROADMAP → ORCHESTRATION → IMPLEMENTATION) described in `docs/README.md` § "The four-layer work flow". Three prompt templates drive the loops:

- `.claude/prompts/ROADMAP-ARCHITECT.md` — Layer 2.
- `.claude/prompts/ROADMAP-ORCHESTRATOR.md` — Layer 3. The main target of this session.
- `.claude/prompts/SESSION-IMPLEMENTER.md` — Layer 4.

The current orchestrator template describes a coordinator that never acts — it only drafts prompts for the developer to paste into fresh implementer / codex chats, and triages findings the developer relays back. The developer spawns every agent manually and shuttles every artefact between chats. It works, but the orchestrator is more clerk than orchestrator.

Two tooling shifts make a more active orchestrator viable:

1. **Claude Code's `Agent` tool.** The orchestrator can spawn sub-agents in-process. With `run_in_background: true` the orchestrator does not block on the sub-agent, and `SendMessage` (by agent id or name) lets the orchestrator continue the same sub-agent across rounds without re-spawning — preserving context, tacit knowledge, and token cost.
2. **The codex plugin (`openai/codex-plugin-cc`)** confirmed installed and authenticated in this Claude Code install (see "Surprises & discoveries" once filled). It exposes `/codex:review`, `/codex:adversarial-review`, `/codex:rescue`, `/codex:status`, `/codex:result`, `/codex:cancel`, `/codex:setup`. `/codex:review` accepts `--base <ref>`, `--wait`, `--background`. `--background` returns a task id; `/codex:status [id]` polls; `/codex:result [id]` retrieves; `/codex:cancel [id]` kills. The plugin also ships internal skills `codex:codex-cli-runtime` and `codex:codex-result-handling` that standardise how Claude calls the companion runtime and formats results.

Paths of interest this session touches:

- `.claude/prompts/ROADMAP-ORCHESTRATOR.md` — rewrite in place.
- `.claude/prompts/SESSION-IMPLEMENTER.md` — add "Incoming messages from the orchestrator" section, clarify status-lock protocol from the implementer side, note fix rounds come via `SendMessage` (no re-brief needed).
- `.claude/prompts/ROADMAP-ARCHITECT.md` — add one paragraph on declaring the parallel-sub-agent exception in a roadmap's frontmatter or overview.
- `HUMANS.md` — update the "Mental model" or "Session flow" sections with one or two paragraphs on the active orchestrator.
- `docs/PLANS.md` — add this plan under `## In flight`, move to `## Shipped` at close.
- `docs/README.md` and `.claude/CLAUDE.md` / `.agents/AGENTS.md` — touch only if terminology or workflow steps genuinely need to change (scope rule from brief). We will assess during implementation; default stance is **no change**.

Terms:

- **sub-agent** — a secondary agent spawned by the orchestrator via the `Agent` tool. Runs in a separate context; receives its own prompt; reports back on the same message channel or via `SendMessage`.
- **status lock** — the invariant that a plan file's frontmatter `status:` value gates which agent may act. `active` means the implementer may work and codex must not run; `review` means codex may run and the implementer is frozen. Only the orchestrator flips between `active` and `review`.
- **triage authority split** — the rule that classifies codex findings as either *clear bugs* (autonomously routed to the implementer) or *judgment calls* (paused for developer decision).
- **context escape hatch** — the routine the orchestrator runs when its own context approaches saturation, writing a short handoff brief so the developer can spin up a fresh orchestrator without losing roadmap-level state.

## Scope

### In

- Rewrite `.claude/prompts/ROADMAP-ORCHESTRATOR.md` so:
  - Phase 1 opens with a concrete `Agent` tool call shape (subagent_type, run_in_background, prompt skeleton reference).
  - Phases 2–3 describe plan review and approval relay via `SendMessage` to the same sub-agent.
  - Phase 4 describes receiving the implementation report on the same channel (either the agent's foreground return, or a polled `TaskOutput` style read if still running in background).
  - Phase 5 invokes `/codex:review --background` directly, polls `/codex:status`, reads `/codex:result`, and routes findings via `SendMessage` to the implementer. The status lock (`active → review` before codex, `review → active` before routing fixes) is called out inline.
  - Phase 6 stays the close phase; adds the final status flip to `shipped`.
  - A new "Status lock and serial execution" subsection under Rules of engagement declares the invariant in plain terms, with the concrete `status:` transitions that encode it.
  - A new "Triage authority" subsection declares the autonomous-vs-pause split, with three or four concrete examples of each.
  - A new "Context escape hatch" subsection declares the trigger and the handoff-brief shape.
  - A new appendix "Appendix A — Parallel-sub-agent exception" documents the rare pattern (E2E-style, independent tests) and states that, absent an explicit declaration in the roadmap, serial-only applies.
- Update `.claude/prompts/SESSION-IMPLEMENTER.md`:
  - Add "Incoming messages from the orchestrator" section standardising the envelope for plan-review, approval, fix-round, and close messages the implementer will receive via `SendMessage`.
  - Clarify "## The four-layer flow you operate in" to reflect the implementer is now typically spawned by the orchestrator, not pasted by the developer (but both paths remain valid).
  - Clarify the status-lock protocol from the implementer's side: implementer flips `planning → active` on approval, `active → review` when committed; does **not** flip `review → active` back (that is the orchestrator's privilege when routing a codex fix).
- Update `.claude/prompts/ROADMAP-ARCHITECT.md`:
  - One paragraph in the "Output format" Mode-A section covering how to declare the parallel-sub-agent exception in a new roadmap — a top-level `execution: parallel-sessions-allowed` frontmatter field plus an explicit justification in the overview.
- Update `HUMANS.md`:
  - One paragraph in "Session flow" (or "Mental model") describing the active-orchestrator behavior: "I spin up one orchestrator per roadmap; it spawns the implementer sub-agent, relays codex findings, and only pauses for me at plan approval and commit."

### Out

- No changes to `app/Console/Commands/DocsCheckCommand.php`, `.claude/skills/riservo-status/SKILL.md`, the indices, or the canonical docs shape beyond the three template files + HUMANS.md + this plan row.
- No changes to `docs/SPEC.md`, any `docs/decisions/DECISIONS-*.md`, or any application code.
- No automation (no bootstrap scripts that spin up orchestrators). Template updates only.
- No dogfooding against ROADMAP-PAYMENTS in this session. The developer spawns the first active orchestrator against PAYMENTS-1 as the next step, after this session closes.
- No `git worktree` integration. Serial execution on the main tree is the deliberate model.
- No update to `docs/README.md` or `.claude/CLAUDE.md` / `.agents/AGENTS.md`. Rationale: the four-layer framing, status taxonomy, and session flow described in those files already fit the active orchestrator; only the *internal mechanics* of Layer 3 change, which is exactly what the orchestrator template owns. If implementation surfaces a terminology collision, we re-open this boundary with the developer before editing.

## Key design decisions

1. **Same sub-agent across plan → implementation → codex fix rounds.** The orchestrator spawns *one* implementer sub-agent per session via `Agent` and continues it via `SendMessage`. No re-spawning between phases. Rationale: context continuity (the sub-agent already knows the plan, the files it touched, the deviations it took), token cost (a fresh implementer re-reads the entire read-first list), and tacit knowledge carry-forward (surprises and discoveries from implementation inform fix rounds). A fresh implementer is spawned only if the sub-agent's context genuinely bloats — rare; judgment call surfaced to the developer.

2. **Strictly serial execution, always, one sub-agent at a time.** Never two implementer sub-agents concurrently. Never codex review while the implementer is editing. The plan file's frontmatter `status:` is the machine-readable lock:
   - `status: active` → implementer may work; codex may **not** run.
   - `status: review` → codex may run; implementer is **frozen**.
   - Orchestrator flips `active → review` before invoking `/codex:review`; flips `review → active` when routing a fix; flips to `shipped` only at session close.
   Rationale: the developer's stated preference, plus it removes an entire class of "codex reviewed a version the implementer has since moved past" bugs. The status field is already authoritative per `docs/README.md`; this session hardens the semantics, it does not invent a new field.

3. **Codex invoked directly via the plugin's slash commands.** The orchestrator's Phase 5 default pattern: `/codex:review --background` → poll `/codex:status <id>` → `/codex:result <id>`. Use `/codex:adversarial-review` only for sessions touching security / concurrency / payments surfaces. Rationale: the plugin is a first-party OpenAI integration designed for exactly this Claude-Code-invokes-Codex loop; bypassing it with a Bash wrapper around the companion script would duplicate infrastructure the plugin already provides. The template notes the companion-script fallback (`node .../codex-companion.mjs`) as a last-resort if slash commands misbehave.

4. **Triage authority split.** Codex findings partition into two classes:
   - **Autonomous routing** — clear bugs: broken tests, syntax errors, obvious logic errors, documented-invariant violations (GIST overlap, `billing.writable`, tenant scoping, D-NNN contradictions). The orchestrator drafts a fix prompt and `SendMessage`s the implementer immediately.
   - **Human check** — judgment calls: alternative-design suggestions, scope-creep debate, breaking-change proposals, anything that re-opens a locked decision or widens the session beyond its plan. The orchestrator pauses and asks the developer before routing.
   Rationale: not every codex finding is a bug; some are debate. The split lets the orchestrator clear obvious fix loops without paging the developer, while preserving developer authority on scope and design.

5. **Developer gates stay human-only.** Plan approval is always human. Commit and push are always human. No agent commits. No agent pushes. The orchestrator cannot override either gate even in "obvious" cases. Rationale: the developer's standing rule across every existing template; this session does not relax it.

6. **Parallel-sub-agent exception is documented, not default.** Roadmaps where session units are (a) genuinely independent and (b) reviewed by "tests pass" rather than "code pattern review" — `ROADMAP-E2E.md` is the canonical example, bulk migrations are another — may run implementer sub-agents in parallel. These roadmaps **must declare the exception explicitly** (proposed frontmatter key: `execution: parallel-sessions-allowed`) and justify it in the overview. Absent an explicit declaration, serial-only applies. Lives as Appendix A in the orchestrator template, not a separate template file. Rationale: a separate template for a rarely-used variant doubles maintenance burden; an appendix keeps serial as the headline default and parallel as a clearly-scoped deviation.

7. **Context escape hatch at self-reported ~500k tokens.** The orchestrator evaluates its remaining-context signal at the start of each phase. When the signal indicates it is past the halfway point of its 1M context budget, it pauses, writes a short handoff brief (current roadmap state: sessions shipped with commit hashes, sessions in flight, next free D-NNN, open carry-overs, last codex round results if any), and tells the developer to spin up a fresh orchestrator with that brief. Rationale: token-count is the most direct signal; message-count is a proxy that drifts when sessions vary in size; self-report matches the way every other "when do I STOP and hand off" condition in these templates is phrased. The 500k threshold leaves the fresh orchestrator 500k to spend — enough for several more sessions.

8. **No git worktrees.** Sub-agents work in the main tree; serial execution means no concurrent-write risk. `/codex:review` reviews the main tree. This matches the current convention and removes the worktree-management ceremony that would otherwise leak into the template. Rationale: simpler is better when the concurrency constraint that motivates worktrees has been removed by invariant 2 above.

## Implementation steps

Work lands in a single commit if the edits pass docs:check clean; two commits are acceptable if it makes the diff easier to review (orchestrator rewrite first; ancillary template edits second). All five files mentioned in Scope-In are touched in the same PR.

### Step 1 — Rewrite `.claude/prompts/ROADMAP-ORCHESTRATOR.md`

The current file is 168 lines. Target: ≤220 lines after rewrite (budget for the new subsections). Rewrite in place — do not append a "v2" section.

Concrete sub-edits:

- **Prelude block (lines 1–5).** Update the `> Purpose:` block to say "active coordinator" and mention that the orchestrator spawns the implementer sub-agent via the `Agent` tool and invokes `/codex:review` directly.
- **Read-first list (Phase 0 equivalent).** Append one line after the `DECISIONS-*.md` entry: "The codex plugin's command list — `/codex:review [--base <ref>] [--wait] [--background]`, `/codex:adversarial-review`, `/codex:status [id]`, `/codex:result [id]`, `/codex:cancel [id]`. If the plugin is not installed (`/plugins` does not list `codex@openai-codex`), stop and ask the developer before starting the roadmap."
- **Phase 1 — Initial prompt for the implementing agent.** Rewrite so the phase produces the prompt *and* spawns the sub-agent. Concrete shape:

  ```
  1. Developer says "ready for Session N".
  2. Read the Session N spec, cross-cutting decisions, HANDOFF, relevant DECISIONS-*.md, most recent shipped plan.
  3. Compose the session-level brief that the implementer will receive. Contents: [same list as current Phase 1: project state, read-first list, task scope, locked decisions, quality bar, open questions, workflow, definition of done, report-back shape]. The brief is the BODY of the prompt; the outer wrapper is the SESSION-IMPLEMENTER template shape.
  4. Spawn the implementer sub-agent:

     Agent({
       description: "Session N implementer — [short title]",
       subagent_type: "general-purpose",
       run_in_background: true,
       prompt: "[SESSION-IMPLEMENTER template body, with every [BRACKETED] placeholder filled from the brief in step 3]"
     })

     Record the returned agent id / name. Default subagent_type is `general-purpose` because the template body is self-contained and no specialised tool surface is required; do not introduce a custom subagent definition for this.
  5. Wait for the sub-agent to produce the plan file. When it signals "plan ready for review" (either by returning from its first run or by emitting the handoff protocol line), pull the plan path.
  ```

- **Phase 2 — Plan review.** Unchanged in principle (read the plan, produce a review note with Strengths / Required fixes / Minor refinements), but the *relay* changes: instead of giving the review to the developer to paste, the orchestrator `SendMessage`s the review directly to the sub-agent, with an envelope header (spec'd in SESSION-IMPLEMENTER.md). The developer still sees the review — the orchestrator sends its review to *both* the developer (as normal output) and the sub-agent (via `SendMessage`). The developer may veto or add fixes before the sub-agent acts, so the sub-agent waits for either "approved, implement" or a revised patch list before doing anything.
- **Phase 3 — Approval relay.** Orchestrator `SendMessage`s the "approved, you may implement; flip status: planning → active; begin" message. No developer copy-paste. Developer sign-off is captured when the developer tells the orchestrator to send approval.
- **Phase 4 — Implementation report.** When the sub-agent signals "implementation committed; ready for codex", the orchestrator reads the report, verifies lifecycle bookkeeping (status flipped to review, `commits:` populated, PLANS.md updated, HANDOFF rewritten if the session is product/runtime-touching), and produces a short verdict. If red flags (bypassed tests, silent scope expansion, re-opened locked decision), orchestrator `SendMessage`s a "please address X first" back to the sub-agent *before* running codex.
- **Phase 5 — Codex review coordination.** Rewrite to:

  ```
  1. Confirm plan status is `review` (the sub-agent flipped it at end of Phase 4).
  2. Invoke `/codex:review --background` (add `--base main` if the session is on a branch, omit for main-tree work). Use `/codex:adversarial-review --background` for sessions touching payments, concurrency, auth, or security surfaces — roadmap frontmatter or session scope indicates which.
  3. Record the returned task id.
  4. Poll `/codex:status <id>` until codex reports finished. Expect multiple minutes; the orchestrator may continue with other roadmap bookkeeping between polls but MUST NOT SendMessage the sub-agent until codex is done and a routing decision has been made.
  5. Read `/codex:result <id>`.
  6. Triage findings per "Triage authority" (below).
     - Clear bugs: draft a tight fix prompt, flip plan status `review → active` (orchestrator only), SendMessage the sub-agent the fix prompt. When the sub-agent reports commit, flip `active → review` again and return to step 2 for a re-review on the new commit.
     - Judgment calls: pause, surface to the developer, wait for decision, then route the decision back to the sub-agent.
  7. Loop until codex returns clean.
  ```

- **Phase 6 — Session close.** Unchanged in principle. Add one line: orchestrator `SendMessage`s the sub-agent the close instruction (flip `review → shipped`, fill Close note, move PLANS.md row, rewrite HANDOFF if applicable). Then appends the Post-implementation review subsection to the plan file either itself or by SendMessage — the template specifies the sub-agent does it since the sub-agent already owns the plan file.
- **Rules of engagement — new subsection "Status lock and serial execution".** New prose:

  > The plan file's frontmatter `status:` is the machine-readable lock that gates which agent may act:
  > - `status: active` — the implementer sub-agent may edit files. Codex MUST NOT run.
  > - `status: review` — codex may run against the main tree. The implementer sub-agent is frozen; the orchestrator MUST NOT `SendMessage` it with new work until the orchestrator flips the status back to `active`.
  > - `status: shipped` — session closed. Both sub-agent and codex are done.
  > Only the orchestrator flips between `active` and `review`. The implementer sub-agent flips `planning → active` once on approval and `active → review` once when committed; never back. One implementer sub-agent per session. One codex job in flight at a time. No exceptions without an explicit roadmap-level declaration (see Appendix A).

- **Rules of engagement — new subsection "Triage authority".** New prose:

  > Codex findings split into two classes:
  > - **Clear bugs — route autonomously.** Broken test, syntax error, obvious logic error, violation of a documented invariant (GIST overlap on `bookings`, `billing.writable` middleware on a mutating route, tenant scoping on a business-owned query, a D-NNN contradiction). Orchestrator drafts a fix prompt and SendMessages the sub-agent immediately. No developer pause.
  > - **Judgment calls — pause for developer.** Alternative-design suggestion, scope-creep debate, breaking-change proposal, anything that re-opens a locked decision or widens scope beyond the plan. Orchestrator surfaces the finding to the developer, waits, and only routes once the developer has decided.
  > When in doubt, ask. The default on ambiguous findings is pause, not route.

- **Rules of engagement — new subsection "Context escape hatch".** New prose:

  > When the orchestrator's remaining context signal indicates it has crossed the halfway mark of its budget (~500k tokens remaining against a 1M budget on Opus 4.7), pause at the next phase boundary and write a handoff brief. The brief is a single message to the developer with: current commit hash, current Feature+Unit test count, sessions shipped this roadmap (with their commit hashes), sessions in flight with their current `status:`, next free `D-NNN`, any carry-overs queued to `docs/BACKLOG.md`, and the last codex round's result summary. The developer spins up a fresh orchestrator with the brief pasted in. Do NOT attempt to continue past the halfway mark — the fresh orchestrator needs its own 500k to spend.

- **Appendix A — Parallel-sub-agent exception.** New section at the bottom. Brief:

  > Default: serial execution, one implementer sub-agent at a time, status lock enforced. Exception: a roadmap may declare `execution: parallel-sessions-allowed` in its frontmatter when (a) session units are genuinely independent (no shared files, no shared model changes, no shared routes) and (b) acceptance is "tests pass", not "code pattern review". `ROADMAP-E2E.md`'s per-route browser-test sessions are the canonical example. If a roadmap declares the exception, the orchestrator MAY spawn one sub-agent per concurrent session with distinct plan-file paths, and codex review may run sequentially per plan file (not concurrently across plans). Every other rule — human approval gates, triage authority, no auto-commit — still applies.

### Step 2 — Update `.claude/prompts/SESSION-IMPLEMENTER.md`

Concrete sub-edits:

- **"The four-layer flow you operate in" (lines 17–25).** Add one sentence at the end of the block: "In practice, the orchestrator typically spawns you via Claude Code's `Agent` tool and continues you via `SendMessage` across plan review, approval, fix rounds, and close. The developer may also brief you directly for a one-off task; both paths use the same template body."
- **"Workflow" (around line 207).** Re-word the status-lock line: "On approval, flip `status: planning → active` and begin. When implementation is committed, flip `status: active → review` and report to the orchestrator. You do NOT flip `review` back to `active` — that is the orchestrator's transition, used when it routes a codex fix to you."
- **New section — "Incoming messages from the orchestrator" (between "Workflow" and "Core principles").** New content:

  > If the orchestrator spawned you via the `Agent` tool, you will receive messages from it via `SendMessage`. Each message follows one of four envelopes. Match the envelope, do the work, and reply in kind.
  >
  > **Envelope 1 — Plan review.** Header: `[ORCHESTRATOR → IMPLEMENTER — PLAN REVIEW]`. Body contains Strengths / Required fixes / Minor refinements buckets. Your action: revise the plan in-place, update `updated:`, reply with "plan revised at `[path]`; diff summary: …". Do NOT flip `status:` yet.
  >
  > **Envelope 2 — Approval.** Header: `[ORCHESTRATOR → IMPLEMENTER — APPROVED, IMPLEMENT]`. Body may contain last patches that must land first. Your action: apply any last patches, flip `status: planning → active`, bump `updated:`, begin implementation. Reply with progress at natural milestones.
  >
  > **Envelope 3 — Codex fix round.** Header: `[ORCHESTRATOR → IMPLEMENTER — CODEX FIX, ROUND N]`. The orchestrator has flipped `status: review → active` on your behalf. Body lists the codex findings to fix, ranked. Your action: apply fixes, commit, append a line to `## Progress`, append a cluster to `## Implementation log` with the new commit hash, flip `status: active → review`, reply with the new commit hash.
  >
  > **Envelope 4 — Close instruction.** Header: `[ORCHESTRATOR → IMPLEMENTER — CLOSE]`. Body may contain final decisions on carry-overs or lessons-learned framing. Your action: append the `## Post-implementation review` section (one subsection per codex round, plus "Closed clean at commit …"), fill the `## Close note / retrospective`, flip `status: review → shipped`, move the `docs/PLANS.md` row from `## In flight` to `## Shipped`, update `docs/ROADMAP.md` if this closes a roadmap, rewrite `docs/HANDOFF.md` if the session is product/runtime-touching, run `php artisan docs:check` until clean, reply with the final report.
  >
  > In all four envelopes: if the orchestrator tells you to do something that violates a locked decision, a cross-cutting invariant, or the scope boundary in the plan, refuse and ask the orchestrator to re-check with the developer. You hold the line even against the orchestrator.

### Step 3 — Update `.claude/prompts/ROADMAP-ARCHITECT.md`

Concrete sub-edit:

- **"Mode A — DRAFT FROM INTENT" output section, `## Overview` description.** Add one paragraph after the session-summary-table bullet:

  > If — and only if — this roadmap's sessions are genuinely independent (no shared files, no shared model changes) and acceptance is "tests pass" rather than "code pattern review", declare the parallel-sub-agent exception by adding `execution: parallel-sessions-allowed` to the roadmap frontmatter AND justifying the declaration in the Overview (one paragraph: which independence claim, which acceptance shape). Absent an explicit declaration, the orchestrator runs one implementer sub-agent at a time per Appendix A of `.claude/prompts/ROADMAP-ORCHESTRATOR.md`. Do not declare the exception for mixed roadmaps where some sessions share surface area.

### Step 4 — Update `HUMANS.md`

Concrete sub-edit:

- **"Session flow (how I actually work)" section.** Insert two paragraphs at the top, before the numbered list:

  > With the codex plugin installed and Claude Code's `Agent` tool available, I now spin up one orchestrator per roadmap and let it do the coordination legwork. The orchestrator spawns the implementer sub-agent via `Agent`, continues it across plan → implementation → codex-fix rounds via `SendMessage`, invokes `/codex:review --background` directly, and routes clear-bug codex findings back to the implementer without asking me. I stay in the loop at exactly two gates: plan approval and commit/push.
  >
  > The plan file's frontmatter `status:` is the serial-execution lock. `active` means the implementer is working and codex must not run; `review` means codex is running and the implementer is frozen. Only the orchestrator flips between them. One implementer sub-agent at a time, one codex job in flight at a time — unless the roadmap explicitly declares `execution: parallel-sessions-allowed` (rare; see Appendix A of `.claude/prompts/ROADMAP-ORCHESTRATOR.md`).

- **"Possible future improvements"** stays unchanged.

### Step 5 — Verify and close

- Run `php artisan docs:check` — must return clean. The plan file itself must pass (frontmatter, indexed in PLANS.md, bucket matches status).
- Run `vendor/bin/pint --dirty --format agent` — no PHP files should be touched, so Pint should be a no-op, but run it anyway.
- Confirm Wayfinder is not affected (no route changes), skip `npm run build` (no frontend changes).
- Flip plan `status: active → shipped`, bump `updated:`, record final commit hash in `commits: [...]`.
- Move `docs/PLANS.md` row from `## In flight` to `## Shipped` under a new "Workflow" subsection (or piggyback "Docs system" if that fits the developer's preference).
- HANDOFF update is **optional** per `.claude/CLAUDE.md` — this session does not change shipped product / runtime state and the workflow change is orchestrator-internal rather than a canonical-reading-order change. Default: **skip HANDOFF**. The developer may direct otherwise at close.

## Files to create / modify

### Create

- `docs/plans/PLAN-WORKFLOW-AGENTIC-ORCHESTRATOR.md` — this file.

### Modify

- `.claude/prompts/ROADMAP-ORCHESTRATOR.md` — full rewrite in place (Steps 1 sub-edits above).
- `.claude/prompts/SESSION-IMPLEMENTER.md` — three targeted edits (Step 2 sub-edits above).
- `.claude/prompts/ROADMAP-ARCHITECT.md` — one paragraph insert (Step 3).
- `HUMANS.md` — two-paragraph insert (Step 4).
- `docs/PLANS.md` — row add under `## In flight` at plan start; row move to `## Shipped` at close.

### Delete

- None.

## Tests

No automated test coverage exists for prompt-template files and none is introduced in this session. The acceptance check is mechanical:

| Area | Cases | Notes |
|---|---|---|
| docs:check | `php artisan docs:check` returns exit 0, no findings | Runs against the new plan file + the PLANS.md row move |
| Pint | `vendor/bin/pint --dirty --format agent` returns 0 | No-op expected (no PHP touched) |
| Manual read-through | Orchestrator template reads as a single coherent document | Specifically: the status-lock invariant is stated once and referenced everywhere it applies, not restated in every phase |
| Manual read-through | Implementer template's "Incoming messages" section matches the four envelopes the orchestrator actually sends | Cross-reference by eye, not by test |

Feature+Unit test baseline: 693 passed / 2814 assertions (from `docs/HANDOFF.md`). Expected delta: **none**. If the baseline changes during implementation, investigate — it should not.

## Validation & acceptance

Observable behavior after this session:

1. Opening `.claude/prompts/ROADMAP-ORCHESTRATOR.md` and searching for the literal string `Agent(` finds a concrete tool-call example in Phase 1. Before the session, that string did not appear in the file.
2. The orchestrator template contains three new subsections under "Rules of engagement" (Status lock and serial execution / Triage authority / Context escape hatch) and one new appendix (Parallel-sub-agent exception) — findable by their headings.
3. `.claude/prompts/SESSION-IMPLEMENTER.md` contains an "Incoming messages from the orchestrator" section with four envelopes (`PLAN REVIEW`, `APPROVED, IMPLEMENT`, `CODEX FIX, ROUND N`, `CLOSE`). Grep for `[ORCHESTRATOR → IMPLEMENTER` surfaces all four.
4. `.claude/prompts/ROADMAP-ARCHITECT.md` mentions `execution: parallel-sessions-allowed` and the Appendix A reference. Grep confirms.
5. `HUMANS.md`'s "Session flow" section opens with two paragraphs describing the active orchestrator and the status lock.
6. `php artisan docs:check` returns exit 0. No findings.
7. `docs/PLANS.md` contains a row for this plan, initially under `## In flight`, moved to `## Shipped` on close.

Manual smoke the developer should run at session close:

- Read each of the five modified files top-to-bottom. Confirm no contradictions between them (e.g., if the implementer template says "orchestrator flips review→active" and the orchestrator template says "implementer flips review→active", that's a contradiction and must be resolved in favour of the orchestrator owning the transition).
- Spot-check that the four envelopes in SESSION-IMPLEMENTER.md match the four places ROADMAP-ORCHESTRATOR.md `SendMessage`s (plan review, approval, codex fix, close). If they drift apart, fix before commit.

## Decisions to record

**None.** This session makes no architectural `D-NNN` decisions — it updates workflow tooling / templates only. The six new conventions introduced (status lock semantics, sub-agent continuity, triage split, codex invocation path, context escape hatch, parallel exception) live in the templates themselves and are enforced by template prose, not by application code or `docs/decisions/` entries.

If the developer decides one of these conventions is heavyweight enough to warrant a topical D-NNN entry (e.g. "D-1NN — status field as execution lock"), that is a separate session; flag it at close for the developer to decide.

## Open questions

Resolved in the plan above; listed here for traceability.

1. **Exact `Agent` tool invocation shape.** Resolved: `subagent_type: "general-purpose"`, `run_in_background: true`, prompt body = the SESSION-IMPLEMENTER template with placeholders filled. Rationale: the template body is self-contained; a dedicated subagent definition would duplicate the template in a separate file and add a sync-drift risk.
2. **SendMessage envelope shape.** Resolved: four envelopes (`PLAN REVIEW`, `APPROVED, IMPLEMENT`, `CODEX FIX, ROUND N`, `CLOSE`) documented in SESSION-IMPLEMENTER.md's new "Incoming messages" section. Each envelope has a header, a body spec, and an expected reply. The implementer must refuse envelopes that violate scope or locked decisions — the sub-agent holds the line even against the orchestrator.
3. **`/codex:review` invocation from inside an orchestrator.** Resolved: slash-command invocation (`/codex:review --background`) is the primary path. The companion-script fallback (`node .../codex-companion.mjs review …`) is noted as a last-resort only. Rationale: the plugin is designed for exactly this Claude-invokes-Codex loop; the slash-command surface is the cleanest.
4. **Context escape-hatch trigger.** Resolved: self-reported remaining-context at ~500k tokens on a 1M budget (halfway). Phase-boundary check, not per-turn. Rationale: self-report matches how every other "STOP and hand off" condition in the templates is phrased; 500k leaves the fresh orchestrator room to spend.
5. **Exception-pattern location.** Resolved: Appendix A inside `.claude/prompts/ROADMAP-ORCHESTRATOR.md`. Rationale: a separate template for a rarely-used variant doubles maintenance burden; an appendix keeps serial as the headline default and parallel as a clearly-scoped deviation.

## Risks & notes

- **Risk: the plugin's real slash-command behavior differs subtly from the README.** For example, `/codex:status` might return task ids in a format the orchestrator template does not anticipate. Mitigation: the developer will dogfood against PAYMENTS-1 as the next session; any template patch needed surfaces there. The template should phrase the commands defensively ("the orchestrator invokes `/codex:review --background`, reads the returned task id, polls `/codex:status <id>`") rather than quoting exact output formats.
- **Risk: `Agent` tool behavior depends on the specific Claude Code version.** If a future Claude Code release changes `run_in_background` semantics or `SendMessage` routing, the orchestrator template will need a patch. The template should not over-specify tool mechanics — it should state the *intent* (spawn, continue, close) and leave the mechanical details as examples rather than contracts.
- **Risk: status-lock contract drift.** If a future session updates the SESSION-IMPLEMENTER template in a way that lets the implementer flip `review → active`, the status lock breaks. Mitigation: call out the invariant explicitly in both templates, and add an "implementer does NOT flip review→active" one-liner to the SESSION-IMPLEMENTER workflow section (Step 2 above).
- **Risk: the 500k escape-hatch threshold is wrong.** If orchestrators routinely hit the threshold in the middle of a single session, the threshold is too tight; if they never hit it across a multi-session roadmap, it is too loose. Mitigation: the developer should track whether the threshold fires during PAYMENTS. If it fires mid-session twice in a row, bump to ~600k. No automation; judgment call.
- **Note: this session makes no application-code changes.** `npm run build` and Wayfinder regen are not required. `docs:check` is the only mechanical gate.
- **Note: no codex pass on this session.** The plan explicitly skips `## Post-implementation review` per the docs-only-session carve-out in `docs/README.md` § "Session lifecycle in a plan file". The developer may override and request a codex pass; in that case, the orchestrator-loop we are building is the one we would dogfood, which is circular. Prefer "no codex on the meta-session" to avoid the chicken-and-egg.

## Progress

- [x] (2026-04-18) Step 1 — `.claude/prompts/ROADMAP-ORCHESTRATOR.md` rewritten in place: active-orchestrator phasing, status-lock / triage / escape-hatch subsections, Appendix A parallel exception.
- [x] (2026-04-18) Step 2 — `.claude/prompts/SESSION-IMPLEMENTER.md` updated: four-envelope "Incoming messages from the orchestrator" section, status-lock clarifications in Workflow steps 7–8, sub-agent-spawning note in "Four-layer flow" section.
- [x] (2026-04-18) Step 3 — `.claude/prompts/ROADMAP-ARCHITECT.md` updated: one-paragraph insert after the `## Overview` bullet in Mode A on declaring `execution: parallel-sessions-allowed`.
- [x] (2026-04-18) Step 4 — `HUMANS.md` updated: two-paragraph insert at the top of "Session flow" on active orchestrator + status lock.
- [x] (2026-04-18) Step 5 — `php artisan docs:check` clean; `vendor/bin/pint --dirty --format agent` clean (no-op); acceptance greps confirm all four envelopes, Agent invocation, three new subsections, and Appendix A are present; PLANS.md row move + status flip happen at commit handoff.

## Implementation log

### Cluster 1 — orchestrator template rewritten

Rewrote `.claude/prompts/ROADMAP-ORCHESTRATOR.md` in place. Net change: 168 → 246 lines. The file still opens with the Purpose / How to use / Prerequisites block, preserves the six-phase loop, preserves the State-you-maintain and First-task sections. What changed:

- Prerequisites block now names both the architect handoff AND the codex plugin install steps.
- Read-first list grew one entry (codex plugin command surface) and one entry (the SESSION-IMPLEMENTER template itself, since the orchestrator now fills its body).
- Phase 1 replaced: composes the brief, then spawns the sub-agent via `Agent(subagent_type: "general-purpose", run_in_background: true, prompt: "<SESSION-IMPLEMENTER template filled>")`. Records the returned id for use in later phases.
- Phase 2 replaced: review is shared with both developer and sub-agent (via `SendMessage`). Envelope name `[ORCHESTRATOR → IMPLEMENTER — PLAN REVIEW]` references the implementer template's "Incoming messages" section (Cluster 2 writes that section).
- Phase 3 replaced: approval is a `SendMessage` envelope, not a developer-paste.
- Phase 4 unchanged in shape; added explicit envelope reuse for "please address X first" mid-stream.
- Phase 5 replaced: orchestrator invokes `/codex:review --background` directly, polls `/codex:status`, reads `/codex:result`. Adversarial review gated by session surface area. Status-lock flip (`review → active → review`) is called out explicitly in the fix-routing step.
- Phase 6 unchanged in shape; close instruction now flows via the `[ORCHESTRATOR → IMPLEMENTER — CLOSE]` envelope.
- Rules of engagement: three new subsections (Status lock and serial execution / Triage authority / Context escape hatch) at lines 158, 168, 177. The original rules list kept all eight bullets ("never write code", "never approve unilaterally", etc.).
- Appendix A added at line 222: parallel-sub-agent exception, off by default, requires `execution: parallel-sessions-allowed` in the roadmap frontmatter plus overview justification.

### Cluster 2 — SESSION-IMPLEMENTER.md updated

Three targeted edits to `.claude/prompts/SESSION-IMPLEMENTER.md`:

1. Appended one paragraph to "The four-layer flow you operate in" noting that the orchestrator typically spawns the sub-agent via the `Agent` tool and continues it via `SendMessage`, with a forward reference to the new "Incoming messages" section.
2. Re-wrote Workflow steps 7–8 to state explicitly that the implementer flips `active → review` (and flips `active → review` again after each codex fix round) but **never** flips `review → active` back. That transition is the orchestrator's alone via envelope `[ORCHESTRATOR → IMPLEMENTER — CODEX FIX, ROUND N]`.
3. Added a new top-level "## Incoming messages from the orchestrator" section between "## Workflow" and "## Core principles". Four envelopes (`PLAN REVIEW`, `APPROVED, IMPLEMENT`, `CODEX FIX, ROUND N`, `CLOSE`) plus a "Refusal clause" at the bottom asserting the implementer holds the line on locked decisions / invariants / scope / quality-bar rules even against the orchestrator.

Confirmed by grep: all four envelope headers appear in both the orchestrator template (as `SendMessage` triggers) and the implementer template (as receivers). No drift.

### Cluster 3 — ROADMAP-ARCHITECT.md updated

One-paragraph insert after the `## Overview` bullet in the Mode-A "Body — match the shape" section. The paragraph states when to declare `execution: parallel-sessions-allowed` (both independence and tests-pass acceptance required), cites `ROADMAP-E2E.md` as the canonical fit, and rules out mixed roadmaps like `ROADMAP-PAYMENTS.md`. Points at Appendix A of the orchestrator template for the full semantics.

### Cluster 4 — HUMANS.md updated

Two-paragraph insert at the top of "## Session flow (how I actually work)", before the numbered list. First paragraph: one orchestrator per roadmap, the `Agent` + `SendMessage` + `/codex:review --background` mechanics, and the two human gates (plan approval, commit/push). Second paragraph: the status lock semantics and the parallel exception. Existing numbered list and everything below it unchanged.

### Cluster 5 — verification

- `php artisan docs:check` → `docs:check — clean. Frontmatter, indices, and bucket policy all agree.`
- `vendor/bin/pint --dirty --format agent` → `{"result":"pass"}` (no PHP touched — expected no-op).
- Acceptance greps:
  - `Agent(` appears at `.claude/prompts/ROADMAP-ORCHESTRATOR.md:59` (Phase 1 tool-call shape) and `:235` (Appendix A).
  - `[ORCHESTRATOR → IMPLEMENTER — ...]` envelope strings appear symmetrically in both templates for all four envelopes.
  - Status-lock transitions (`planning → active`, `active → review`, `review → active`, `review → shipped`) appear consistently across both templates; only-the-orchestrator-flips-review-to-active invariant stated at `ROADMAP-ORCHESTRATOR.md:110`, `:166` and `SESSION-IMPLEMENTER.md:215`, `:242` without contradiction.
  - Three new Rules subsections at `ROADMAP-ORCHESTRATOR.md:158`, `:168`, `:177`; Appendix A at `:222`.
  - `execution: parallel-sessions-allowed` referenced in all three templates + HUMANS.md.
- PLANS.md row status column will be updated and moved from `## In flight` to `## Shipped` when the developer commits; plan `status: active → shipped` flip and final `commits: [...]` happen in the same edit.

## Close note / retrospective

- **Status on close:** `shipped`.
- **Final commit:** to be filled in when the developer commits this session. All five touched files (`.claude/prompts/ROADMAP-ORCHESTRATOR.md`, `.claude/prompts/SESSION-IMPLEMENTER.md`, `.claude/prompts/ROADMAP-ARCHITECT.md`, `HUMANS.md`, `docs/plans/PLAN-WORKFLOW-AGENTIC-ORCHESTRATOR.md`) land together with the `docs/PLANS.md` row move. The hash goes into `commits: [...]` at commit time.
- **Test suite delta:** none. No application code changed. Baseline 693 / 2814 unchanged.
- **Bundle / build impact:** none. No PHP, no frontend. `npm run build` and `php artisan wayfinder:generate` not run — nothing would change. `vendor/bin/pint --dirty --format agent` run anyway, no-op as expected.
- **Carry-overs:** none queued to `docs/BACKLOG.md`. Two items to watch during first dogfood (PAYMENTS-1):
  1. The 500k-token escape-hatch threshold is a guess. If the orchestrator trips it mid-session on a single-session scope, bump to ~600k; if it never trips across an entire roadmap, consider dropping to ~400k to recycle sooner.
  2. The `/codex:review` slash-command invocation path assumes Claude can issue slash commands inside its own responses inside an orchestrator sub-agent. If the plugin's companion-script path (`node .../codex-companion.mjs`) is required instead, patch Phase 5 of `.claude/prompts/ROADMAP-ORCHESTRATOR.md`.
- **HANDOFF:** skipped per `.claude/CLAUDE.md` rule — this session does not change shipped product / runtime state, and the workflow change is orchestrator-internal rather than a canonical-reading-order change. The four-layer framing, status taxonomy, and session flow all remain as-documented in `docs/README.md`.
- **Post-implementation review:** skipped. This is a workflow-only session; the living-section carve-out in `docs/README.md` § "Session lifecycle in a plan file" permits omitting `## Post-implementation review`. Running codex against the meta-session would require the orchestrator loop we are building to review itself, which is circular.
- **Lessons learned:** The status field doubles cleanly as the serial-execution lock without any new infrastructure — the convention was already in place; this session just hardened the semantics. The decision to spawn one sub-agent per session and continue it via `SendMessage` (rather than spawn-per-phase) is the single largest simplification: it removes re-read cost, preserves tacit knowledge, and makes the status lock the only state anyone has to track. Codex plugin invocation via slash commands matches the ergonomics the developer would use by hand; no wrapper layer required.
