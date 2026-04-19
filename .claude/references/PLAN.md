# Writing a session plan — `docs/PLAN.md`

This document specifies the shape of a **session execution plan** ("ExecPlan") for riservo.ch. The plan lives at `docs/PLAN.md` — a single file overwritten at the start of every new session. Git history preserves every prior plan.

Treat the reader of the plan as a novice to this repository: they have the current working tree, the four live docs (`docs/SPEC.md`, `docs/ROADMAP.md`, `docs/HANDOFF.md`, and this `docs/PLAN.md` once you write it), and nothing else. There is no memory of prior plans, no external context beyond what's on disk.

## How to use this reference

When authoring a session plan, follow this document to the letter. If it is not in your context, refresh by reading the whole file. Be thorough in reading (and re-reading) source material — `SPEC.md`, the relevant `docs/decisions/DECISIONS-*.md` files, the code you'll touch — to produce an accurate plan. Start from the skeleton at the bottom of this file and flesh it out as your research progresses.

When implementing an approved plan, do not prompt the developer for "next steps" between milestones — proceed to the next one. Keep all sections up to date. Add or split entries in `## Progress` at every stopping point so the list always reflects reality. Resolve technical ambiguities in the plan with explicit justification in `## Decision Log`. For product, policy, or scope decisions, raise them as `## Open Questions` for the developer to resolve — ideally before code starts, mid-work if they surface later. Do not silently guess on product calls.

When revising the plan, record every change in `## Decision Log` for posterity; any reader must be able to tell why the plan diverged from its original shape. Plans are living documents — the goal is that a fresh implementer can restart from only `SPEC.md + HANDOFF.md + ROADMAP.md + PLAN.md` with no other context.

When the work has meaningful unknowns, use an explicit prototyping milestone (see § "Prototyping milestones" below) to validate feasibility before committing to a full implementation.

## Non-negotiable requirements

- Every plan must be **fully self-contained** given the four live riservo docs. A novice agent handed `SPEC.md + HANDOFF.md + ROADMAP.md + PLAN.md` must be able to implement the session end-to-end without any other context.
- Every plan is a **living document**. Revise as progress is made, as discoveries surface, as design decisions finalize. Each revision stays self-contained.
- Every plan must produce **demonstrably working behavior** — not just code changes that "meet a definition".
- Every plan must **define every term of art** in plain language on first use, or refrain from using it.

Purpose and intent come first. Begin by explaining, in a few sentences, why the work matters from a user's perspective: what someone can do after this change that they could not do before, and how to see it working. Then guide the reader through the exact steps — what to edit, what to run, what they should observe.

The agent executing the plan can list files, read files, search, run tests, and run the dev server. **It never commits.** Stage changes frequently; report at stopping points. The developer is the sole commit authority — there is no carve-out.

**Ceremony sequence**: plan → developer approval → exec → codex review → developer commit. Codex review runs against the **staged / working-tree state** (not against a prior commit); fixes land on the same uncommitted diff; the developer commits once at the end with the full reviewed change bundled. Multiple review rounds can stack pre-commit if needed — each round's findings get their own subsection in `## Review`. No interim commits required.

Repeat any assumption you rely on. Do not point at external blogs or third-party documentation; if specific knowledge is required (e.g., Stripe Connect webhook semantics, Pest 4 browser API details), embed a summary in the plan in your own words. If the plan builds on a prior plan preserved in git history (`git log --follow docs/PLAN.md`), quote the relevant parts directly — don't rely on the reader to run git archaeology.

## Formatting

The plan lives as **plain markdown** at `docs/PLAN.md`. Standard fenced code blocks — triple-backtick `bash`, `diff`, `php`, `typescript`, `json`, `sql` — are allowed and encouraged for commands, transcripts, diffs, and code snippets. They render cleanly in GitHub, VS Code preview, and the repository's Obsidian vault.

Use `#`, `##`, `###` for headings with two newlines after each. Use correct syntax for ordered and unordered lists.

