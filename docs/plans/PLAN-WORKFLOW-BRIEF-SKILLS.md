---
name: PLAN-WORKFLOW-BRIEF-SKILLS
description: "Two prompt-prep skills (riservo-brief-architect / riservo-brief-orchestrator) plus move prompt templates into skill assets/"
type: plan
status: shipped
created: 2026-04-18
updated: 2026-04-18
commits: []
supersededBy: null
---

# PLAN — WORKFLOW-BRIEF-SKILLS — Two prompt-prep skills + move templates to skill assets

## Purpose

After this change, the developer starts a new roadmap by running `/riservo-brief-architect` in any chat; the skill asks 2–4 shaping questions and emits a fully-filled architect prompt ready to paste into a fresh session. At the end of an architect session, the developer runs `/riservo-brief-orchestrator`; the skill reads the just-shipped roadmap and HANDOFF, pre-flight-checks the codex plugin, and emits a fully-filled orchestrator prompt — no questions asked, because the roadmap IS the context. Prompt templates move from `.claude/prompts/` into each skill's `assets/` subdirectory per the Claude Code convention for template bodies.

Observable after: `/riservo-brief-architect` and `/riservo-brief-orchestrator` appear in the available-skills list; running either produces a copy-paste block with every `[BRACKETED]` placeholder filled. The old `.claude/prompts/` directory is gone; every reference to it in the repo resolves to the new paths.

## Context

The developer already runs this pattern by hand (prior conversation, 2026-04-18): open a short chat, ask Claude to prepare the architect prompt from the intent, paste into a fresh chat, work with the architect. At the end of the architect session, ask Claude to prepare the orchestrator prompt, paste into the orchestrator chat. Formalising it as two skills codifies the pattern, enforces a consistent brief shape, and lets `/riservo-brief-orchestrator` auto-fill placeholders from disk at session boundaries.

Existing skill structures for reference: `.claude/skills/riservo-status/SKILL.md` (leaf skill, no assets), `.claude/skills/cashier-stripe-development/` (uses `references/` subdir), `.claude/skills/laravel-best-practices/` (uses `rules/` subdir). Neither uses `assets/`; the new skills introduce the convention, matching Anthropic's broader skill-system pattern where `assets/` holds material the skill emits rather than consults.

Paths touched:

- `.claude/skills/riservo-brief-architect/SKILL.md` — create.
- `.claude/skills/riservo-brief-architect/assets/ROADMAP-ARCHITECT.md` — move from `.claude/prompts/ROADMAP-ARCHITECT.md`.
- `.claude/skills/riservo-brief-orchestrator/SKILL.md` — create.
- `.claude/skills/riservo-brief-orchestrator/assets/ROADMAP-ORCHESTRATOR.md` — move from `.claude/prompts/ROADMAP-ORCHESTRATOR.md`.
- `.claude/skills/riservo-brief-orchestrator/assets/SESSION-IMPLEMENTER.md` — move from `.claude/prompts/SESSION-IMPLEMENTER.md`. The orchestrator is this template's primary consumer (it fills the body into its `Agent` tool call); one-off direct briefs still work by reading the file at its new path.
- `.claude/prompts/` — removed entirely once empty.
- `HUMANS.md`, `docs/HANDOFF.md`, `docs/README.md` — reference updates.
- Cross-refs inside the three moved templates — updated during the move.

Terms: "prompt-prep skill" — a skill whose job is to emit a ready-to-paste prompt block, not to perform the role the prompt addresses. These two skills prep for the architect and orchestrator; they are not themselves the architect or orchestrator.

## Scope

### In

- Create two skills with the names `riservo-brief-architect` and `riservo-brief-orchestrator`, both following the `SKILL.md` + `assets/` layout.
- Move all three prompt templates into the relevant skill's `assets/` folder.
- Update every reference to `.claude/prompts/*` in active (non-shipped-plan) files to the new paths.
- Rewrite `docs/HANDOFF.md` to reflect the new workflow (skills exist; templates live under skill assets; `/riservo-brief-architect` and `/riservo-brief-orchestrator` are now the canonical entry points).
- Run `php artisan docs:check` clean at close; move `docs/PLANS.md` row from `## In flight` to `## Shipped`; flip plan `status: active → shipped`.

