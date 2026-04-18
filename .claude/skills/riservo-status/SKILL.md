---
name: riservo-status
description: Verify the riservo docs system is internally consistent — every process doc (plan, roadmap, review, handoff) has valid YAML frontmatter, every file is indexed in the right place (docs/PLANS.md or docs/ROADMAP.md), every index row points at an existing file, and the row's bucket matches the file's status. Use when the user asks for a docs health check, a sanity check after status flips, a pre-commit verification, or mentions "docs drift", "consistency check", "status check", or "riservo status".
---

# riservo docs status check

This skill verifies that the documentation system's frontmatter-vs-index contract is intact. It is the **ergonomic front door** to the underlying mechanical checker — the real truth engine lives in `app/Console/Commands/DocsCheckCommand.php` and runs as `php artisan docs:check`.

## When to invoke

Invoke whenever the user asks to:

- Check the docs are consistent.
- Verify status and index are in sync.
- Spot docs drift (e.g., "is my PLANS.md up to date?", "did I forget to flip a status anywhere?").
- Run a pre-commit sanity pass on docs changes.
- Close a session (the session-done checklist in `.claude/CLAUDE.md` calls for a consistency check).

Do not invoke for lint / prose / grammar — this skill is strictly mechanical.

## How to run

1. Run `php artisan docs:check --json` from the project root. The JSON output is stable and easy to group.
2. If the command returns exit code `0` with no findings, report "clean" and stop.
3. If there are findings, group them by severity (`error` first, then `warn`) and by file. Present them to the user with short suggested fixes next to each error.

## Interpreting findings

Each finding has `{severity, file, message}` keys. Common cases and how to phrase the fix:

- **"no YAML frontmatter found"** → the file needs a frontmatter block prepended. If the file is a temporary codex report (e.g. `REVIEW-DOC.md`), suggest deletion rather than adding frontmatter.
- **"frontmatter is missing required field `X`"** → add the field. Required fields are `name`, `description`, `type`, `status`, `created`, `updated`.
- **"status `X` is not in the taxonomy"** → use one of `draft | planning | active | review | shipped | superseded | abandoned`. See `docs/README.md` § "Status taxonomy".
- **"status `X` is unusual for type `Y`"** → a warning, not an error. Flag it to the user but do not fail; may be an intentional edge case.
- **"type `X` expects the file to live under …"** → the file is in the wrong directory for its declared type, or the type is wrong for the directory. Clarify with the user which is correct.
- **"frontmatter `name:` does not match filename"** → usually a copy-paste error; rename one or the other. Filename (minus `.md`) is usually authoritative.
- **"file is not indexed in `docs/PLANS.md`" / "in `docs/ROADMAP.md`"** → add a row to the index under the bucket matching the file's `status:` (`## In flight` for draft/planning/active/review; `## Shipped`; `## Superseded / Abandoned`).
- **"index row references `…` which does not exist on disk"** → either the file was deleted without removing the row, or the row path is a typo.
- **"file status `X` disagrees with `…` row status `Y`"** → one of them was flipped without the other. Usually the frontmatter is authoritative; update the index row to match.
- **"file status `X` should live under `## BUCKET` in …"** → the row is in the wrong bucket for its status. Move the row.
- **"status is `superseded` but `supersededBy:` is not set"** → a warning; add `supersededBy: path/to/successor.md` to the frontmatter.

## Presenting results

Default output shape:

```
## docs:check — {ERRORS}/{WARNINGS}

### Errors ({N})

- **{file}** — {message}
  Fix: {one-line suggested action}

### Warnings ({N})

- **{file}** — {message}
  Fix: {one-line suggested action}
```

If the check is clean, respond with a single line: `docs:check — clean. Frontmatter, indices, and bucket policy all agree.`

## What the script does NOT check

Be honest with the user about the checker's scope:

- It does not validate body prose, spelling, or grammar.
- It does not check that plan cross-references (e.g. `see PLAN-MVPC-3`) resolve.
- It does not verify commit hashes in `commits: []` actually exist in git.
- It does not verify dates in `created:` / `updated:` are sensible.
- Policy rules like "HANDOFF must be updated for product-touching sessions" live in `.claude/CLAUDE.md`; the checker does not enforce them.

Anything beyond mechanical frontmatter-and-index consistency is a human judgement call.

## Escalation

If the user asks for a check that requires the above (prose lint, cross-ref resolution, git verification), say so and do not fake it. Propose the shape of a follow-up rather than simulating the check.