Write in plain prose. Prefer sentences over lists. Avoid tables unless the content is genuinely tabular (a test-case matrix, a file-change roster with columns). Checklists are permitted only in `## Progress`, where they are mandatory. Narrative sections stay prose-first.

## Guidelines

**Self-containment and plain language are paramount.** If you introduce a phrase that is not ordinary English ("tenant context", "GIST overlap constraint", "Wayfinder route", "magic link", "pending action"), define it on first use and name the files or commands where it manifests in riservo. Do not say "as defined in SPEC §7.6 which you already read" — embed the needed explanation here, even if it repeats. The plan has to work for a reader who hasn't yet opened SPEC.

**Resolve ambiguities in the plan.** When two approaches compete, pick one and explain why in `## Decision Log`. When you genuinely cannot pick because it is a product / policy / scope call, record it in `## Open Questions` for the developer to resolve — never silently guess on those. Asking mid-implementation is acceptable when something surfaces late; guessing is not.

**Anchor acceptance in observable outcomes.** State what the user can do after implementation, the exact commands to run, and the outputs they should see. Acceptance is behavior a human can verify ("after starting the dev server, visiting `/dashboard/settings/payments` shows a 'Connect with Stripe' button; clicking it redirects to `connect.stripe.com/express/oauth/...`") — not internal attributes ("added a `ConnectAccountService` class"). If the change is internal, explain how its impact can still be demonstrated — typically a test that fails before and passes after, with the diff shown.

**Specify repository context explicitly.** Name files with full repo-relative paths (`app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php`). Name functions precisely (`Booking::shouldSuppressCustomerNotifications()`). Describe where new files go. If touching multiple areas, include a short orientation paragraph that explains how those parts fit together so a novice can navigate confidently.

**Be idempotent and safe.** Write steps so they can be re-run without damage or drift. If a step can fail halfway, include a retry or recovery path. For migrations or destructive operations, spell out backups and safe fallbacks. Prefer additive, testable changes you can validate as you go.

**Validation is not optional.** Include instructions to run tests, start the system, and observe useful behavior. Describe comprehensive testing for every new feature. Include expected outputs and error messages so a novice can tell success from failure. State the exact test commands — riservo's iteration loop is `php artisan test tests/Feature tests/Unit --compact`; full suite (developer, pre-push) is `php artisan test --compact`. Show how to prove the change is effective beyond compilation: a small end-to-end scenario, a CLI invocation, an HTTP transcript.

**Capture evidence.** When steps produce terminal output, diffs, or logs that prove success, include them as fenced snippets in the plan. Keep them concise and focused.

## Milestones

Milestones are narrative, not bureaucracy. Introduce each with a paragraph describing scope, what will exist at the end of the milestone that did not exist before, the commands to run, and the acceptance you expect to observe. Read as a story: **goal → work → result → proof**.

Progress and milestones are distinct: milestones tell the story, `## Progress` tracks granular work. Both must exist for any plan larger than a single trivial change.

Each milestone must be **independently verifiable** and incrementally implement the overall goal. Never abbreviate a milestone for brevity — details that might be crucial to a future implementer stay in.

## Prototyping milestones and parallel implementations

Include an explicit prototyping milestone when it de-risks a larger change. Examples in the riservo context:

- Validate that a specific Stripe Connect API shape behaves as documented (e.g., `checkout.sessions.retrieve` with `stripe_account` header on a suspended account) by writing a tiny test-only invocation before the real path.
- Spike a GIST constraint on a fixture table to confirm the query-planner behavior before applying the migration to `bookings`.
- Try two Inertia prop shapes side-by-side and measure re-render cost before committing to one.

Keep prototypes additive and testable. Label the milestone as "Prototyping" explicitly. Describe how to run and observe the prototype. State the criteria for promoting it to the full implementation or discarding it.

Prefer additive changes followed by subtractions that keep tests green at every stopping point. Parallel implementations — keeping the old code path alongside the new one during a migration — are fine when they reduce risk or let tests keep passing. Describe how to validate both paths and how to retire the old one safely once the new one proves itself.

