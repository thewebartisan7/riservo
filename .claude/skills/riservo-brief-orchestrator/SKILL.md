---
name: riservo-brief-orchestrator
description: Prepare the ready-to-paste prompt for starting a roadmap-orchestrator session. Reads the active roadmap, pulls baseline commit and test count from docs/HANDOFF.md, resolves the next free D-NNN and the relevant DECISIONS-*.md topical files, pre-flight-checks the codex plugin, and emits a fully-filled orchestrator prompt — no questions asked unless multiple active roadmaps exist or the plugin is missing. Use when the user says "start the orchestrator", "brief the orchestrator", "ready to orchestrate ROADMAP-X", "prepare the orchestrator prompt", or mentions spinning up Layer 3 (ORCHESTRATION) of the riservo workflow. Do NOT use this skill to perform orchestration itself — it only prepares the prompt for a separate fresh chat.
---

# riservo-brief-orchestrator

Prepare the initial prompt for a **roadmap-orchestrator session** (Layer 3 of the riservo four-layer work flow, see `docs/README.md` § "The four-layer work flow"). This skill does NOT orchestrate — it reads the just-active roadmap from disk, fills the orchestrator template from project state (no developer questions unless the state is ambiguous), and emits a ready-to-paste block for the developer to drop into a fresh chat.

## When to invoke

- User says "start the orchestrator", "brief the orchestrator for ROADMAP-X", "ready to orchestrate", "prepare the orchestrator prompt".
- User just finished the architect session (the roadmap flipped to `status: active`) and wants to begin session coordination.
- User wants to re-spin an orchestrator on a roadmap that is already active (e.g., the prior orchestrator hit the context escape hatch).

## When NOT to invoke

- The roadmap is still `status: draft` or `planning` — the architect has not finished; run `/riservo-brief-architect` first, or iterate with the architect agent.
- There is no active roadmap (`docs/ROADMAP.md` has no `## In flight` row at `status: active`) — tell the developer there is nothing to orchestrate.
- The task is a single one-off session with no roadmap context — brief an implementer directly via `.claude/skills/riservo-brief-orchestrator/assets/SESSION-IMPLEMENTER.md`; orchestration overhead is not worth it for one session.

## How to run

1. **Read both templates.** Load `assets/ROADMAP-ORCHESTRATOR.md` (primary) and `assets/SESSION-IMPLEMENTER.md` (the body the orchestrator will fill into its `Agent` tool call in Phase 1). The orchestrator prompt references the implementer template by full repo-relative path; keep it consistent.
2. **Find the target roadmap.**
   - **First, parse the user's request for an explicit roadmap name** (e.g. "brief the orchestrator for `ROADMAP-PAYMENTS`", "ready to orchestrate ROADMAP-X"). If a name is supplied, resolve ONLY that roadmap — do NOT fall back to generic active-roadmap discovery. Open its frontmatter:
     - If the named roadmap does not exist on disk, stop and tell the developer.
     - If it exists but `status:` is not `active`, stop and tell the developer explicitly what the current status is and what they need to do (e.g. "`ROADMAP-PAYMENTS` is `draft` — finish the architect pass first"). Do NOT silently substitute a different roadmap.
     - If it is `active`, proceed.
   - **If no name was supplied**, fall back to auto-discovery:
     - Read `docs/ROADMAP.md`. Identify rows under `## In flight`.
     - Open each candidate roadmap's frontmatter; keep only those at `status: active`.
     - Zero active: stop and tell the developer. Suggest running `/riservo-brief-architect` first, or checking whether the architect has flipped `status: planning → active`.
     - One active: proceed.
     - Multiple active: ask the developer which one.
3. **Extract roadmap context.** From the chosen `docs/roadmaps/ROADMAP-[NAME].md`, pull:
   - Roadmap name (from frontmatter `name:`).
   - One-line scope (from frontmatter `description:`).
   - Session count: count top-level `^## Session ` headings (note the trailing space — only per-session H2s, not section headings like `## Sessions 12–13 — Superseded …`). The current roadmap format puts each session at H2 and has no `## Sessions` wrapper. If a roadmap instead uses H3 per session (`^### Session `, e.g. the legacy `ROADMAP-CALENDAR.md` format), fall back to counting those and note the deviation to the developer.
   - First session title: the first `## Session 1 — …` heading (or the first H3 equivalent if the roadmap uses the legacy H3 format). For roadmaps whose first session is not numbered `1` (e.g. `## Session E2E-0 — …` or `## Session F1 — …`), take the first session heading as-is and surface the literal id to the developer.
   - The `DECISIONS-*.md` topical files referenced in the read-first lists or the Cross-cutting decisions section.
   - Starting `D-NNN` — in this order of authority: (1) if the roadmap's Cleanup tasks section or a Cross-cutting decisions blurb names the starting ID explicitly, use it; (2) else if the roadmap or topical decisions file redirects to HANDOFF (e.g. `DECISIONS-PAYMENTS.md`'s header says the counter lives in HANDOFF), read it from `docs/HANDOFF.md` § "What is next" or the most recent "next free ID" line; (3) else read the topical decisions file directly and pick the first unused ID after the highest existing `D-NNN`. If all three fail (a brand-new domain whose topical file is absent), default to the value HANDOFF's shipped-sessions table last reported plus one, and flag the ambiguity in the emitted prompt.
   - Whether the roadmap declares `parallel_sessions: [S1, S2a, …]` in its frontmatter. Extract the list verbatim if present; absent, every session is serial.
