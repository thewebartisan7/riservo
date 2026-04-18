---
name: PLAN-DOCS-RESTRUCTURE
description: Move docs to status-driven model with index files; dissolve docs/archive/; normalise frontmatter across all process docs
type: plan
status: shipped
created: 2026-04-17
updated: 2026-04-17
---

# PLAN — DOCS-RESTRUCTURE — Status-driven docs with indices

> Status: Draft, awaiting developer approval.
> Scope: documentation-only. No application code changes.
> Baseline: `main` at `9e984bf` — MVP fully shipped, `docs/roadmaps/ROADMAP-PAYMENTS.md` is next but not started.

---

## Context

Today every roadmap, plan, and review round lives in one of two physical states: **active** (in `docs/roadmaps/`, `docs/plans/`, `docs/reviews/`) or **archived** (moved to `docs/archive/{roadmaps,plans,reviews}/` on close). The move-on-close step is a manual ritual the developer finds tedious; it also fragments the lifecycle of a session across multiple locations and across commits, and it breaks any cross-reference that hard-codes an old path.

Parallel to this, `docs/DECISIONS.md` already demonstrates a pattern that works well at scale: a short, stable index that points at topical files. Decision IDs never move; only the index grows. We want the same shape for roadmaps and plans.

The developer evaluated three models (status quo / status-driven indices / GitHub-native) and chose status-driven with indices. This plan turns that choice into concrete structure.

---

## Goal

Replace the "move to archive on close" workflow with a **status-in-frontmatter + index-file** model, so that:

- A file's path never changes for lifecycle reasons. Only its frontmatter `status:` changes.
- Discoverability comes from two new index files — `docs/ROADMAP.md` and `docs/PLANS.md` — modelled on `docs/DECISIONS.md`.
- A plan file holds the **entire lifecycle** of a session in one place: pre-impl plan, implementation notes, post-impl review, close note.
- The switchover costs a single session; after that, no more physical moves at session close.
- Agent per-session token cost does not grow (indices are terse; files stay where they were; no new mandatory reads).

End state: `docs/archive/` no longer hosts plans / roadmaps / reviews. Either the subtree is empty and retired, or (recommended) it is dissolved and its contents are moved back to their canonical locations with `status: shipped | superseded` frontmatter.

---

## Scope

### In

1. Introduce a YAML frontmatter convention across all process docs (roadmaps, plans, review artefacts, HANDOFF).
2. Create `docs/ROADMAP.md` and `docs/PLANS.md` as stable indices.
3. Define and apply a closed six-state status taxonomy.
4. Dissolve `docs/archive/{plans,roadmaps,reviews}/` — move files back to their canonical locations, apply `status:` frontmatter, update indices.
5. Normalise the three currently-active roadmaps (PAYMENTS, E2E, GROUP-BOOKINGS) to the new shape.
6. Update agent guidance files (`CLAUDE.md`/`AGENTS.md` pairs) to reflect the new convention.
7. Rewrite `docs/README.md` to describe the new reading order and the new status-flip workflow.
8. Rewrite the session-done checklist in `.claude/CLAUDE.md` so agents flip status and update the index instead of moving files.
9. Delete the obsolete `docs/plans/PLAN-DOCS-CLEANUP.md` (developer-confirmed).
10. Handle orphans: `docs/PROMPT-R8-QA.md`, `docs/reviews/E2E-IMPLEMENTATION-REPORT.md`, and `HUMANS.md` stale references.
11. Fold the E2E close-out report into `ROADMAP-E2E.md` and annotate `route-coverage.md` as the live status ledger.
12. Update `HANDOFF.md` and roll-in the 200-line carry-over rule.

### Out

- No changes to `docs/SPEC.md`, `docs/ARCHITECTURE-SUMMARY.md`, `docs/UI-CUSTOMIZATIONS.md`, `docs/DEPLOYMENT.md`, `docs/BACKLOG.md`, `docs/TESTS.md` — these are content docs, not process docs. (Exception: fix any one-line stale reference they contain, nothing more.)
- No changes to `docs/decisions/*.md` (topical decision files) — the pattern works, `DECISIONS.md` is the model we are *copying*.
- No retroactive rewriting of the body of any archived plan. Only a frontmatter block gets prepended. The narrative stays intact as historical record.
- No GitHub Issues / Milestones / Projects / Wiki integration. The developer will evaluate the hybrid separately.
- No application code changes. No database migrations. No Wayfinder regeneration.

---

## Key design decisions

### 1. Frontmatter shape — YAML triple-dash

**Decided**: YAML frontmatter at the top of every process doc.

