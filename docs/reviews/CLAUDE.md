# Reviews Guidance

This directory holds **cross-cutting audits** — reviews that sit above any single session. Examples: pre-launch security review, accessibility audit, full-suite performance audit, compliance sweep.

- Per-session codex reviews do **not** live here. They live inside the plan file under `## Post-implementation review`.
- Historical round-based reviews (`REVIEW-1`, `REVIEW-2`, `ROADMAP-REVIEW-1`, `ROADMAP-REVIEW-2`) are kept in this directory with `status: shipped` for historical reference.
- Do not bulk-read this directory. Open the specific review file referenced by the current task.
- For general project context, prefer the stable entrypoints first: `docs/README.md`, `docs/HANDOFF.md`, `docs/ROADMAP.md`, `docs/SPEC.md`, and `docs/DECISIONS.md`.