### Out

- No retroactive edits to `docs/plans/PLAN-WORKFLOW-AGENTIC-ORCHESTRATOR.md` (shipped plan; historical record of paths at the moment it shipped).
- No new architectural decisions (`D-NNN`).
- No application code, no tests, no frontend, no migrations.
- No changes to `docs/README.md` beyond the three template-path references on lines 27–29 and 113.
- No deletion of the plan-prep pattern; the skills augment, do not replace, the ability to read the templates directly.
- No auto-invocation. Skills fire only when the developer types their slash names.

## Key design decisions

1. **`assets/` subdirectory, not `references/`.** Templates are emitted (filled and handed back to the developer), not consulted. `assets/` matches the Anthropic convention for material a skill renders, and keeps the layout clean alongside `references/` and `rules/` elsewhere in the repo.
2. **`SESSION-IMPLEMENTER.md` lives under `riservo-brief-orchestrator/assets/`.** The orchestrator is this template's primary consumer — Phase 1 of its own template fills the implementer body into an `Agent` tool call. A skill named `riservo-brief-implementer` would be third-wheel; one-off direct briefs read from the new path without needing a skill wrapper.
3. **`/riservo-brief-architect` is interactive, `/riservo-brief-orchestrator` is silent by default.** The architect brief cannot be auto-filled from disk (nothing on disk yet describes the intent); the skill asks 2–4 shaping questions. The orchestrator brief can be fully auto-filled (the roadmap on disk names scope, sessions, decisions; HANDOFF names baseline; topical files name next D-NNN); the skill asks only if multiple active roadmaps exist or if the codex plugin is missing.
4. **Skill descriptions follow the proven riservo-status shape.** Long description, explicit trigger phrases, explicit "use when" examples, explicit "do not use for" examples. Skill descriptions drive discovery; terse descriptions get skipped.
5. **No skill-to-skill call.** Neither skill invokes the other. The developer is the glue at session boundaries (paste into fresh chat). Keeping each skill self-contained avoids coupling the two lifecycles.
6. **Mode-B detection in `/riservo-brief-architect`.** If a draft-status roadmap exists in `docs/roadmaps/` matching the developer's topic keyword, the skill offers Mode B (stress-test) instead of Mode A. Saves re-drafting an existing draft.
7. **Codex plugin pre-flight in `/riservo-brief-orchestrator`.** If `/plugins` does not list `codex@openai-codex`, the emitted prompt opens with the install block so the next session doesn't stall. Detect via the `installed_plugins.json` file or the `/plugins` command surface; if detection is unreliable, print the install block prophylactically with a "skip if already installed" note.
8. **No change to the templates' body content.** This session is structural (layout + wrappers). The content of each template stays as it is — same sections, same prose, same frontmatter. Only cross-references between them update.

## Implementation steps

### Step 1 — Create directory structure + move templates

```
mkdir -p .claude/skills/riservo-brief-architect/assets
mkdir -p .claude/skills/riservo-brief-orchestrator/assets
git mv .claude/prompts/ROADMAP-ARCHITECT.md .claude/skills/riservo-brief-architect/assets/ROADMAP-ARCHITECT.md
git mv .claude/prompts/ROADMAP-ORCHESTRATOR.md .claude/skills/riservo-brief-orchestrator/assets/ROADMAP-ORCHESTRATOR.md
git mv .claude/prompts/SESSION-IMPLEMENTER.md .claude/skills/riservo-brief-orchestrator/assets/SESSION-IMPLEMENTER.md
rmdir .claude/prompts
```

### Step 2 — Update cross-references inside the moved templates