```yaml
---
name: PLAN-MVPC-5-CALENDAR-INTERACTIONS
description: Drag / resize / click-to-create for the dashboard calendar
type: plan
status: shipped
created: 2026-04-17
updated: 2026-04-17
commits: [21f7e00, eae8051]
supersededBy: null
---
```

**Why YAML over blockquote**:

- Grep-stable: `grep -l "^status: active" docs/**/*.md` returns an exact file list, usable in a 10-line script.
- One self-contained block; renders as a code fence in GitHub but does not break Markdown elsewhere.
- Reserved convention (Jekyll, Hugo, Obsidian, MkDocs all parse the same syntax), so any future tooling can read it without custom parsing.
- Humans can still read it — it is not hidden metadata.

**Why not keep the current blockquote style** (`> Status: ...`): it varies file-by-file (some say "Shipped", some say "FULLY SHIPPED (2026-04-17)", some say "Superseded (Phase 2); Phase 3 historical"), grep misses variants, and it is fragile under editor reflow. We will keep the existing human-readable summary blockquote in the body (it is a useful lede), but the **authoritative** status is in frontmatter.

**Fields**:

| Field | Required | Notes |
|---|---|---|
| `name` | yes | Matches the filename minus `.md` |
| `description` | yes | One line; drives index tables |
| `type` | yes | `roadmap | plan | review | report | handoff` |
| `status` | yes | See taxonomy §2 |
| `created` | yes | `YYYY-MM-DD` — date file first committed |
| `updated` | yes | `YYYY-MM-DD` — date of last meaningful edit |
| `commits` | optional | Commit hashes once implementation lands (plans only) |
| `supersededBy` | optional | Path to successor file (when `status: superseded`) |
| `supersedes` | optional | Path to predecessor (mirror of `supersededBy`) |

Retro-normalised archive files can use `commits: []` or omit `commits:` entirely; we are not going to mine git for hashes we never recorded.

### 2. Status taxonomy — six states, closed set

| Status | Applies to | Meaning |
|---|---|---|
| `draft` | plan | The agent is still writing the plan; not yet ready for developer approval. |
| `planning` | plan, roadmap | Ready for approval / review; nothing is being implemented against it. |
| `active` | plan, roadmap | Approved and being implemented, or — for a roadmap — sessions are being delivered under it. |
| `review` | plan | Implementation committed; a post-implementation (codex) review is in progress. |
| `shipped` | plan, roadmap, review, report | Work is complete. File kept in place as historical record. |
| `superseded` | plan, roadmap | Replaced by another file; `supersededBy` points at the successor. |

**Why no `archived`**: it duplicates `shipped` + `superseded`. The substantive question about any finished doc is "done successfully" (→ `shipped`) vs "replaced" (→ `superseded`). A bare `archived` adds no signal.

**Why no `standing`**: a roadmap that stays alive across new features (e.g. `ROADMAP-E2E.md`) is just `active` with open scope. Inventing a status for it complicates the taxonomy for one file.

**Why keep `review`** even if it often collapses into `active` within hours: plan files are the single source of truth for the session lifecycle (§5). Flagging "implementation is in, codex is looking" is useful signal for an agent who picks up a follow-up. If the developer decides in practice that `review` is too short-lived to bother tracking, we can collapse it into `active` later with no schema break.

**Status transitions** (plans):

```
draft → planning → active → review → shipped
                     ↓
                 superseded
```

### 3. Index files — ROADMAP.md and PLANS.md

Modelled on `docs/DECISIONS.md`. Each file is:

- A short preface (how to use).
- One or more status-bucketed tables, so the "active" rows always render above the fold.
- No body content duplicated from the indexed files — it is an index, not a summary.

**`docs/ROADMAP.md` — proposed content**

