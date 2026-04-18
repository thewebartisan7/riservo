# Documentation Guide

Start here before reading any other project documentation. This file is the source of truth for what future agents should read first, what to open only when relevant, and how the docs system is structured.

## Read First

1. `docs/HANDOFF.md` — latest project state, current conventions, and immediate follow-up guidance.
2. `docs/ROADMAP.md` — index of delivery roadmaps. Open the specific roadmap row whose scope matches the current task.
3. `docs/SPEC.md` — product scope, rules, and domain model.
4. `docs/DECISIONS.md` — decision index; open only the topical decision files relevant to the task.
5. `docs/ARCHITECTURE-SUMMARY.md` — concise implementation-oriented summary of the current system.

## Read When Relevant

- `docs/UI-CUSTOMIZATIONS.md` — required for COSS UI, theming, or frontend polish work.
- `docs/DEPLOYMENT.md` — required for deployment, queue, mail, cron, or environment work.
- `docs/BACKLOG.md` — unscheduled UI follow-up, UX ideas, and technical debt notes.
- `docs/PLANS.md` — index of session plans. Open the specific plan row whose scope matches the current task. Do **not** bulk-read `docs/plans/`.
- `docs/reviews/` — cross-cutting audits (security, accessibility, performance). Open a specific file by name. This directory is **not** indexed — files here are directory-scoped.
- `tests/Browser/route-coverage.md` — live per-route E2E coverage ledger paired with `ROADMAP-E2E.md`.

## The four-layer work flow

Work happens in four layers, in this order. Each is owned by a different role, and the boundaries are hard — an agent in one layer does not cross into another.

1. **INTENT** — the developer expresses a direction.
2. **ROADMAP** — a senior architect agent turns intent into a roadmap (WHAT, split into sessions, cross-cutting decisions locked). Template: `.claude/prompts/ROADMAP-ARCHITECT.md`.
3. **ORCHESTRATION** — an orchestrator agent coordinates the sessions of one roadmap from start to finish: drafts per-session prompts, reviews plans, relays codex findings, maintains state. Template: `.claude/prompts/ROADMAP-ORCHESTRATOR.md`.
4. **IMPLEMENTATION** — a per-session implementer agent writes an execution plan, gets approval, implements, keeps the plan alive as a living document, and closes by flipping status + updating indices. Template: `.claude/prompts/SESSION-IMPLEMENTER.md`.

`HUMANS.md` at the repo root is the developer's personal map of this same pipeline; it maps the four layers onto a simpler WHAT / HOW / CODE mental shorthand.

## How this docs system works

