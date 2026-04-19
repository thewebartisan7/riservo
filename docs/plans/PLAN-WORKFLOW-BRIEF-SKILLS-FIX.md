---
name: PLAN-WORKFLOW-BRIEF-SKILLS-FIX
description: "Codex-review follow-up: fix six findings against the workflow-orchestrator + brief-skills templates"
type: plan
status: shipped
created: 2026-04-19
updated: 2026-04-19
commits: []
supersededBy: null
---

# PLAN — WORKFLOW-BRIEF-SKILLS-FIX — Codex-review remediations against the orchestrator + implementer + brief-skill templates

## Purpose

Codex adversarial review on the two shipped workflow sessions (`PLAN-WORKFLOW-AGENTIC-ORCHESTRATOR` and `PLAN-WORKFLOW-BRIEF-SKILLS`) surfaced six findings: two critical, three significant, one minor. Every finding is routable as a clear bug per the triage rule — all cite concrete contradictions or concrete technical facts, none re-open a locked scope decision. This session lands all six fixes in the live templates and the architect SKILL.md so the first real dogfood against ROADMAP-PAYMENTS does not stall on broken execution contracts.

Observable after: the orchestrator template's Phase 5 uses the codex-companion script via Bash (not slash commands); the commit contract is developer-only everywhere, with no implementer carve-out; each of the four orchestrator→implementer envelopes has a strict body schema mirrored across both templates; the Mode-B detector has explicit 0/1/>1 branches; the escape-hatch brief covers mid-session state; the triage "clear bug" list excludes schema/contract/refactor changes; the parallel exception is session-scoped.

## Context