```markdown
# Roadmap Index

Stable entrypoint for delivery roadmaps. Each row links to a file whose `status:` frontmatter is the authoritative state.

## Active

| File | Status | Scope | Updated |
|---|---|---|---|
| [ROADMAP-PAYMENTS](roadmaps/ROADMAP-PAYMENTS.md) | active | Customer-to-professional Stripe Connect Express payments (6 sessions) | 2026-04-17 |
| [ROADMAP-E2E](roadmaps/ROADMAP-E2E.md) | active | Pest 4 browser coverage. E2E-1..6 shipped; stays active for new features. Status ledger in `tests/Browser/route-coverage.md`. | 2026-04-17 |
| [ROADMAP-GROUP-BOOKINGS](roadmaps/ROADMAP-GROUP-BOOKINGS.md) | planning | Multi-customer per slot (workshops, classes). Post-MVP. | 2026-04-15 |

## Shipped

| File | Status | Scope | Updated |
|---|---|---|---|
| [ROADMAP-MVP-COMPLETION](roadmaps/ROADMAP-MVP-COMPLETION.md) | shipped | MVPC-1..5 (OAuth, Calendar sync, Cashier, Provider self-service, Calendar interactions) | 2026-04-17 |
| [ROADMAP-MVP](roadmaps/ROADMAP-MVP.md) | shipped | Original MVP, Sessions 1–11 | 2026-04-17 |

## Superseded

| File | Status | Supersedes / SupersededBy | Updated |
|---|---|---|---|
| [ROADMAP-FEATURES](roadmaps/ROADMAP-FEATURES.md) | superseded | → ROADMAP-MVP-COMPLETION | 2026-04-16 |
| [ROADMAP-CALENDAR](roadmaps/ROADMAP-CALENDAR.md) | superseded | → ROADMAP-MVP-COMPLETION (Phase 2) | 2026-04-16 |
```

**`docs/PLANS.md`** — same shape, one row per plan (≈ 32 rows). Bucketed by `status`. Given the pre-MVP plan population is all `shipped`, the "Shipped" table is long by definition; the "Active" / "In review" section stays short so an agent scanning it pays only the cost of reading the lede + the first table.

**Question the brief asked: include shipped/superseded rows or not?**

I recommend **include all, bucketed**. Reasons:

- Discoverability grows linearly with the index. An agent looking for "what PLAN-R-17 was about" has one place to look.
- Bucketing keeps agent reading cheap: if they only care about live work, they stop at the "Active" table.
- Indices are human-written and only change when someone flips a status; they do not drift silently.

If the index ever bloats past ~150 rows (unlikely for 1–2 years), we can split into `PLANS.md` + `PLANS-HISTORY.md` the way `DECISIONS.md` + `DECISIONS-HISTORY.md` does today. Not worth pre-optimising.

### 4. Physical archive policy — dissolve `docs/archive/{plans,roadmaps,reviews}/`

The brief explicitly invited questioning: *"Idealmente però vorrei spostare anche questi dove erano e applicare il frontmatter anche a loro, se non ci sono buone ragioni per tenerli."* I agree.

**Recommend**: dissolve the three process-doc subtrees under `docs/archive/`. Move files back to their canonical locations with `status: shipped | superseded` frontmatter.

- `docs/archive/plans/*.md` → `docs/plans/*.md` (31 files)
- `docs/archive/roadmaps/*.md` → `docs/roadmaps/*.md` (4 files)
- `docs/archive/reviews/*.md` → `docs/reviews/*.md` (4 files) — but see §6, reviews get re-scoped.
- `docs/archive/CLAUDE.md` and `docs/archive/AGENTS.md` → deleted; the archive guardrail is no longer needed.
- `docs/archive/` itself: keep the directory only if `docs/design/` relocation needs it; otherwise remove.

**Why this is safe** (verified during audit):

- No application code anywhere references `docs/archive/` paths. The only external references are in meta files (`.claude/CLAUDE.md`, `.agents/AGENTS.md`, `HUMANS.md`, `.claude/MAINTENANCE.md`) — all updated in this same session.
- Cross-references *between* archived plans (one plan citing another) break only if we change file basenames; we do not. A file formerly at `docs/archive/plans/PLAN-MVPC-3-CASHIER-BILLING.md` becomes `docs/plans/PLAN-MVPC-3-CASHIER-BILLING.md` — same basename, same content, new parent.
- Git history is preserved through `git mv`; `git log --follow` still works across the move.

**One-time cost**: ~39 files get a frontmatter block prepended. Body content is untouched. Diff is large but reviewable — one commit, clear message. The developer reads diffs; this is not a regression risk vector.

**Alternative considered and rejected**: keep `docs/archive/` intact, link entries from the new indices, normalise only the active roadmaps. This preserves the status quo with less churn, but leaves two failure modes: (a) agents looking for `PLAN-MVPC-3-*` search in the wrong place half the time, (b) a new session shipping today would need a decision — "do I move to archive now, or start the new convention from here?" — which is exactly the ambiguity the developer wants to kill. Dissolution closes the ambiguity in one pass.

**Edge case**: if during migration a file turns out to have zero historical value and is actively confusing (e.g. a stale initial prompt like `docs/PROMPT-R8-QA.md`), we can delete it instead. Individually flagged in §9.

### 5. Plan files as single source of truth for the session lifecycle