Every roadmap, plan, review, and handoff file carries a YAML frontmatter block. The frontmatter drives one thing for every type (the file's own lifecycle state via `status:`) and, for two of the four types, a second thing (the row in a matching index):

- **`plan`** — drives a row in `docs/PLANS.md`.
- **`roadmap`** — drives a row in `docs/ROADMAP.md`.
- **`review`** — directory-scoped only, not indexed. Cross-cutting audits are rare enough that a directory listing is enough.
- **`handoff`** — there is exactly one (`docs/HANDOFF.md`), so no index row is needed; the frontmatter convention is applied purely for consistency with the other types.

Decision topics have their own index (`docs/DECISIONS.md`) pointing at per-topic files under `docs/decisions/`; those follow a different shape and are not covered by the frontmatter convention here.

Files **do not move** when they ship. The path stays stable; only `status:` flips. Discoverability comes from the indices, not from directory layout.

### Frontmatter shape

```yaml
---
name: PLAN-EXAMPLE
description: One-line summary that drives the index table
type: plan            # plan | roadmap | review | handoff
status: active        # draft | planning | active | review | shipped | superseded | abandoned
created: YYYY-MM-DD
updated: YYYY-MM-DD
commits: [hash1, hash2]    # optional; plans only, filled as implementation commits land
supersededBy: roadmaps/ROADMAP-X.md    # optional; only when status: superseded
---
```

### Status taxonomy

| Status | Applies to | Meaning |
|---|---|---|
| `draft` | plan, roadmap | Still being written / actively reshaped; not ready for developer approval. |
| `planning` | plan, roadmap, review | Drafted, handed to the developer for approval / review; nothing is being implemented against it yet. |
| `active` | plan, roadmap, review, handoff | Plan/roadmap/review is being worked on. For `handoff`, means "current live handoff". |
| `review` | plan | Implementation committed; codex review in progress. |
| `shipped` | plan, roadmap, review, handoff | Work complete. File stays in place as historical record. |
| `superseded` | plan, roadmap | Replaced by another file; `supersededBy:` points at the successor. |
| `abandoned` | plan | Started, then intentionally stopped without a successor (rare; Close note explains why). |

Notes on specific types:

- **`handoff`**: `HANDOFF.md` is always `active` — there is exactly one. The type exists so the file participates in the frontmatter convention, not because it has a non-trivial lifecycle.
- **`review`**: cross-cutting audits follow `planning | active | shipped` like a mini-roadmap. Per-session codex reviews do not live in this directory — they live inside the plan file under `## Post-implementation review`.
- **`abandoned`** vs **`superseded`**: use `superseded` when a named successor takes over the scope; use `abandoned` when the scope is dropped with no replacement.

### Session lifecycle in a plan file

A plan file holds the **whole session** — pre-implementation plan, living progress trace, and post-implementation record — all in one place, under stable sections:

```
# PLAN — {ID} — {Title}

## Purpose                         (what the user gains; observable behavior)
## Context                         (current state, files to know, terms defined)
## Scope (In / Out)
## Key design decisions
## Implementation steps
## Files to create / modify
## Tests
## Validation & acceptance         (exact commands + expected outputs)
## Decisions to record             (new D-NNNs destined for topical files)
## Open questions
## Risks & notes

## Progress                        (checkbox list, timestamped, kept current)
## Implementation log              (chronological, clustered by commit)
## Surprises & discoveries         (unexpected findings with evidence)
## Post-implementation review      (codex rounds + follow-up commits)
## Close note / retrospective      (status on close, final commit, test delta, lessons)
```

The lower five sections are **living-document** sections — they grow as the session runs, not retroactively at close.

**Which living sections are mandatory depends on session type:**

- **Code-touching sessions** (features, bug fixes, remediations, UI work): all five living sections are mandatory by close. Any section with no content still exists with a single-line explanation ("no surprises this session", "no codex review — internal only", etc.) — never silently omitted.
- **Docs-only and workflow-only sessions**: `## Implementation log` + `## Close note / retrospective` are still mandatory. `## Progress`, `## Surprises & discoveries`, and `## Post-implementation review` may be omitted entirely if the session is linear and does not accumulate real progress/surprise/review traffic.
- **Hotfix / rollback sessions**: use the implementer template with a `PLAN-HOTFIX-{TOPIC}.md` (or `PLAN-ROLLBACK-{TOPIC}.md`) filename. Living sections may be abbreviated; a single-paragraph `Implementation log` + `Close note` is acceptable for a hotfix under an hour of work.

For the full prompt that drives an implementing-agent session against this shape, see `.claude/prompts/SESSION-IMPLEMENTER.md`.

## Working rules

- Record new architectural decisions in the appropriate topical file linked from `docs/DECISIONS.md`. Never add a decision body directly to `DECISIONS.md`.
- If a session introduces a brand-new decision *domain* (a new `DECISIONS-{TOPIC}.md` file), the same session must add its row to the `docs/DECISIONS.md` index.
- New active implementation plans go in `docs/plans/` with `status: draft` or `planning`. They stay there for their entire life.
- When a plan's status changes (approval, close, review start/end), update **both** the frontmatter and the `docs/PLANS.md` row. Same rule for roadmaps against `docs/ROADMAP.md`.
- `docs/HANDOFF.md` is required for any session that changes shipped product or runtime state. Docs-only and workflow-only sessions may skip HANDOFF — unless they change the workflow itself or the canonical reading order, in which case they must update HANDOFF so future agents pick up the new rules.
- When HANDOFF is updated, it is **rewritten fresh** (overwrite, not append). If HANDOFF grows beyond 200 lines, move carry-over items into `docs/BACKLOG.md` — HANDOFF stays focused on "what shipped last" and "what is next".
- `docs/reviews/` is reserved for cross-cutting audits (security, accessibility, performance). Per-session reviews live inside the plan file under `## Post-implementation review`.

### Mechanical consistency check

Frontmatter and indices must stay in sync. The convention is manually maintained: every time a plan or roadmap status flips, the matching `docs/PLANS.md` / `docs/ROADMAP.md` row moves bucket and its status column updates.

Two tools verify this contract:

- **`php artisan docs:check`** — the truth engine. Deterministic, exits non-zero on any error. Add `--json` for machine-readable output; add `--base=PATH` to run against a different project root (used by tests). Lives in `app/Console/Commands/DocsCheckCommand.php`; tests in `tests/Feature/Commands/DocsCheckCommandTest.php`.
- **`/riservo-status` skill** — the ergonomic interface for Claude Code sessions. Invokes `docs:check --json` under the hood, groups findings by severity, suggests fixes. Lives in `.claude/skills/riservo-status/`.

What they verify: every file with frontmatter is indexed correctly, every indexed row points at an existing file, the row's bucket matches the file's `status:`, status values are in the taxonomy, required fields are present, `type:` matches the directory. They do **not** lint body prose, resolve cross-references, or verify commit hashes — those are human judgement calls.

Run `php artisan docs:check` before commit on any docs-touching session. Use the skill when you want grouped human-readable findings with suggested fixes.

## What not to search by default

- `docs/plans/` — do not bulk-read. Use `docs/PLANS.md` to find a specific plan, then open only that file.
- `docs/reviews/` — same: open specific audit files named by the task, not the whole directory.
- `docs/roadmaps/` — same: open via `docs/ROADMAP.md`.
- `docs/decisions/` — same: open via `docs/DECISIONS.md`.