4. **Capture the roadmap-start hash from git.** This value is informational only — it is NOT the codex review base. The codex review base is `session_base`, captured per-session by the orchestrator at each session's Phase 1 (before the sub-agent makes any commits) and persisted in that session's plan frontmatter as `session_base:`. The skill does NOT derive `session_base`; the orchestrator template does, per its Phase 1 step 2. The skill only captures a roadmap-start breadcrumb so the orchestrator has a sanity reference against HANDOFF.
   - `git rev-parse --abbrev-ref HEAD` — record the current branch. Expect `main` (or a long-lived branch the developer names). If HEAD is detached or on an unexpected branch, stop and ask which branch is canonical.
   - `git rev-parse HEAD` — this is the value to fill `[BASELINE HASH]` in the emitted prompt. Label it roadmap-start / informational in the brief's header (the template already does).
   - `git status --porcelain` — record the count of uncommitted paths. Surface the count in the emitted prompt advisorily.

   From `docs/HANDOFF.md`, additionally pull:
   - The "last shipped commit" line — call it `<handoff-hash>`.
   - Current Feature+Unit test count (`{N} passed / {M} assertions`).
   - Whether the chosen roadmap is listed as "next active" or similar.

   If `<handoff-hash>` and the git-derived `[BASELINE HASH]` differ, carry an advisory line in the emitted prompt: "HANDOFF records `<handoff-hash>` as the last shipped commit; git HEAD is `<baseline-hash>`. HANDOFF should be refreshed in the next HANDOFF-rewriting session; this affects narrative only — codex review is pinned per-session via `session_base`, not via `[BASELINE HASH]`." Do NOT ask the developer to pick between them.
5. **Pre-flight the codex plugin.** Check `/Users/mir/.claude/plugins/installed_plugins.json` for `codex@openai-codex`. If present, confirmed. If absent, the emitted prompt must open with the install block:

   ```
   # Before starting: install the codex plugin.
   /plugin marketplace add openai/codex-plugin-cc
   /plugin install codex@openai-codex
   /reload-plugins
   /codex:setup
   ```

   If detection is unreliable (file missing, ambiguous), print the install block with a "skip if already installed" note — a false negative costs one extra line in the prompt, not a failed session.
6. **Fill the orchestrator template.** Replace every `[BRACKETED]` placeholder in `assets/ROADMAP-ORCHESTRATOR.md` using the state gathered:
   - `[ROADMAP NAME]` — from step 3.
   - `[ONE-LINE SCOPE …]` — from step 3.
   - `[N]` — session count.
   - `[SESSION-1 TITLE]` — from step 3.
   - `[LIST THE RELEVANT DECISIONS-*.md TOPICAL FILES …]` — from step 3; format as a literal list with one-line descriptions of each file's relevance.
   - `[NNN]` in the "next available decision ID" blurb — from step 3.
   - `[BASELINE HASH]` — substitute the git-derived hash from step 4 (`git rev-parse HEAD`). This is the roadmap-start breadcrumb, NOT the codex review base. Label its fill site as informational. Codex base is per-session `session_base`, captured by the orchestrator at each session's Phase 1, not by this skill.
   - Test count — from step 4, in the "your first task" confirmation bullets.
7. **Emit the block.** Return a single message containing:

   - One lead-in line: "Codex plugin: **installed** / **NOT installed — install block included**."
   - If the roadmap declares a `parallel_sessions:` list: one extra line naming the actual session ids from the key, e.g. "This roadmap declares `parallel_sessions: [S1, S2a, S2b]` — the orchestrator may spawn parallel implementer sub-agents for those specific sessions per Appendix A. Every other session remains serial."
   - The fenced code block with the filled template body (from the `## Template — copy from here down` section onward, every placeholder filled).

8. **Close with one sentence.** Remind the developer this chat can be closed (the skill's job is done); the next step is to paste the block into a **fresh chat** where the orchestrator will live for the whole roadmap, interacting via SendMessage between sessions.

## What the skill does NOT do

- Does not coordinate the roadmap. The orchestrator does, in a separate fresh chat.
- Does not spawn sub-agents, invoke `/codex:review`, or commit anything.
- Does not modify any roadmap, plan, index, or decision file.
- Does not run `php artisan docs:check` — that is the orchestrator's discipline at each session close, not this skill's concern.

## Escalation

If state is ambiguous (stale HANDOFF, multiple active roadmaps the developer won't disambiguate, `installed_plugins.json` unreadable, roadmap with no session headings), say so plainly. Do NOT emit a half-filled prompt and hope. The orchestrator template is strict about read-first discipline — a broken brief breeds confusion for the whole roadmap.

If the developer asks for the orchestrator to do something the template forbids (skip plan approval, auto-commit, run parallel sub-agents on a serial roadmap), refuse via the template's rules-of-engagement rather than silently relaxing them.