New standard section order for plan files authored *after* this restructure. Archived plans are **not** retrofitted — that is a historical pollution we accept.

```markdown
---
name: PLAN-...
description: ...
type: plan
status: draft
created: YYYY-MM-DD
updated: YYYY-MM-DD
commits: []
---

# PLAN — {ID} — {Title}

## Context

## Goal

## Scope
### In
### Out

## Key design decisions

## Implementation steps

## Files to create / modify

## Tests

## Verification

## Decisions to record     ← D-NNN list, cross-refs topical DECISIONS-*.md

## Open questions

## Risks & notes

---

## Implementation log

Appended during/after implementation by the agent. Deviations from plan, surprises, commit hashes per cluster. Free-form — not a template, but concrete.

## Post-implementation review

### Codex findings
### Follow-up commits

## Close note

- Status on close: shipped
- Final commit: {hash}
- Test suite delta: {X → Y}
- Bundle / build impact: {...}
```

The last three sections are **new**. They live in the same file as the plan because:

- Knowledge density: pre-impl plan + what actually happened + review findings are the three artefacts most often re-read together when returning to a feature.
- Stable URL: future PRs that touch the same area can link one path.
- The move-to-archive ritual was the *only* reason the plan had to be closed and a separate report file created.

### 6. `docs/reviews/` — narrow scope, keep folder

Today `docs/reviews/` holds the "active review round", and past rounds move to archive. The developer now considers that pattern (batched codex reviews of 10 sessions at once) an anti-pattern: too much noise, not enough signal. **Per-session review belongs in the plan file** (§5).

**Recommend**: re-scope `docs/reviews/` to **cross-cutting audits only** — the kinds of reviews that genuinely sit above any single session. Concrete examples:

- Pre-launch security review.
- Accessibility audit.
- Full-suite performance audit.
- Compliance sweep.

These are rare (≤ 1 per quarter) but real. Deleting the folder would force the next one to invent a location from scratch.

**Actions**:

- Rewrite `docs/reviews/CLAUDE.md` and `AGENTS.md` to describe the new narrow scope (no more "active review round").
- Move `REVIEW-1.md`, `REVIEW-2.md`, `ROADMAP-REVIEW-1.md`, `ROADMAP-REVIEW-2.md` back to `docs/reviews/` (out of archive) with `status: shipped`. These belong here conceptually even though the round-based pattern is dead — they *were* cross-cutting.
- The file `docs/reviews/E2E-IMPLEMENTATION-REPORT.md` is **not** a review; it is a session close-out report that landed in the wrong folder. Options: (a) move it into `ROADMAP-E2E.md` as an "Implementation log" section, (b) move to `docs/reports/` as a new top-level for report-type artefacts, (c) keep in `docs/reviews/` with `status: shipped` and a note. I recommend **(a) inline into `ROADMAP-E2E.md`** because the roadmap is active and this report is its implementation log; putting it in the roadmap file is the cleanest expression of the "single source of truth per unit of work" principle. Then delete the standalone file.

### 7. `HANDOFF.md` — evolution

Current HANDOFF is 105 lines, well-structured, rewritten fresh per session. Keep as-is except for two small changes:

- Add a frontmatter block with `type: handoff`, `status: active`, `updated: YYYY-MM-DD`. Marginal benefit but consistent with the scheme.
- Document in `docs/README.md` the rule: **if HANDOFF exceeds 200 lines, split the carry-over list into `docs/BACKLOG.md`**. HANDOFF remains about "what shipped last + what's next"; carry-over debt belongs in BACKLOG. This is an explicit guardrail, not a new workflow.

### 8. Guidance file updates

| File | Change |
|---|---|
| `docs/plans/CLAUDE.md` and `AGENTS.md` | Add note: plan files now include Implementation log + Post-implementation review + Close note sections. Drop the "read only specific files" rule if it still conflicts (it does not — keep it). |
| `docs/archive/CLAUDE.md` and `AGENTS.md` | **Delete**. `docs/archive/` no longer exists in its old form. |
| `docs/reviews/CLAUDE.md` and `AGENTS.md` | Rewrite for narrow cross-cutting-audit scope. Drop per-round guidance. |
| `docs/README.md` | Full rewrite — new reading order, new indices, new status-flip workflow, no more "move to archive" language. |
| `.claude/CLAUDE.md` (project instructions) | Update session workflow step 8 (currently "move the plan file from `docs/plans/` to `docs/archive/plans/`") to new language: "flip `status:` to shipped and update `docs/PLANS.md`". |
| `.agents/AGENTS.md` | Mirror of `.claude/CLAUDE.md` — same update. |
| `HUMANS.md` | Update stale refs (`docs/ROADMAP.md`, `docs/reviews/ROADMAP-REVIEW.md`) to match new reality. Flagged only because the file currently misleads. |
| `.claude/MAINTENANCE.md` | Single reference to `docs/archive/` — update or note as obsolete. |

