# Instruction File Maintenance

Codex (`AGENTS.md`) and Claude (`CLAUDE.md`) share scoped project instructions across this repository. Each paired file must stay byte-identical in body content; edit both in the same commit to prevent drift.

## Mirrored pairs

- `CLAUDE.md` ↔ `AGENTS.md` (repository root — Laravel Boost–managed)
- `.claude/CLAUDE.md` ↔ `.agents/AGENTS.md` (project rules)
- `resources/js/CLAUDE.md` ↔ `resources/js/AGENTS.md` (frontend rules)
- `resources/js/components/ui/CLAUDE.md` ↔ `resources/js/components/ui/AGENTS.md` (COSS UI primitives rules)
- `docs/plans/CLAUDE.md` ↔ `docs/plans/AGENTS.md` (plans guardrail)
- `docs/reviews/CLAUDE.md` ↔ `docs/reviews/AGENTS.md` (reviews guardrail)

## Ownership split at the repository root

- Root `CLAUDE.md` / `AGENTS.md` host the Laravel Boost–managed guideline block. Treat them as tool-owned; the block may be regenerated.
- Project-owned rules (documentation entry point, session workflow, critical rules, conventions, subtree guardrails) live in `.claude/CLAUDE.md` and `.agents/AGENTS.md`. Add new project-level rules there, not at the repository root.