Codex findings verbatim (source: developer's paste from `codex` CLI, 2026-04-19):

**Critical**
1. **Commit authority internally contradictory.** Orchestrator says "developer approves plans and commits code"; implementer says "commit frequently" and has an "if the developer has explicitly told you to commit autonomously" carve-out. Locations: `ROADMAP-ORCHESTRATOR.md:13,88,149`; `SESSION-IMPLEMENTER.md:213,237,287`.
2. **Phase 5 slash-command invocation is wrong.** Plugin command `review.md` declares `disable-model-invocation: true` and shells out to `node "${CLAUDE_PLUGIN_ROOT}/scripts/codex-companion.mjs" review "$ARGUMENTS"` via Bash. Same `disable-model-invocation: true` holds for `adversarial-review`, `status`, `result`, `cancel` (verified: `/Users/mir/.claude/plugins/marketplaces/openai-codex/plugins/codex/commands/*.md`). Agents cannot self-invoke these as slash commands. Location: `ROADMAP-ORCHESTRATOR.md:102`.

**Significant**
3. **Envelope protocol not actually symmetric.** Orchestrator reuses `[PLAN REVIEW]` mid-stream for pre-codex remediation, but the implementer's [PLAN REVIEW] contract is "revise the plan in place"; the body shapes of `[CODEX FIX, ROUND N]` and `[CLOSE]` drift across the two templates.
4. **Parallel exception too coarse.** One roadmap-level `execution: parallel-sessions-allowed` flag lets the orchestrator parallelise all sessions of the roadmap; "mostly independent except one shared step" slips through.
5. **Mode-B detector underspecified.** `SKILL.md:26` handles 0-match and 1-match, but not >1 match; also "if candidate is already active, default to Mode A" is too permissive — silently preps a duplicate roadmap for the same topic.
6. **Escape-hatch brief incomplete.** Lists sessions-shipped, in-flight, next D-NNN — but no current session id/title, plan path, current phase, latest implementer hash, active codex job id, pending developer decisions.

**Minor**
7. **Triage "clear bug" list too permissive.** "Broken test, syntax error, obvious logic error, documented-invariant violation" — should exclude schema/data migrations, public contract changes, flaky-test rewrites, broad refactors.

The two shipped plans (`PLAN-WORKFLOW-AGENTIC-ORCHESTRATOR.md`, `PLAN-WORKFLOW-BRIEF-SKILLS.md`) stay frozen at their shipped state — per the docs convention, shipped plans are historical records. Their `## Post-implementation review` sections were intentionally omitted at close (docs-only carve-out + meta-circularity of reviewing the review loop). The findings live in this plan's Context section; the fix trace lives in this plan's Implementation log.

Per developer guidance (2026-04-18), all three workflow plans (`AGENTIC-ORCHESTRATOR`, `BRIEF-SKILLS`, and this `BRIEF-SKILLS-FIX`) are disposable after the developer commits + pushes. Only codebase plans are durable history; docs/workflow refactoring plans can be deleted post-push. This plan's quality bar is "enough shape to carry through commit", not "polished for posterity".

## Scope

### In

- Orchestrator template fixes: Phase 5 codex invocation via Bash/companion-script; developer-only commit contract; pre-codex remediation envelope; triage-bucket exclusion list; escape-hatch brief extension; session-scoped parallel exception.
- Implementer template fixes: developer-only commit wording; strict body schemas for all four envelopes (mirrored from orchestrator).
- `/riservo-brief-architect/SKILL.md` fix: explicit 0/1/>1 match branches; refuse implicit Mode A on active-roadmap topic collision.
- `/riservo-brief-orchestrator/SKILL.md` fix: read the roadmap's `parallel_sessions:` frontmatter key (if present) and pass it through to the orchestrator brief.
- `.claude/skills/riservo-brief-architect/assets/ROADMAP-ARCHITECT.md` fix: update the parallel-exception paragraph to describe the new session-scoped key.
- `HUMANS.md` fix: update the parallel-exception one-liner to reflect session-scoped semantics.

### Out

- No change to `docs/README.md` or `docs/HANDOFF.md` at *this plan's* authorship time — but see Close note: subsequent codex-review rounds on this commit stack pulled `HANDOFF.md` into scope and made minor edits.
- No retroactive edit to the two shipped plans.
- No `docs/reviews/` file — per-session codex findings live inline; cross-cutting audits only use that directory.
- No application code, no tests.
- No new decisions (`D-NNN`). All fixes live in template prose.
- No scope expansion beyond the six findings. If a fix surfaces a seventh problem, it goes to the Risks section and a follow-up, not in-scope.

## Key design decisions

Six fixes, two with sub-choices flagged for developer approval (marked **[DEVELOPER DECISION]**).

### 1. Commit authority — normalize to developer-only everywhere

Remove every "commit" verb from the implementer template; replace with "stage + report". Remove the "if the developer has explicitly told you to commit autonomously" carve-out in `SESSION-IMPLEMENTER.md:287`. Implementer's handoff becomes: "work is staged at `[description of files + diffs]`; ready for developer commit." Orchestrator's Phase 4 waits for a **developer-supplied commit hash** before advancing to Phase 5; the hash is what flips `status: active → review` (the implementer proposes the transition in its report, the developer confirms by committing, the orchestrator only runs codex once the hash exists).

Rationale: the existing ambiguity is a security-flavored bug. "Commit frequently" plus a carve-out for agent commits lets a confused implementer write history the developer didn't agree to. The cost of the fix is small (prose-only) and the semantic is crisp.

### 2. Phase 5 codex invocation — companion script via Bash

Rewrite Phase 5 step 2 of the orchestrator template from:

```
Invoke codex directly: /codex:review --background (or /codex:adversarial-review …)
```

to:

```
Invoke the codex companion script via Bash. The plugin's slash commands all declare
`disable-model-invocation: true`, so agents cannot self-invoke them — the canonical
machine path is the script the plugin's commands shell out to.

Bash({
  command: `node "${CLAUDE_PLUGIN_ROOT}/scripts/codex-companion.mjs" review [--base <ref>] --background`,
  description: "Codex review",
  run_in_background: true
})

For adversarial framing on security/concurrency/payments sessions, swap `review` for
`adversarial-review` and pass focus text as a trailing argument. Resolve CLAUDE_PLUGIN_ROOT
to `/Users/mir/.claude/plugins/cache/openai-codex/codex/<version>/` or use the env var if
the runtime exports it.

Poll via `node .../codex-companion.mjs status <task-id>`; read via
`node .../codex-companion.mjs result <task-id>`; cancel via
`node .../codex-companion.mjs cancel <task-id>`.

Host-dependent shorthand: if the orchestrator runtime actually supports self-invoking
slash commands (it does not in Claude Code as of this writing — verify via /plugins),
the shorter `/codex:review --background` form may substitute for the Bash call. Default
to the Bash path for portability.
```

Rationale: this is the concrete technical correction codex surfaced. The Bash path works today; the slash-command path does not.

### 3. Envelope protocol — strict body schemas + new pre-codex envelope **[DEVELOPER DECISION 1]**

Two parts:

**Part A (no judgment):** each of the four existing envelopes (`PLAN REVIEW`, `APPROVED, IMPLEMENT`, `CODEX FIX, ROUND N`, `CLOSE`) gets an explicit **Body schema:** block that both templates mirror verbatim. Example for `[CODEX FIX, ROUND N]`:

```
Body schema:
- Round number (integer, 1-indexed)
- Codex task id (the id returned by the companion script)
- Finding list, each finding: { file path, line range, quoted offending text or absence, expected fix in concrete terms, severity (critical/significant/minor) }
- Any developer directives from pause rounds, if applicable
```

**Part B (judgment call):** the mid-stream "address X before codex runs" case currently overloads `[PLAN REVIEW]`. Codex proposes a new envelope:

- **Option A (I lean here):** introduce `[ORCHESTRATOR → IMPLEMENTER — PRE-CODEX REMEDIATION]`. Fires between Phase 4 and Phase 5 when the orchestrator spots a red flag in the implementer's report and wants a pre-codex cleanup pass. Semantics: implementer fixes, stages, reports, orchestrator verifies, proceeds to Phase 5. Clean separation from `[PLAN REVIEW]`.
- **Option B:** keep overloading `[PLAN REVIEW]`. Cheaper in template real-estate but semantically drifts.

Recommend A. Envelope count goes from 4 to 5; clarity gain is worth the extra page.

### 4. Parallel exception — session-scoped granularity **[DEVELOPER DECISION 2]**

Replace roadmap-level flag `execution: parallel-sessions-allowed` with a session-scoped declaration:

- **Option A (I lean here):** flat list. Frontmatter key: `parallel_sessions: [S1, S2a, S2b]` — the named sessions may run in parallel; everything else stays serial. Simple to declare, easy to reason about.
- **Option B:** groups. Frontmatter key: `parallel_groups: [[S1, S2a], [S2b, S3]]` — each inner list is a group of parallel sessions; groups run serially relative to each other. More expressive ("these two are pairable, these other two are pairable, but the two pairs must go one after the other"), more frontmatter complexity.

Recommend A. Adopt B only if a real roadmap demands it.

Architect template's parallel-exception paragraph updates to teach the new key. `ROADMAP-ORCHESTRATOR.md` Appendix A updates: the orchestrator reads the key, may parallelise ONLY the named sessions, everything else serial. `/riservo-brief-orchestrator/SKILL.md` step 3 adds: extract `parallel_sessions:` (if present) and forward it into the orchestrator brief.

### 5. Mode-B detector — explicit match branches + refuse active collisions

Rewrite `/riservo-brief-architect/SKILL.md` step 2:

```
- Read docs/ROADMAP.md. Count matching candidate rows in ## In flight whose description
  or name share a keyword with the developer's topic.
  - 0 matches: default to Mode A.
  - 1 match: open that roadmap's frontmatter.
    - status: draft or planning → offer Mode B (stress-test) or Mode A (start fresh).
    - status: active → REFUSE implicit Mode A. Tell the developer: "ROADMAP-[NAME] is
      already active. Mode A would create a duplicate. Options: (1) direct-edit the
      active roadmap for minor changes, (2) supersede it with a new draft whose
      supersededBy: points at the new file, (3) explicitly confirm you want a
      parallel/sibling roadmap on the same topic." Ask before proceeding.
    - status: shipped → confirm with the developer that the prior roadmap is truly done;
      if yes, proceed with Mode A. A shipped roadmap does not block a new draft on the
      same topic, but confirming prevents accidental re-draft of completed work.
  - >1 matches: list them to the developer and ask which one (or none, for a fresh Mode A).
```

### 6. Escape-hatch brief — extend with mid-session state

Augment the orchestrator template's escape-hatch section. The brief gains:

- Current session id + title (e.g. "PAYMENTS-2a — Payment at Booking Happy Path").
- Current plan path (`docs/plans/PLAN-PAYMENTS-2A-*.md`).
- Current phase (1 / 2 / 3 / 4 / 5 / 6).
- Latest implementer commit hash, if Phase 4+ has happened.
- Active codex task id + base ref, if Phase 5 is mid-flight.
- Pending developer decisions (any judgment-call findings the developer has not yet ruled on).
- Sub-agent id (for SendMessage continuity).

Plus a rule: **if the orchestrator is already above the threshold at the start of Phase 5**, write the handoff brief BEFORE invoking the long-running codex job — not at the next phase boundary. A long codex round can take many minutes during which the orchestrator accumulates polling context; crossing the threshold mid-codex is worse than triggering a clean handoff first.

### 7. Triage "clear bug" list — add exclusion bucket

Extend the `## Triage authority` subsection of `ROADMAP-ORCHESTRATOR.md`. Current list of clear bugs stays. Add explicit "do NOT route autonomously — pause for developer" list:

- Schema / data migration changes (touching tables, columns, indexes, or production data).
- Public contract changes (API endpoints, webhook payload shapes, client-visible JSON, exported types).
- Flaky-test rewrites (a test that intermittently fails may be a real bug, not a test bug; developer decides).
- Broad refactors (changes touching more than ~5 files or crossing a domain boundary).

These overlap somewhat with "judgment calls" but are worth calling out specifically because codex is particularly prone to confidently propose them.

## Implementation steps

Single commit. The fixes are small enough and logically coupled (all on the workflow templates) that splitting commits adds noise without splitting risk.

### Step 1 — `ROADMAP-ORCHESTRATOR.md` fixes

One file, six edits (per findings 1, 2, 3A, 3B, 4, 6, 7). Order inside the file:
- Prelude + read-first lines 3, 5, 8: drop the "directly invoke slash commands" phrasing, replace with "via the codex-companion script".
- Phase 4 "Implementation report": change "Plan `status: review`" reporting to "ready for developer commit"; advance to Phase 5 only after developer supplies hash.
- Phase 5 step 2: rewrite per design decision 2 (Bash/companion-script path).
- Phase 5 step 6 (triage routing): align with finding 7 exclusion bucket.
- Rules of engagement "Status lock" subsection: tighten who-flips-what in line with the developer-hash gate.
- Rules of engagement "Triage authority" subsection: add exclusion bucket.
- Rules of engagement "Context escape hatch" subsection: extend brief contents + add pre-Phase-5 immediate-handoff rule.
- Appendix A: rewrite to use `parallel_sessions: [list]` (Option A per design decision 4).
- New Phase-4-to-5 interstitial subsection or inline paragraph defining `[ORCHESTRATOR → IMPLEMENTER — PRE-CODEX REMEDIATION]` envelope (design decision 3 Option A).

### Step 2 — `SESSION-IMPLEMENTER.md` fixes

One file, three edits:
- Workflow steps 5, 7, 8, 9: remove "commit" verbs; replace with "stage + report"; remove the `(Exception: if the developer has explicitly told you to commit autonomously…)` carve-out in the Rules of engagement.
- "## Incoming messages from the orchestrator" section: add strict Body schema block to each of the four envelopes; add the new fifth envelope `[ORCHESTRATOR → IMPLEMENTER — PRE-CODEX REMEDIATION]`.
- Implementer handoff-protocol lines: update to "work staged, ready for commit" instead of "implementation committed at [hash]".

### Step 3 — `/riservo-brief-architect/SKILL.md` fix

Step 2 rewrite per design decision 5 (0/1/>1 match branches + active-collision refusal).

### Step 4 — `ROADMAP-ARCHITECT.md` asset fix

Parallel-exception paragraph rewrite: replace `execution: parallel-sessions-allowed` language with `parallel_sessions: [list]`; update the example and rationale.

### Step 5 — `/riservo-brief-orchestrator/SKILL.md` fix

Step 3 addition: extract `parallel_sessions:` (if present) and forward into the orchestrator brief. Small paragraph-size edit.

### Step 6 — `HUMANS.md` fix

Parallel-exception one-liner rewrite — same rename as step 4.

### Step 7 — Close

- `php artisan docs:check` clean.
- `vendor/bin/pint --dirty --format agent` clean (expected no-op).
- Flip this plan `status: active → shipped`, bump `updated:`.
- Move `docs/PLANS.md` row from `## In flight` to `## Shipped / Workflow`.
- No HANDOFF change (the workflow entry points and mechanics didn't change at the developer-visible level; only internal contracts tightened).
- Commits list gets filled when the developer commits.

## Files to create / modify

### Create

- `docs/plans/PLAN-WORKFLOW-BRIEF-SKILLS-FIX.md` — this file.

### Modify

- `.claude/skills/riservo-brief-orchestrator/assets/ROADMAP-ORCHESTRATOR.md`
- `.claude/skills/riservo-brief-orchestrator/assets/SESSION-IMPLEMENTER.md`
- `.claude/skills/riservo-brief-architect/assets/ROADMAP-ARCHITECT.md`
- `.claude/skills/riservo-brief-architect/SKILL.md`
- `.claude/skills/riservo-brief-orchestrator/SKILL.md`
- `HUMANS.md`
- `docs/PLANS.md` — row added at plan start, moved to Shipped at close.

### Delete

- None.

## Tests

No programmatic tests; acceptance is mechanical.

| Check | Expected |
|---|---|
| `php artisan docs:check` | clean |
| `vendor/bin/pint --dirty --format agent` | no-op pass |
| Grep `disable-model-invocation` usage in `ROADMAP-ORCHESTRATOR.md` | Zero direct slash-command invocations; companion-script Bash calls instead |
| Grep commit verbs in `SESSION-IMPLEMENTER.md` | Zero "commit frequently" or "if the developer has explicitly told you to commit autonomously" phrasings |
| Count envelope headers | 5 envelopes in both templates (adds `[PRE-CODEX REMEDIATION]`) |
| Grep `execution: parallel-sessions-allowed` | Zero matches; replaced by `parallel_sessions:` |

## Validation & acceptance

After this session, a developer running `/riservo-brief-orchestrator` against any active roadmap receives a prompt whose orchestrator, when pasted into a fresh chat, will:

1. Invoke codex via Bash/companion-script, not slash commands.
2. Wait for a developer-supplied commit hash between Phase 4 and Phase 5.
3. Understand five SendMessage envelopes, not four, with explicit body schemas.
4. Handle a roadmap's `parallel_sessions: [list]` frontmatter, if present.
5. Route "clear bugs" autonomously but pause on migrations, contract changes, flaky tests, broad refactors.
6. Write a mid-session-complete handoff brief if the context threshold is hit, and do so before a long codex round rather than inside it.

A developer running `/riservo-brief-architect` with a topic keyword matching an already-active roadmap receives a refusal message, not a silent Mode-A duplicate.

## Decisions to record

None. Six template-prose fixes; no architectural `D-NNN` warranted.

## Open questions

Two sub-choices flagged above for developer approval before implementation:

1. **[DEVELOPER DECISION 1]** — envelope semantics: Option A (new `[PRE-CODEX REMEDIATION]` envelope, 4→5 total) or Option B (overload `[PLAN REVIEW]`, keep 4)?
2. **[DEVELOPER DECISION 2]** — parallel exception: Option A (flat `parallel_sessions: [list]`) or Option B (grouped `parallel_groups: [[list], [list]]`)?

I recommend A for both. If you want B on either, tell me which and I'll re-scope the matching step.

## Risks & notes

- **Risk: companion-script path-resolution.** The Bash call uses `${CLAUDE_PLUGIN_ROOT}`; if the orchestrator agent's shell does not have that env var exported, the resolution fails. Mitigation: the template instructs the orchestrator to either rely on the env var or resolve to the absolute path `/Users/mir/.claude/plugins/cache/openai-codex/codex/<version>/scripts/codex-companion.mjs` (version-dependent; version can be discovered from `installed_plugins.json`). A small brittle edge; worth flagging as a first-dogfood watchpoint.
- **Risk: five-envelope protocol feels heavy.** If in practice the `[PRE-CODEX REMEDIATION]` envelope fires rarely, it bloats the template. Mitigation: document it clearly with "when to use" framing. If after two real roadmaps it hasn't fired once, consolidate back into `[PLAN REVIEW]` in a future session.
- **Risk: developer-hash gate slows Phase 5.** Today the orchestrator can run codex immediately; under the fix, it must wait for the developer to commit before `/codex:review` runs. That's the right contract but it means the developer is a synchronous participant between Phase 4 and Phase 5. Mitigation: accept the cost; it matches the "two gates" principle we've locked.
- **Note: this plan is disposable post-push per developer guidance.** Keep that in mind when weighing polish-vs-speed tradeoffs on the Close note and Implementation log.
- **Note: no codex pass planned on this session.** Same meta-circularity reasoning as the prior two workflow sessions: running the orchestrator loop against its own fix is confusing. The developer may override.

## Progress

- [x] (2026-04-19) Plan drafted, six fixes scoped, two sub-choices flagged.
- [x] Developer approved with Option A on both open decisions (new `[PRE-CODEX REMEDIATION]` envelope; flat `parallel_sessions: [list]`).
- [x] Step 1 — `ROADMAP-ORCHESTRATOR.md` edits applied in the prior chat.
- [x] Step 2 — `SESSION-IMPLEMENTER.md` edits applied in the prior chat.
- [x] Step 3 — `riservo-brief-architect/SKILL.md` edit applied in the prior chat.
- [x] Step 4 — `ROADMAP-ARCHITECT.md` asset edit applied in the prior chat.
- [x] Step 5 — `riservo-brief-orchestrator/SKILL.md` edit applied in the prior chat.
- [x] Step 6 — `HUMANS.md` edit applied in the prior chat.
- [x] Step 7 — status flipped, `docs/PLANS.md` row moved, `docs:check` clean. (Pint: no PHP touched.)

## Implementation log

The six fixes were applied in the same 2026-04-19 chat that drafted this plan, across the files listed in Progress. Close was signalled before commit; the file was flipped to `status: shipped` and indexed accordingly. **Commits were intentionally left for the developer** — this plan is part of a larger workflow-template stack that the developer reviews and commits as one unit.

A subsequent 2026-04-19 chat (the codex-dogfood pass described in the Close note below) ran five adversarial-review rounds against the same uncommitted stack, surfaced 11 additional findings against the workflow templates and read-first docs, and applied fixes in place. Those fixes are NOT tracked in this plan file's Progress — they are a separate review-and-remediate loop, not part of the original six-finding scope. `docs/HANDOFF.md` and `HUMANS.md` received edits during that loop; `docs/README.md` was not touched.

## Close note / retrospective

This plan was the third in a three-plan workflow-templates stack (PLAN-WORKFLOW-AGENTIC-ORCHESTRATOR → PLAN-WORKFLOW-BRIEF-SKILLS → PLAN-WORKFLOW-BRIEF-SKILLS-FIX).

A separate 2026-04-19 chat, kicked off to dogfood the codex-companion plugin's `/codex:adversarial-review` against this same uncommitted stack, ran multiple rounds and surfaced findings against the new workflow docs/skills (execution-contract issues, self-blocking gates, stale example data, envelope count drift, HANDOFF-vs-template contradictions, etc.). Every finding was routable; every fix was applied in place without scope creep. That loop converged when codex returned no-more-findings; this plan was updated in the same pass to accurately reflect the extended reality.

Retrospective: the meta-workflow sessions run on this template stack surfaced real bugs that the architect round alone had missed. The codex adversarial-review loop was a materially better quality gate than any internal-review pass that preceded it. Carry-over learning: for any future workflow-templates session, run an adversarial-review loop as the final pre-commit gate rather than relying on architect self-review.
