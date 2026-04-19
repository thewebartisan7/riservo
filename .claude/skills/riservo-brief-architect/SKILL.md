---
name: riservo-brief-architect
description: Prepare the ready-to-paste prompt for starting a roadmap-architect session. Asks 2–4 shaping questions about the developer's intent, fills every [BRACKETED] placeholder in the architect template, and emits a copy-paste block to paste into a fresh chat. If a matching draft roadmap already exists in docs/roadmaps/, offers the Mode-B stress-test variant. Use when the user says "start a new roadmap", "I want to plan X", "brief the architect", "prepare the architect prompt", or mentions spinning up Layer 2 (ROADMAP) of the riservo workflow. Do NOT use this skill to perform the architect work itself — it only prepares the prompt for a separate fresh chat.
---

# riservo-brief-architect

Prepare the initial prompt for a **roadmap-architect session** (Layer 2 of the riservo four-layer work flow, see `docs/README.md` § "The four-layer work flow"). This skill does NOT draft the roadmap itself — it asks the developer a few shaping questions, fills the architect prompt template, and emits a ready-to-paste block for the developer to drop into a fresh chat.

## When to invoke

- User says "start a new roadmap", "I have an idea for X", "let's plan Y", "brief the architect", "prepare the architect prompt".
- User describes an intent that clearly belongs at Layer 2 (WHAT, not HOW) — scope spans more than a single session, involves cross-cutting decisions, or needs multiple sessions to ship.
- User has an existing draft roadmap in `docs/roadmaps/` at `status: draft` and wants it stress-tested before locking.

## When NOT to invoke

- The task is a single session of an already-active roadmap — that's Layer 3/4 territory; use `/riservo-brief-orchestrator` or brief an implementer directly.
- The task is trivial (a hotfix, a one-file change, a dependency bump) — the architect template is overkill; brief an implementer directly via `.claude/skills/riservo-brief-orchestrator/assets/SESSION-IMPLEMENTER.md`.
- The request is for information, advice, or a sanity check — this skill emits prompts, not answers.

## How to run

1. **Read the template.** Load `assets/ROADMAP-ARCHITECT.md` end-to-end. Keep a mental map of every `[BRACKETED]` placeholder so the shaping questions you ask to the developer map one-to-one onto placeholders you will fill.
2. **Detect Mode A vs Mode B.** Read `docs/ROADMAP.md` (the index — do NOT bulk-read `docs/roadmaps/`; that directory is on the "do not search by default" list in `.claude/CLAUDE.md`). Run two separate passes against the rows, since the In-flight bucket covers draft/planning/active/review and the Shipped bucket is where historical collisions live:

   **Pass 1 — `## In flight` search.** Count rows whose `description` or `name` share a keyword with the developer's topic.
   - **0 in-flight matches** — fall through to Pass 2.
   - **1 in-flight match** — open ONLY that roadmap's frontmatter and branch on its `status:`:
     - `status: draft` or `status: planning` — ask: "I see `docs/roadmaps/ROADMAP-[NAME].md` at `status: [draft|planning]`. Mode B (stress-test / revise the existing draft) or Mode A (start a different fresh roadmap)?" Proceed on the developer's answer.
     - `status: active` or `status: review` — **refuse implicit Mode A**. Reply: "`ROADMAP-[NAME]` is already active. A fresh Mode-A draft would create a duplicate. Three options: (1) direct-edit the active roadmap for minor changes (open it and I'll help), (2) supersede it with a new draft whose `supersededBy:` points at the new file, (3) confirm explicitly that you want a parallel/sibling roadmap on the same topic — only then will I proceed with Mode A." Wait for the developer's explicit choice; do not proceed silently.
   - **>1 in-flight matches** — list the matching rows (name + description + status) and ask which one (or "none, start fresh"). Do not pick one heuristically.

   **Pass 2 — `## Shipped` search.** Only if Pass 1 returned zero in-flight matches (or the developer explicitly chose "none, start fresh" on a >1 case). Count `## Shipped` rows whose `description` or `name` share a keyword with the topic.
   - **0 shipped matches** — default to Mode A (start fresh, no confirmation needed).
   - **≥1 shipped match** — ask: "A prior roadmap on this topic shipped at `docs/roadmaps/ROADMAP-[NAME].md`. Proceed with a fresh Mode-A roadmap (the old one stays as historical record) or was this topic already fully delivered?" Wait for confirmation; a stale-keyword false positive is harmless, but silently drafting a duplicate of shipped work is not.
3. **Ask the shaping questions.** Keep to 2–4 questions; the architect chat itself will probe further. Typical set:
   - **One-line intent.** Example: "What's the one-line version of what you want to ship?" If the developer gave it in the invocation, skip this question.
   - **Surface area.** Example: "Which models / controllers / frontend pages does this touch? Rough sketch is fine."
   - **Probing areas (top 2–3).** Example: "What's the trickiest part that a critic should hammer on? (concurrency, external-service failure, invariant-respect, auth-boundary, localisation, …)"
   - **Settled decisions.** Example: "Any decisions already locked from prior conversation that I should pre-record so the architect does not re-open them?"
4. **Fill the template.** Replace every `[BRACKETED]` placeholder in `assets/ROADMAP-ARCHITECT.md` with the developer's answers:
   - `[ROADMAP TOPIC]` — from the one-line intent.
   - `[LIST THE PHP MODELS …]`, `[LIST EXISTING CONTROLLERS …]`, `[LIST ANY FRONTEND PAGES …]` — from the surface-area answer.
   - `[PASTE THE DEVELOPER'S INTENT HERE …]` — from the one-line intent + any elaboration given.
   - `[PROBING AREA 1..N]` — from the probing answer.
   - `[IF THE ROADMAP HAS ALREADY GONE THROUGH A ROUND …]` — from settled-decisions answer, or omit the whole optional block.
   - Mode selection — A or B per step 2. Fill the "First task" section with the matching example text from the template.
5. **Emit the block.** Return a single fenced code block with a one-line lead-in, like:

   > Copy the block below into a fresh chat and send. It will spin up the architect on `ROADMAP-[NAME]`.

   Then the fenced block containing the filled template body (everything from `## Template — copy from here down` in the template, with placeholders filled). Do NOT summarise or paraphrase — the architect reads the block literally.

6. **Close with one sentence.** Remind the developer that the architect will ask clarifying questions, draft the roadmap at `docs/roadmaps/ROADMAP-[NAME].md` with `status: draft → planning`, and stop for review. The developer iterates, approves, and the architect flips to `status: active` before exiting.

## What the skill does NOT do

- Does not draft the roadmap. The architect does, in a separate fresh chat.
- Does not commit, edit `docs/ROADMAP.md`, or touch any existing roadmap file.
- Does not spawn sub-agents. This skill is a prompt-builder.
- Does not run `php artisan docs:check`, `vendor/bin/pint`, or any validation — the architect session handles that at close.

## Escalation

If the developer's intent does not fit Layer 2 (it's too small, too ambiguous, or already partially implemented), say so plainly and propose the right layer instead. Examples:

- "That looks like a single-session task. Do you want me to brief an implementer directly instead?"
- "That intent is still at the exploration stage — we should talk it through before drafting an architect brief. What outcome would you want to measure?"

Skills serve the workflow, not the other way around. Push back when the workflow is wrong for the task.