- `ROADMAP-ARCHITECT.md`: two references to `.claude/prompts/ROADMAP-ORCHESTRATOR.md` (lines 5 and 213 in the pre-move version) → `.claude/skills/riservo-brief-orchestrator/assets/ROADMAP-ORCHESTRATOR.md`.
- `ROADMAP-ORCHESTRATOR.md`: references to `.claude/prompts/ROADMAP-ARCHITECT.md` (line 5) → new architect-assets path. References to `.claude/prompts/SESSION-IMPLEMENTER.md` (lines 28, 63, 78) → new implementer-assets path (same skill's assets, adjacent file, so a relative reference `assets/SESSION-IMPLEMENTER.md` reads cleanly when the orchestrator template is already in that assets folder; but the template may be read standalone, so stick with repo-relative absolute paths).
- `SESSION-IMPLEMENTER.md`: reference to `.claude/prompts/ROADMAP-ORCHESTRATOR.md` (line 4) → new orchestrator-assets path.

### Step 3 — Update external references

- `HUMANS.md` line 116 — update Appendix A path.
- `docs/HANDOFF.md` line 32 — rewrite the "Prompt templates live in…" sentence. Since HANDOFF is being refreshed anyway (this session changes the workflow), the full rewrite covers this.
- `docs/README.md` lines 27–29 and 113 — update the four template-path references.

### Step 4 — Write `SKILL.md` for `riservo-brief-architect`

Long description, explicit triggers, interactive behavior (asks 2–4 shaping questions before emitting), Mode-A / Mode-B branch, "Skip for" carve-outs (tiny one-off roadmaps that need neither architect nor orchestrator go straight to the implementer template).

Behavior:

1. Read `assets/ROADMAP-ARCHITECT.md`.
2. Scan `docs/roadmaps/` for draft-status roadmaps; if one matches the developer's topic, offer Mode B.
3. Ask: what is the one-line intent? which surface area (models, controllers, frontend pages)? what are the top 2–3 probing areas a critic should hammer on? any settled decisions from prior conversation to pre-record?
4. Fill placeholders in the architect template body. Emit the filled prompt in a fenced block with a one-line lead-in: "Copy the block below into a fresh chat."

### Step 5 — Write `SKILL.md` for `riservo-brief-orchestrator`

Long description, explicit triggers, silent-by-default behavior (no questions unless ambiguity), pre-flight checks (codex plugin, multiple active roadmaps).

Behavior:

1. Read `assets/ROADMAP-ORCHESTRATOR.md` and `assets/SESSION-IMPLEMENTER.md`.
2. Detect the active roadmap: grep `docs/ROADMAP.md` for `## In flight` rows whose frontmatter resolves to `status: active`. If zero, tell the developer no active roadmap exists (probably ran the skill too early). If one, use it. If more than one, ask which.
3. Read the chosen roadmap: extract name, one-line scope, session count, `DECISIONS-*.md` topical files referenced, starting `D-NNN` if stated.
4. Read `docs/HANDOFF.md` for baseline commit hash + Feature+Unit test count.
5. Pre-flight codex plugin: check `/Users/mir/.claude/plugins/installed_plugins.json` for `codex@openai-codex`; if absent, emit the install block at the top of the output prompt.
6. Fill placeholders in the orchestrator template body. Emit in a fenced block with lead-in: "Codex plugin detected / not detected. Copy the block below into a fresh chat."

### Step 6 — Rewrite `docs/HANDOFF.md`

Overwrite per the HANDOFF convention. Reflect:

- Workflow now has two skill entry points (`/riservo-brief-architect`, `/riservo-brief-orchestrator`) at the front of Layer 2 and Layer 3 respectively; the direct template-read path remains valid for one-offs.
- Prompt templates moved from `.claude/prompts/` to skill `assets/`. Canonical paths documented in the new HANDOFF.
- Everything else in HANDOFF stays: MVP shipped, PAYMENTS next, D-109 next free ID, 693/2814 test baseline unchanged.

### Step 7 — Close

- Run `php artisan docs:check` → expect clean.
- Run `vendor/bin/pint --dirty --format agent` → expect no-op (no PHP changed).
- Flip plan `status: active → shipped`, bump `updated:`, commits: `[]` to be filled at developer commit.
- Move `docs/PLANS.md` row to `## Shipped / Workflow` next to the prior agentic-orchestrator plan.

## Files to create / modify

### Create

- `.claude/skills/riservo-brief-architect/SKILL.md`
- `.claude/skills/riservo-brief-orchestrator/SKILL.md`
- `docs/plans/PLAN-WORKFLOW-BRIEF-SKILLS.md` — this file.

### Move (git mv, preserving history)

- `.claude/prompts/ROADMAP-ARCHITECT.md` → `.claude/skills/riservo-brief-architect/assets/ROADMAP-ARCHITECT.md`
- `.claude/prompts/ROADMAP-ORCHESTRATOR.md` → `.claude/skills/riservo-brief-orchestrator/assets/ROADMAP-ORCHESTRATOR.md`
- `.claude/prompts/SESSION-IMPLEMENTER.md` → `.claude/skills/riservo-brief-orchestrator/assets/SESSION-IMPLEMENTER.md`

### Modify

- All three moved templates: internal cross-reference updates.
- `HUMANS.md` — one path update.
- `docs/HANDOFF.md` — full rewrite.
- `docs/README.md` — four path updates.
- `docs/PLANS.md` — add row under `## In flight` at start, move to `## Shipped / Workflow` at close.

### Delete

- `.claude/prompts/` — directory removed once empty.

## Tests

No programmatic tests; acceptance is mechanical.

| Check | Command | Expected |
|---|---|---|
| Frontmatter + index consistency | `php artisan docs:check` | clean |
| PHP style (no-op) | `vendor/bin/pint --dirty --format agent` | `{"result":"pass"}` |
| No stale path references | `Grep pattern=".claude/prompts/"` against all active files | matches only in the shipped plan (historical) |
| Skill discoverability | `/reload-skills` or re-enter chat; `/help` lists `/riservo-brief-architect` + `/riservo-brief-orchestrator` | both names appear |

Test baseline 693/2814 unchanged — no application code touched.

## Validation & acceptance

1. `ls .claude/skills/riservo-brief-architect/` returns `SKILL.md assets/`. `ls .claude/skills/riservo-brief-architect/assets/` returns `ROADMAP-ARCHITECT.md`.
2. `ls .claude/skills/riservo-brief-orchestrator/assets/` returns `ROADMAP-ORCHESTRATOR.md SESSION-IMPLEMENTER.md`.
3. `ls .claude/` does NOT list a `prompts/` directory.
4. `Grep ".claude/prompts/"` returns matches only in `docs/plans/PLAN-WORKFLOW-AGENTIC-ORCHESTRATOR.md` (shipped historical record) — zero matches in live templates or docs.
5. `php artisan docs:check` exits 0 with "clean".
6. `docs/HANDOFF.md` names the two new skills and their assets paths.

Manual smoke:

- Reload skills (`/reload-skills`) in a session. Type `/riservo-brief-architect` — skill fires, asks shaping questions.
- Type `/riservo-brief-orchestrator` against an active roadmap — skill emits a filled prompt without asking.

## Decisions to record

None. This is a structural / ergonomic change; no new architectural `D-NNN` needed. The convention of "prompt templates live under each skill's `assets/`" is local to the `.claude/` tree and not worth a topical decision file.

## Open questions

None remaining. The developer pre-approved the skill names, the `assets/` location, and the "now go" signal.

## Risks & notes

- **Risk: path churn confuses the shipped plan's Close note.** The shipped `PLAN-WORKFLOW-AGENTIC-ORCHESTRATOR.md` references `.claude/prompts/*` as the files it touched. Those paths no longer exist after this session. Mitigation: do NOT edit the shipped plan. Future readers understand paths move; the plan documents what was true at its own ship time. Git history resolves the move.
- **Risk: skill-discovery timing.** After creating the SKILL.md files, the skills are not immediately loaded into the current conversation — the developer may need `/reload-skills` or a fresh chat. The session closes with a reminder.
- **Risk: codex-plugin-detection brittleness.** `/riservo-brief-orchestrator` detects the plugin via `installed_plugins.json`. If Claude Code changes the plugin registry location, the skill's pre-flight returns a false "missing" signal. Mitigation: the skill prints a "skip if already installed" note alongside the install block, so a false-negative costs the developer one redundant line in the emitted prompt, not a failed session.

## Progress

- [x] (2026-04-18) Plan drafted + approved (developer "now go" signal).
- [x] (2026-04-18) Step 1 — mkdir + git mv, `.claude/prompts/` removed.
- [x] (2026-04-18) Step 2 — three templates' internal cross-refs rewritten to the new paths.
- [x] (2026-04-18) Step 3 — `HUMANS.md` + `docs/README.md` external refs updated; HANDOFF refresh deferred to Step 6.
- [x] (2026-04-18) Step 4 — `riservo-brief-architect/SKILL.md` written.
- [x] (2026-04-18) Step 5 — `riservo-brief-orchestrator/SKILL.md` written. Both skills now appear in the available-skills list.
- [x] (2026-04-18) Step 6 — `docs/HANDOFF.md` rewritten fresh: adds "Workflow entry points" section naming both skills, names the new asset paths, updates "For the next session agent" to use `/riservo-brief-orchestrator`.
- [x] (2026-04-18) Step 7 — `php artisan docs:check` clean; `vendor/bin/pint --dirty --format agent` no-op pass; grep for `.claude/prompts/` returns only the two plan files (shipped historical + this plan's own scope narrative). PLANS.md row move + status flip happen below.

## Implementation log

### Cluster 1 — directory structure + git mv

Created `.claude/skills/riservo-brief-architect/assets/` and `.claude/skills/riservo-brief-orchestrator/assets/`. Moved the three templates via `git mv` (preserves history). Removed the now-empty `.claude/prompts/` directory. Confirmed by `ls`:

- `.claude/skills/riservo-brief-architect/assets/ROADMAP-ARCHITECT.md`
- `.claude/skills/riservo-brief-orchestrator/assets/ROADMAP-ORCHESTRATOR.md`
- `.claude/skills/riservo-brief-orchestrator/assets/SESSION-IMPLEMENTER.md`
- `.claude/prompts/` — gone.

### Cluster 2 — cross-reference updates inside the moved templates

Four edits inside the three moved files:

- `ROADMAP-ARCHITECT.md`: line 5 (Prelude) and the "Handoff to the orchestrator" step 5 now point at `riservo-brief-orchestrator/assets/ROADMAP-ORCHESTRATOR.md`; the latter also mentions the `/riservo-brief-orchestrator` skill as the ergonomic entry point. The parallel-exception paragraph already pointed at the orchestrator template via relative phrasing; updated to the new path.
- `ROADMAP-ORCHESTRATOR.md`: line 5 (Prerequisites) → new architect-assets path. Line 28 (Read-first #9) → new implementer-assets path. Phase 1 `Agent(prompt: "<body of…>")` string → new implementer-assets path. Phase 2 envelope reference → new implementer-assets path.
- `SESSION-IMPLEMENTER.md`: line 4 (How to use) → new orchestrator-assets path.

### Cluster 3 — external reference updates

- `HUMANS.md` "Session flow" — Appendix A reference updated to new orchestrator-assets path. New two-paragraph addendum after the existing two paragraphs on the status lock, covering the `/riservo-brief-architect` and `/riservo-brief-orchestrator` skills and the direct-implementer-read carve-out.
- `docs/README.md` four-layer flow bullets (Layer 2 / 3 / 4) — template paths updated; Layer 2 and Layer 3 now name the entry skill alongside the template path. `docs/README.md` line 113 plan-shape reference also updated.
- `docs/HANDOFF.md` — deferred to Cluster 5.

### Cluster 4 — SKILL.md files written

- `riservo-brief-architect/SKILL.md` — long description, clear When/When NOT to invoke sections, 6-step How-to-run covering template read, Mode-A/B detection, 2–4 shaping questions, placeholder fill, block emit, closing reminder. "What the skill does NOT do" section rules out doing the architect work itself. Escalation section pushes back when the task is wrong-layer.
- `riservo-brief-orchestrator/SKILL.md` — long description, When/When NOT, 8-step How-to-run covering template read, active-roadmap detection (zero/one/multiple), roadmap-context extraction, baseline from HANDOFF, codex plugin pre-flight, placeholder fill, block emit with lead-in line, closing reminder. "What the skill does NOT do" + Escalation sections.

Both skills now appear in the available-skills list on load; no manual `/reload-skills` needed from the developer because Claude Code picked them up automatically for this session.

### Cluster 5 — HANDOFF rewrite

Overwrote `docs/HANDOFF.md` fresh per the convention. Net structure:

- Top matter bumped `updated:` to `2026-04-18`; State line names both uncommitted workflow sessions.
- New "Workflow entry points (2026-04-18)" section immediately after the state line: `/riservo-brief-architect`, `/riservo-brief-orchestrator`, plus the direct-SESSION-IMPLEMENTER read path for one-offs.
- New "Workflow mechanics (unchanged by this session; hardened in 2026-04-18's prior session)" section: the active orchestrator invariants, status lock, triage split, developer gates, parallel exception, context escape hatch, template-asset paths. Folded in from the prior session's Close note to avoid re-deriving.
- Existing "Docs system" section retained but prefaced "(unchanged from 2026-04-17's restructure)".
- "What is shipped", "What is active next", "Conventions that future work must not break", "Test / build commands" sections carried forward verbatim.
- "Open follow-ups" gained two new carry-overs: dogfood the active orchestrator + brief skills against PAYMENTS-1; track whether the 500k escape-hatch threshold is right.
- "For the next session agent" rewritten to direct at `/riservo-brief-orchestrator`, not direct template copy.

### Cluster 6 — verification

- `php artisan docs:check` → `docs:check — clean. Frontmatter, indices, and bucket policy all agree.`
- `vendor/bin/pint --dirty --format agent` → `{"result":"pass"}` (no PHP touched — expected no-op).
- `Grep pattern=".claude/prompts/"` → two files only: `docs/plans/PLAN-WORKFLOW-AGENTIC-ORCHESTRATOR.md` (shipped, historical — correct to leave) and this plan (describes the before/after — correct to leave). No live files reference the old path.
- Skill discoverability: both `/riservo-brief-architect` and `/riservo-brief-orchestrator` appear in the available-skills list visible to Claude in this session.

## Close note / retrospective

- **Status on close:** `shipped`.
- **Final commit:** to be filled in when the developer commits this session. All changes (5 new files + 6 modified files + 1 directory removal, counted by rough git-status shape) land together.
- **Test suite delta:** none. No application code changed. Baseline 693 / 2814 unchanged.
- **Bundle / build impact:** none. No PHP, no frontend changes.
- **Carry-overs:** none new beyond what HANDOFF's "Open follow-ups" already lists. The two carry-overs seeded by the prior workflow session (dogfood against PAYMENTS, track escape-hatch threshold) remain.
- **HANDOFF:** rewritten fresh per the convention — this session changes the canonical workflow entry path (skills, not direct template copy), which counts as a workflow change.
- **Post-implementation review:** skipped. This is a workflow-only session; the docs/README.md carve-out permits omitting `## Post-implementation review` for linear workflow sessions. No codex pass. (Same reasoning as the prior workflow session: dogfooding the loop on itself is circular.)
- **Lessons learned:**
  1. Skill-based entry is a cleaner interface than "read the template". The orchestrator skill in particular is the big win — it auto-fills every placeholder from disk, reducing a 3-minute hand-fill to a 10-second slash command. The architect skill is thinner but still useful because it forces the developer to answer the shaping questions up-front, which improves the architect chat's first turn materially.
  2. Moving templates into `assets/` matches the Claude Code plugin convention and aligns with how the rest of the ecosystem structures skill-adjacent material. Adjacent projects in `.claude/skills/` use `references/` and `rules/`; `assets/` is semantically right for "material the skill emits" and reads naturally.
  3. Cross-reference updates across a few files are a one-time migration cost — cheap when the templates already name paths by repo-relative absolute. The discipline of never using relative `./` references in the templates made this a near-mechanical edit.