### 9. Orphans to handle

| File | Status today | Disposition |
|---|---|---|
| `docs/plans/PLAN-DOCS-CLEANUP.md` | Stale. Developer confirmed obsolete. | **Delete** (step 0 of implementation). |
| `docs/PROMPT-R8-QA.md` | Stale initial prompt for an R-8 manual QA session that shipped. | **Move** to `docs/plans/PROMPT-R-8-QA.md` with `status: shipped`, or **delete** outright. My recommendation: delete. It is not a plan, not a review, just a prompt kept for no clear reason. Ask the developer on approval. |
| `docs/reviews/E2E-IMPLEMENTATION-REPORT.md` | Report filed in reviews folder. | **Fold into `ROADMAP-E2E.md`** as an Implementation log section, then delete the standalone file. (§6) |
| `docs/design/ui.pen` | Binary design asset. | **Leave alone.** |
| `HUMANS.md` (root) | Developer's personal notes. References `docs/ROADMAP.md` (doesn't exist) and `docs/reviews/ROADMAP-REVIEW.md` (doesn't exist). | **Patch stale references**. Do not restructure — this file is for the human, not agents. |

### 10. HANDOFF ↔ index consistency — convention, not automation

The developer prefers convention over automation for small-scope checks. I agree: a Pest arch test that reads markdown frontmatter is over-engineering for a problem that only exists if an agent skips steps.

**Proposed convention**, to be written into `.claude/CLAUDE.md` session-done checklist:

Before closing any session, the agent confirms:

- [ ] The touched plan / roadmap file has its `status:` and `updated:` frontmatter updated.
- [ ] `docs/PLANS.md` or `docs/ROADMAP.md` reflects the same status.
- [ ] `docs/HANDOFF.md` is rewritten fresh.
- [ ] Commit message includes affected file paths.

**Stretch goal** — if drift shows up in practice: a ~30-line `scripts/docs-check.sh` that:

- Greps all `docs/**/*.md` for frontmatter.
- Asserts every file with frontmatter appears in the right index.
- Asserts every index row points to an existing file.

I do **not** propose writing this script in this session. It is a follow-up if convention fails, and I suspect it will not.

---

## Observations on the brief

Three places where the original prompt points at something the audit contradicts — flagged here rather than silently adjusted, per the brief's "Una nota sul tono" clause:

1. **`HUMANS.md` is not tongue-in-cheek** — it exists at `/Users/mir/Projects/riservo/HUMANS.md` as a real developer-facing reference. It is partly stale (references `docs/ROADMAP.md` which does not exist, `docs/reviews/ROADMAP-REVIEW.md` which does not exist). The brief suggests confirming via `find`; confirmed: it is real. Proposed disposition: patch stale refs as part of this session.
2. **`docs/reviews/` is not empty between rounds** — it currently holds `E2E-IMPLEMENTATION-REPORT.md`. The brief assumed empty. Proposed disposition: §6.
3. **`docs/PROMPT-R8-QA.md`** sits loose at the docs root, not in any subdirectory. Not mentioned in the brief. Proposed disposition: §9.

---

## Implementation steps

A single session, ordered. Each step is atomic enough to inspect in the diff.

### Step 0 — Clean up

- Delete `docs/plans/PLAN-DOCS-CLEANUP.md`.
- Delete `docs/PROMPT-R8-QA.md` (pending developer confirmation on approval — default = delete).

### Step 1 — Author the conventions

1. Write `docs/README.md` fresh: describes the new reading order, introduces `ROADMAP.md` and `PLANS.md` as stable entrypoints, describes the status taxonomy in one short section, and documents the status-flip workflow. Replaces the current "Read First / Read When Relevant / Archive" structure with "Read First / Read When Relevant / Indices" (no archive section needed).
2. Write `docs/ROADMAP.md` as the index over `docs/roadmaps/` (see §3 for shape).
3. Write `docs/PLANS.md` as the index over `docs/plans/` (see §3 for shape).

### Step 2 — Move archive contents back in place

One `git mv` per file; body unchanged.

- `docs/archive/plans/*.md` → `docs/plans/*.md` (31 files)
- `docs/archive/roadmaps/*.md` → `docs/roadmaps/*.md` (4 files)
- `docs/archive/reviews/*.md` → `docs/reviews/*.md` (4 files)

Delete:

- `docs/archive/CLAUDE.md`
- `docs/archive/AGENTS.md`
- `docs/archive/` directory (once empty).

### Step 3 — Prepend frontmatter to every moved file

For each moved file, prepend the YAML block. Values derived mechanically:

- `name`: filename sans `.md`
- `description`: one line pulled from the existing H1 / first paragraph; manual touch-up for clarity
- `type`: `plan` / `roadmap` / `review`
- `status`: `shipped` (default for archived files), `superseded` for ROADMAP-FEATURES and ROADMAP-CALENDAR
- `created`: best-effort date from `git log --diff-filter=A --format=%ad --date=short -- <file> | tail -1` (creation date). Where absent, leave as the date of the initial MVP commit.
- `updated`: best-effort date from `git log -1 --format=%ad --date=short -- <file>`.
- `commits`: left empty (`[]`) for historical plans — mining commit history per plan is not worth the effort; the story lives in git.
- `supersededBy`: set on ROADMAP-FEATURES and ROADMAP-CALENDAR pointing at `roadmaps/ROADMAP-MVP-COMPLETION.md`.

### Step 4 — Normalise currently-active files

Apply frontmatter to:

- `docs/roadmaps/ROADMAP-PAYMENTS.md` — `status: active` (locked v1.1, sessions ready).
- `docs/roadmaps/ROADMAP-E2E.md` — `status: active` (E2E-1..6 shipped, roadmap stays open for new features). Add an "Implementation log" section containing the contents of the old `E2E-IMPLEMENTATION-REPORT.md`.
- `docs/roadmaps/ROADMAP-GROUP-BOOKINGS.md` — `status: planning`.
- `docs/HANDOFF.md` — `status: active`.

Delete `docs/reviews/E2E-IMPLEMENTATION-REPORT.md` after its content is in ROADMAP-E2E.md.

### Step 5 — Update guidance

- Rewrite `docs/plans/CLAUDE.md` and `docs/plans/AGENTS.md` (keep them mirrored): note the Implementation log + Post-impl review + Close note sections; keep the "do not bulk-read" rule.
- Rewrite `docs/reviews/CLAUDE.md` and `docs/reviews/AGENTS.md`: narrow scope to cross-cutting audits.
- Update `.claude/CLAUDE.md` session workflow step 8 and the Session Done Checklist.
- Update `.agents/AGENTS.md` to mirror.
- Update `HUMANS.md` stale references to the new paths (or delete the stale bullets if they no longer apply).
- Update `.claude/MAINTENANCE.md` to drop the `docs/archive/` pair and add the new mirror pair rules for `docs/reviews/` and `docs/plans/` if needed.

### Step 6 — Annotate the E2E status ledger

- `tests/Browser/route-coverage.md` stays where it is; it is already a practical per-route status tracker. Add one-line pointer at the top of `ROADMAP-E2E.md`: *"Live per-route coverage tracked in `tests/Browser/route-coverage.md`."* No new file.

### Step 7 — Verify

- Grep `docs/archive/` across the full repo — should return 0 matches outside of `HUMANS.md`'s possible one-time legacy note and git history.
- Grep `docs/ROADMAP.md` — should now actually resolve to a real file.
- Manually scan `docs/ROADMAP.md` and `docs/PLANS.md` — every row points to an existing file; every file with frontmatter is represented.
- `php artisan test tests/Feature tests/Unit --compact` — expect no delta (this is docs-only).
- `npm run build` — not required but run once for peace of mind.
- `vendor/bin/pint --dirty --format agent` — no PHP touched; should be a no-op.

### Step 8 — Close this plan

This plan is the one exception to the new rule: it gets **physically archived** on close (moved to `docs/archive/plans/PLAN-DOCS-RESTRUCTURE.md`) per the brief's request — it is the reason we are doing the restructure, and a historical marker of the pre-restructure era. Every plan that lands *after* this one stays in place and flips `status:` only.

Actually — on reflection, I would push back on this item one more time. The brief says *"sì, questo plan specifico viene archiviato fisicamente — è il motivo per cui partiamo"*. But under the new convention, there is **no archive directory for plans**. Archiving this one file physically would mean keeping `docs/archive/plans/` alive as a single-file directory — which contradicts the cleanliness the restructure is trying to achieve.

**Counter-proposal**: leave this plan file in `docs/plans/PLAN-DOCS-RESTRUCTURE.md` with `status: shipped`. The Implementation log + Close note sections record that this session killed the `docs/archive/plans/` subtree. The file becomes its own monument. Developer: if you want it moved anyway, say so in the approval and I will do it.

---

## Files to create / modify

**Create**
- `docs/ROADMAP.md`
- `docs/PLANS.md`

**Delete**
- `docs/plans/PLAN-DOCS-CLEANUP.md`
- `docs/PROMPT-R8-QA.md` (pending confirmation)
- `docs/reviews/E2E-IMPLEMENTATION-REPORT.md` (after content migration)
- `docs/archive/CLAUDE.md`
- `docs/archive/AGENTS.md`
- `docs/archive/` directory once empty.

**Move (31 + 4 + 4 = 39 files via `git mv`)**
- `docs/archive/plans/*.md` → `docs/plans/`
- `docs/archive/roadmaps/*.md` → `docs/roadmaps/`
- `docs/archive/reviews/*.md` → `docs/reviews/`

**Modify — frontmatter prepend + sometimes one-line content edits**
- All 39 moved files (frontmatter only)
- `docs/roadmaps/ROADMAP-PAYMENTS.md`
- `docs/roadmaps/ROADMAP-E2E.md` (frontmatter + Implementation log section)
- `docs/roadmaps/ROADMAP-GROUP-BOOKINGS.md`
- `docs/HANDOFF.md` (frontmatter + 200-line carry-over rule in body optional, lives in README anyway)
- `docs/README.md` (rewrite)
- `docs/plans/CLAUDE.md` and `docs/plans/AGENTS.md`
- `docs/reviews/CLAUDE.md` and `docs/reviews/AGENTS.md`
- `.claude/CLAUDE.md`
- `.agents/AGENTS.md`
- `.claude/MAINTENANCE.md`
- `HUMANS.md`

Total: ~50 files touched. Large diff, one logical commit (or two: "move + frontmatter" then "guidance + indices").

---

## Verification

```bash
php artisan test tests/Feature tests/Unit --compact     # expect no delta; docs-only
vendor/bin/pint --dirty --format agent                  # expect no-op
npm run build                                           # not required; run once for sanity
```

Manual checks on close:

- `ls docs/` shows `ROADMAP.md` and `PLANS.md`, no `archive/` directory.
- `docs/ROADMAP.md` rows match every file under `docs/roadmaps/`.
- `docs/PLANS.md` rows match every file under `docs/plans/`.
- Open `docs/README.md`, confirm the Read First list is correct and points at files that actually exist.
- Grep `docs/archive/` repo-wide — zero hits outside of git log.

---

## Open questions

Before I begin implementation, please confirm:

1. **Dissolve `docs/archive/{plans,roadmaps,reviews}/`?** (Recommend yes — §4.) If no, fall back to keeping the archive intact and normalising only the active roadmaps. The index files still get built; they just link into the archive subtree for shipped entries.
2. **Delete `docs/PROMPT-R8-QA.md`?** (Recommend yes — stale initial prompt.) Alternative: move to `docs/plans/` as `PLAN-R-8-QA-PROMPT.md` with `status: shipped`.
3. **Inline the E2E implementation report into `ROADMAP-E2E.md`, or leave as a separate file?** (Recommend inline — §6.)
4. **Archive or keep this plan file on close?** The brief says archive it physically; my counter-proposal (§ Step 8) is to leave it in `docs/plans/` with `status: shipped` so the new convention is consistent from day one. Developer's call.
5. **Two commits or one?** I lean toward two: (a) the big move + frontmatter normalise, (b) indices + guidance + README + HANDOFF. Cleaner to review. Confirm or override.
6. **`review` status — keep or collapse into `active`?** I kept it because it is useful signal; happy to collapse if you think six states is one too many.

Everything else in this plan is locked pending approval.

---

## Risks and notes

- **Large diff risk**. ~50 files change. This is as big as a feature session. Mitigation: two small commits, no narrative rewrites on archived content, `git mv` preserves history.
- **Cross-reference breakage inside archived plans**. Plans currently reference `docs/archive/plans/PLAN-R-1A-...` etc. After the move those paths change. Since archived files are historical record, I will **not** rewrite the internal links — the file basename search still works and over-rewriting history is the wrong move. Agents doing archaeology can resolve by basename.
- **Frontmatter dates are approximate for archived files**. Using `git log --diff-filter=A` per file yields the add-commit date, which is close enough for the intended purpose (human context, not machine scheduling). No SLA on accuracy here.
- **The E2E roadmap's "active with open scope" status is new**. Agents should not be confused by `shipped` sessions inside an `active` roadmap — `route-coverage.md` remains the live per-item status. I will make this explicit in `ROADMAP-E2E.md` and in the index.
- **`.claude/CLAUDE.md` is loaded into every agent session**. Changing its session workflow means every future session reads the new rules. Good (that is the point). But a verification check at close — re-read `.claude/CLAUDE.md` end-to-end — is worth the ten seconds.
- **Future incremental migration to GitHub-native is not blocked** by this restructure. Frontmatter values map cleanly to Issue/Milestone metadata later; moving from file-based to GitHub-native becomes a write-out script, not a re-architecture.

---

## Implementation log

Shipped in a single session on 2026-04-17 (developer reviewing diff before commit).

**Deviations from the plan:**

- Step 8 counter-proposal accepted — this plan file stays in `docs/plans/` with `status: shipped`. No plan file was physically archived. `docs/archive/` was dissolved fully, including plans, roadmaps, and reviews subtrees.
- `docs/PROMPT-R8-QA.md` was **left in place**, not deleted. Developer will handle separately with a dedicated agent. Its one stale reference to `docs/archive/plans/PLAN-R-8-CALENDAR-MOBILE.md` is now technically broken (the file is at `docs/plans/PLAN-R-8-CALENDAR-MOBILE.md`) but out of scope for this session.
- The separate BACKLOG / HANDOFF stale-reference touch-ups were done inline during Step 7 verification rather than as a dedicated follow-up step.

**Files touched:** ~50 total.

- 2 new: `docs/ROADMAP.md`, `docs/PLANS.md`.
- 3 deleted: `docs/plans/PLAN-DOCS-CLEANUP.md`, `docs/archive/CLAUDE.md`, `docs/archive/AGENTS.md`. Plus `docs/reviews/E2E-IMPLEMENTATION-REPORT.md` after inlining into `ROADMAP-E2E.md`.
- 39 moved via `git mv` from `docs/archive/{plans,roadmaps,reviews}/` to their canonical parents; each got a YAML frontmatter block prepended.
- 4 active roadmaps / handoff: frontmatter added (`ROADMAP-PAYMENTS.md`, `ROADMAP-E2E.md`, `ROADMAP-GROUP-BOOKINGS.md`, `HANDOFF.md`).
- Guidance rewrites: `docs/README.md`, `docs/plans/CLAUDE.md` + `AGENTS.md`, `docs/reviews/CLAUDE.md` + `AGENTS.md`, `.claude/CLAUDE.md`, `.agents/AGENTS.md`, `.claude/MAINTENANCE.md`, `HUMANS.md`.
- Stale reference fixes in `docs/ARCHITECTURE-SUMMARY.md`, `docs/BACKLOG.md`, `docs/HANDOFF.md`.

**Edge cases noticed during implementation:**

- First `prepend_fm.sh` invocation for this plan file was fed a mangled positional arg (`--tmp`) and wrote garbled frontmatter; fixed in a follow-up Edit. No other file was affected.
- Multiple `updated:` dates on archived files collapse to `2026-04-17` simply because the earlier `docs-cleanup` session touched them on that date (the `git mv` into archive counts as a modification). Dates are approximate, as flagged in the plan.
- `docs/archive/` references surfaced in active content files (`ARCHITECTURE-SUMMARY.md`, `BACKLOG.md`, `HANDOFF.md`) — fixed inline. References inside the *archived plans* (now un-archived) still point at `docs/archive/plans/...`; per the plan's explicit policy, those internal cross-refs are not rewritten — historical record stays intact, basename search resolves them.

**Not done** (explicitly out of scope, deferred):

- Stale reference in `docs/PROMPT-R8-QA.md` (developer will handle).
- `scripts/docs-check.sh` — deferred as stretch goal; convention-first per §10.
- Retro-rewriting `docs/archive/` paths inside the narrative of archived plans — frozen historical record.

## Close note

- **Status on close:** shipped.
- **Final commit:** *pending developer review + commit.*
- **Test suite delta:** none expected (docs-only session). Verification run at close:
  - `php artisan test tests/Feature tests/Unit --compact` — baseline 693 / 2814, no regression expected.
  - `vendor/bin/pint --dirty --format agent` — no PHP touched; no-op.
  - No `npm run build` needed — docs-only.
- **Decisions recorded:** none. This was pure docs restructure; no architectural decision was added.
- **Carry-over to BACKLOG:** none introduced by this session.
- **Next session:** the developer will review the diff, commit, and then ask the orchestrator agent to open ROADMAP-PAYMENTS Session 1.
