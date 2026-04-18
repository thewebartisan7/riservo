# Plans Guidance

This directory contains every session plan in the project — draft, planning, active, review, shipped, superseded, abandoned. Files do not move when they ship; `status:` in the frontmatter flips instead.

- Do not bulk-read this directory. Use `docs/PLANS.md` to find the plan relevant to the current task, then open only that file.
- Plan files hold the whole session lifecycle. Pre-implementation sections are written before approval; after approval the plan grows living-document sections: `## Progress`, `## Implementation log`, `## Surprises & discoveries`, `## Post-implementation review`, `## Close note / retrospective`. Which of these are mandatory depends on session type — see `docs/README.md` § "Session lifecycle in a plan file".
- When a plan's status changes (approval → `active`, implementation done → `review`, codex clean → `shipped`, mid-session stop → `abandoned` or `superseded`), update **both** the file's frontmatter `status:` / `updated:` and its row bucket in `docs/PLANS.md`.
- For general project context, prefer the stable entrypoints first: `docs/README.md`, `docs/HANDOFF.md`, `docs/ROADMAP.md`, `docs/SPEC.md`, and `docs/DECISIONS.md`.
