# Prompt — E2E Testing Roadmap Review & Session Planning

## Your task

You are the planning agent for the riservo.ch project. Your job in this session is **not to write any test code**. Your only deliverable is an updated, implementation-ready version of the E2E testing roadmap.

---

## Step 1 — Read the project context

Read the following files in order before doing anything else. Do not skip any of them.

1. `docs/README.md` — documentation map and working rules
2. `docs/ROADMAP.md` — primary delivery roadmap (understand what has been built)
3. `docs/SPEC.md` — product scope, URL structure, roles, and domain model
4. `docs/ARCHITECTURE-SUMMARY.md` — current tech stack and file layout
5. `docs/DECISIONS.md` — decision index; open only the topical files relevant to testing and frontend
6. `docs/roadmaps/ROADMAP-E2E.md` — the E2E roadmap you will review and improve
7. `docs/roadmaps/PROMPT-E2E-SETUP.md` — the E2E-0 setup session prompt (how the infrastructure will be built)
8. `docs/roadmaps/PROMPT-E2E-ORCHESTRATOR.md` — the orchestrator session prompt (how subagents will be spawned and what file ownership rules apply)

Files 7 and 8 may be updated if your analysis of the codebase or your restructuring of
ROADMAP-E2E.md requires changes to session boundaries, file ownership, helper method
names, or parallelisation logic. If you modify them, ensure all three files remain
fully consistent with each other — ROADMAP-E2E.md is the source of truth, and the two
prompt files must always reflect it exactly.

---

## Step 2 — Analyse the actual codebase

After reading the documentation, inspect the codebase to verify that the roadmap reflects reality. At minimum:

- List every named route defined in `routes/web.php` and confirm that each one is covered by at least one E2E session in the roadmap. Flag any routes that are missing.
- Inspect `resources/js/pages/` to understand the actual page structure and identify any pages not covered by the roadmap.
- Inspect `tests/` to understand the current test structure (unit, feature, browser) and confirm the roadmap's proposed `tests/Browser/` layout does not conflict with what already exists.
- Check `composer.json` and `package.json` for any already-installed packages that are relevant to browser testing (e.g., `pestphp/pest-plugin-browser` or similar). If they are already present, note it — E2E-0 must not re-install what is already there.
- Check `phpunit.xml` or `pest.php` (whichever is present) to understand the current test configuration so E2E-0 can extend it without breaking the existing suite.
- Look at the existing feature tests to understand factory usage, `RefreshDatabase` patterns, and authentication helpers — the E2E helpers in `tests/Browser/Support/` must be consistent with these conventions.

Document your findings as a short **"Gap & Consistency Report"** at the top of the updated roadmap.

---

## Step 3 — Update and improve the roadmap

Using your findings, rewrite `docs/roadmaps/ROADMAP-E2E.md` in place. The revised roadmap must:

- Correct any flows, routes, or page names that differ from the actual implementation.
- Add any flows, routes, or pages that were missing from the original draft.
- Remove or adjust anything that describes behaviour that does not exist yet in the codebase (e.g., features still under active development or gated behind incomplete sessions). If a flow depends on a not-yet-complete session (e.g., Session 12 — Google Calendar Sync, or R-7 — current review session), note the dependency explicitly but keep the test in the roadmap as a future item.
- Keep the format and conventions of the original: WHAT only, no HOW. The implementing agent decides HOW at plan time.

---

## Step 4 — Split into parallelisable sessions with checklists

This is the most important output of this task. The roadmap sessions as drafted (E2E-0 through E2E-6) are high-level. You must break each session down into **individual test checklists** so that different agents in different sessions can pick up the work independently.

### Rules for session splitting

- **E2E-0 (Infrastructure)** must always run first and must be completed before any other session begins. It is the only mandatory serial dependency. Mark it clearly as `[MUST RUN FIRST — blocks all other sessions]`.
- After E2E-0 is complete, sessions that have no shared state or setup dependencies on each other **may run in parallel**. Mark these as `[CAN RUN IN PARALLEL]`.
- Sessions that depend on the output of a previous session (e.g., a test in E2E-4 requires the onboarding wizard to have been tested and the helpers to be proven in E2E-2) must be marked `[DEPENDS ON: E2E-X]` with the dependency stated explicitly.
- If two sessions touch the same files or helpers, note the conflict and either merge them or sequence them.

### Checklist format

Each session must contain a checklist in this format:

```
### Session E2E-X — [Title]
Status: [MUST RUN FIRST | CAN RUN IN PARALLEL | DEPENDS ON: E2E-Y]
Parallelisable with: [list of other sessions that can run concurrently, or "none"]

**Prerequisites**
- [ ] E2E-0 complete
- [ ] [any other prerequisites]

**Test checklist**
- [ ] [one line per discrete test or test group — specific enough that an agent can implement it without ambiguity]
- [ ] ...

**Files to create**
- `tests/Browser/[SessionName]/[TestFile].php`
- ...

**Files to modify** (shared helpers only — flag if two sessions need to touch the same file)
- `tests/Browser/Support/[Helper].php` — [what needs to be added]
```

Every test in the checklist must be specific enough to implement without further clarification. Vague items like "test the booking flow" are not acceptable — write "Guest completes full booking funnel: service → date → time → details → confirm → confirmation screen shows management link."

---

## Step 5 — Verify parallelisation correctness

After splitting the sessions, produce a **dependency graph** (ASCII is fine) showing which sessions can run in parallel and which are serial. This graph must be included at the bottom of the updated roadmap.

Example format:

```
E2E-0  [serial — must complete first]
  │
  ├─▶ E2E-1  [parallel group A]
  ├─▶ E2E-2  [parallel group A]
  │     └─▶ E2E-4  [depends on E2E-2]
  └─▶ E2E-3  [parallel group A]
```

---

## Constraints

- Do **not** write any test code. Your output is documentation only.
- Do **not** modify any files other than `docs/roadmaps/ROADMAP-E2E.md`.
- Do **not** run `php artisan test` or any tests. Read only.
- The updated roadmap must remain consistent with the conventions in `docs/README.md` (WHAT, not HOW; active plans in `docs/plans/`; decisions recorded in the appropriate topical file).
- Every session in the final roadmap must leave the full test suite green when executed. Note this requirement in each session header.

---

## Output summary

When you are done, confirm:

1. Which routes/pages were missing from the original roadmap and have been added.
2. Which items in the original roadmap were inaccurate and have been corrected.
3. The final session list with parallelisation status.
4. Any open questions or blockers you found that a human should resolve before implementation begins.
