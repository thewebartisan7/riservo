# PLAN-DOCS-CLEANUP

TODO this file can be archived or deleted as it's already done.

## Goal

Clean up the `docs/` structure and normalize agent instruction files so Codex and Claude receive equivalent scoped guidance with lower long-term maintenance overhead.

## Current Problems

- `docs/` mixes live operational docs, future roadmaps, completed plans, review material, and a design asset in one flat top-level structure.
- `docs/DECISIONS.md` is too long for fast task-scoped reading.
- Claude already has scoped instruction files, but Codex does not.
- Instruction files and docs currently hardcode older assumptions about plan locations and reading order.
- There is no single docs entrypoint that clearly distinguishes required reading from optional or historical material.

## Proposed Structure

- Keep live operational docs at the root of `docs/`.
- Add `docs/README.md` as the documentation entrypoint.
- Add `docs/ARCHITECTURE-SUMMARY.md` as a concise architecture digest.
- Keep `docs/DECISIONS.md` as a stable decision index, with detailed topical files under `docs/decisions/`.
- Move secondary roadmaps to `docs/roadmaps/`.
- Move the design asset to `docs/design/`.
- Move completed plans to `docs/archive/plans/`.
- Keep active plans in `docs/plans/`.
- Add `docs/BACKLOG.md` for unscheduled UI/UX/debt follow-up notes.

## File-by-File Actions

- Create `docs/README.md`, `docs/ARCHITECTURE-SUMMARY.md`, `docs/BACKLOG.md`, and this plan file.
- Replace the monolithic `docs/DECISIONS.md` body with a compact index and split live decisions into topical files under `docs/decisions/`.
- Create `docs/decisions/DECISIONS-HISTORY.md` for superseded or resolved planning history.
- Move the secondary roadmap docs into `docs/roadmaps/`.
- Move the design asset into `docs/design/ui.pen`.
- Move completed implementation plans into `docs/archive/plans/`.
- Merge the old future-UX notes and deferred notes from `PLAN-UI-1.md` into `docs/BACKLOG.md`.
- Remove `docs/.DS_Store`.

## Instruction-File Mirroring Strategy

- Keep `AGENTS.md` and `CLAUDE.md` aligned at the repository root.
- Mirror `.claude/CLAUDE.md` to `.agents/AGENTS.md`.
- Mirror `resources/js/CLAUDE.md` to `resources/js/AGENTS.md`.
- Mirror `resources/js/components/ui/CLAUDE.md` to `resources/js/components/ui/AGENTS.md`.
- Add an explicit sync note at the top of each mirrored pair.
- Update root and scoped guidance so they point to `docs/README.md`, `docs/DECISIONS.md`, and the new plan/archive layout.

## Decision-File Strategy

- Keep `docs/DECISIONS.md` as the stable entrypoint to minimize path churn in references.
- Split live decision bodies by topic into:
  - `DECISIONS-FOUNDATIONS.md`
  - `DECISIONS-AUTH.md`
  - `DECISIONS-BOOKING-AVAILABILITY.md`
  - `DECISIONS-DASHBOARD-SETTINGS.md`
  - `DECISIONS-FRONTEND-UI.md`
  - `DECISIONS-CALENDAR-INTEGRATIONS.md`
- Preserve existing decision IDs and original decision text.
- Move only clearly historical or superseded items into `DECISIONS-HISTORY.md`.

## Reference-Update Strategy

- Update instruction files to point to `docs/README.md` and the new decision structure.
- Update roadmap and review docs that instruct future agents to append to `docs/DECISIONS.md`; they should instead record decisions in the appropriate topical file listed in `docs/DECISIONS.md`.
- Update any path references affected by moving plans, secondary roadmaps, or the design asset.
- Refresh `docs/HANDOFF.md` last so it reflects the finished structure.

## Risks

- Breaking discoverability by moving files without a strong index.
- Losing useful future notes when archiving plans.
- Letting mirrored instruction files drift after the cleanup.
- Confusing future sessions if `HANDOFF.md` and `ROADMAP.md` continue to disagree about session numbering.

## Rollout Order

1. Create new docs entrypoints and decision files.
2. Normalize instruction files and add missing mirrored `AGENTS.md` files.
3. Move secondary roadmaps, archive completed plans, and relocate the design asset.
4. Update references.
5. Rewrite `docs/HANDOFF.md`.
6. Verify parity, path correctness, and final tree shape.

## Definition of Done

- Codex and Claude have equivalent root and scoped guidance.
- `docs/README.md` clearly separates read-first material from optional and historical material.
- Decision discovery is task-scoped through `docs/DECISIONS.md` and `docs/decisions/`.
- Completed plans are archived without discarding useful historical context.
- Review/remediation workflow remains intact.
- `docs/HANDOFF.md` reflects the new structure and current next steps.