## Living plans and design decisions

- Plans are living documents. As you make key design decisions, update the plan to record both the decision and the thinking behind it in `## Decision Log`.
- Plans must contain `## Progress`, `## Surprises & Discoveries`, `## Decision Log`, and `## Outcomes & Retrospective`. These are not optional, even on tiny plans (use a single-line "nothing to report" entry if there's genuinely nothing).
- When you discover unexpected bugs, framework quirks, performance tradeoffs, or any observation that shaped your approach, capture it in `## Surprises & Discoveries` with concise evidence — test output is ideal, a `dd()` dump, a log line, a query plan.
- If you change course mid-implementation, document why in `## Decision Log` and reflect implications in `## Progress`.
- At completion of a major milestone or the full session, write an `## Outcomes & Retrospective` entry summarizing what was achieved, what remains, and lessons learned.

## Codex review findings

When the developer runs codex review against the staged / working-tree state, the findings are made available to the agent in one of two ways: (a) the review is run inside the plan+exec session's own chat via `/codex:review` or `/codex:adversarial-review` — the agent reads the output directly from the transcript; or (b) the review is run in a separate chat or terminal and the developer pastes the findings back. Either way, the agent applies the fixes under a dedicated `## Review` section in the plan, structured one subsection per round:

```
## Review — Round N

**Codex verdict**: <one-line summary of codex output>

- [ ] **Finding 1** — <one-line description>.
  *Location*: `path/to/file.php:123-134`.
  *Fix*: <what you'll change, in concrete terms>.
  *Status*: pending | in progress | done.

- [ ] **Finding 2** — …
```

Keep one subsection per review round; they stack. As each fix lands on the staged diff, check the box and mark `Status: done`. The `## Review` section stays in the plan for the life of the session — it's the durable record of quality care.

After the developer's final commit, optionally annotate the section header with `(committed as <short-hash>)` so the record is linkable from git log. No interim commit hashes are required because the sequence is review-before-commit: plan → approve → exec → review → commit.

## One discipline rule for decision durability

Before the next session overwrites `docs/PLAN.md`, **promote any new architectural decisions (`D-NNN`) from this plan into the matching `docs/decisions/DECISIONS-{TOPIC}.md` topical file**. Decisions that live only in `PLAN.md` disappear on overwrite. Better: write decisions directly into the topical file during implementation and reference the `D-NNN` from `PLAN.md`.

The next free decision ID lives in `docs/HANDOFF.md`; re-read HANDOFF for the authoritative current value at the start of every session.

## Session close checklist

Before the developer commits the close artifacts:

- Iteration-loop tests pass: `php artisan test tests/Feature tests/Unit --compact`.
- Code style clean: `vendor/bin/pint --dirty --format agent`.
- Wayfinder regenerated: `php artisan wayfinder:generate`.
- Frontend builds: `npm run build`.
- Any new `D-NNN` has been promoted into the appropriate `docs/decisions/DECISIONS-{TOPIC}.md` (do not leave decisions only in `PLAN.md`).
- `docs/HANDOFF.md` rewritten (overwrite, not append) if the session changed shipped product or runtime state. Docs-only sessions may skip.
- `## Outcomes & Retrospective` populated.

---

## Skeleton of a good plan

```markdown
# <Short, action-oriented description — e.g. "Stripe Connect Express Onboarding">

This plan lives at `docs/PLAN.md` and follows `.claude/references/PLAN.md`. It is a living document. The sections `Progress`, `Surprises & Discoveries`, `Decision Log`, `Review`, and `Outcomes & Retrospective` are kept up to date as work proceeds.


## Purpose / Big Picture

Explain in a few sentences what someone gains after this change and how they can see it working. State the user-visible behavior you will enable.


## Progress

Checkbox list of granular steps. Every stopping point is documented here, splitting partially completed tasks into "done: X / remaining: Y" if needed. Always reflects the true current state.

- [x] (2026-04-20 09:15Z) Example completed step.
- [ ] Example incomplete step.
- [ ] Example partially completed step (done: X; remaining: Y).

Use UTC timestamps so the developer can gauge pace and you can see where you got stuck.


## Surprises & Discoveries

Document unexpected behaviors, bugs, framework quirks, or insights that shaped the implementation. Provide concise evidence.

- **Observation**: <short description>.
  **Evidence**: <test transcript | log line | file:line citation>.
  **Consequence**: <what you did about it>.


## Decision Log

Record every meaningful decision made during the session:

- **Decision**: <what was chosen>.
  **Rationale**: <why — reference the alternatives rejected if relevant>.
  **Date / Author**: 2026-04-20 / planning agent.


## Review

(Present only after codex review rounds run against the staged / working-tree state. See § "Codex review findings" in `.claude/references/PLAN.md`. Fixes land on the same uncommitted diff; one commit at the end bundles everything.)

### Round 1

**Codex verdict**: <one-line summary>

- [ ] **Finding 1** — <description>.
  *Location*: `path/to/file.php:123-134`.
  *Fix*: <concrete change>.
  *Status*: pending.


## Outcomes & Retrospective

Summarize outcomes, gaps, and lessons learned at major milestones or at session close. Compare the result against the original Purpose. Note any carry-overs to `docs/BACKLOG.md`.


## Context and Orientation

Describe the current state relevant to this task as if the reader knows nothing riservo-specific. Name key files and modules by full path. Define any non-obvious term on first use. Do not refer to prior plans by name — if a prior plan's content matters, quote it inline.


## Plan of Work

Describe, in prose, the sequence of edits and additions. For each edit, name the file and location (function, module, React component) and what to insert or change. Keep it concrete and minimal.


## Concrete Steps

State the exact commands to run and where (working directory). When a command produces output, show a short expected transcript so the reader can compare. Update this section as work proceeds.


## Validation and Acceptance

Describe how to start or exercise the system and what to observe. Phrase acceptance as behavior, with specific inputs and outputs. For tests: "run `php artisan test tests/Feature tests/Unit --compact --filter=ConnectedAccountTest` and expect N passed; the new test `<name>` fails before the change and passes after".


## Idempotence and Recovery

If steps can be re-run safely, say so. For risky steps, provide a retry or rollback path. Keep the environment clean after completion.


## Artifacts and Notes

The most important transcripts, diffs, or snippets as fenced examples. Keep them concise and focused on what proves success.


## Interfaces and Dependencies

Be prescriptive. Name the libraries, modules, services, routes, and Inertia props to use and why. Specify the types, method signatures, Wayfinder routes, and event payloads that must exist at the end of the milestone. Prefer stable names and repo-relative paths.

Example — in `app/Services/Payments/ConnectAccountService.php`, define:

    class ConnectAccountService
    {
        public function createExpressAccount(Business $business, string $country): StripeConnectedAccount;
        public function refreshVerificationStatus(StripeConnectedAccount $account): void;
    }

Example — in `resources/js/pages/dashboard/settings/connected-account.tsx`, expect Inertia props:

    interface ConnectedAccountPageProps {
        account: {
            status: 'not_connected' | 'pending' | 'active' | 'disabled';
            country: string | null;
            chargesEnabled: boolean;
            payoutsEnabled: boolean;
        };
    }


## Open Questions

Anything you cannot resolve from the four live docs, the code, or the session brief. Raise these before writing code; if they surface mid-work, pause and ask the developer.


## Risks & Notes

Known risks, fallback plans, compatibility concerns — anything a reviewer should weigh before approving the plan.
```

If you follow the guidance above, a stateless agent — or a human novice — can read the plan top-to-bottom and produce a working, observable result. That is the bar: **self-contained, self-sufficient, novice-guiding, outcome-focused**.

When you revise a plan, ensure your changes are reflected comprehensively across every section (including the living ones) and write a short note at the bottom of the plan describing what changed and why. Plans describe not just the *what* but the *why*, for almost everything.
