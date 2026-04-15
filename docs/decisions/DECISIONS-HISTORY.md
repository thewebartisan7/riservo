# Decision History

This file keeps superseded decisions and resolved planning questions that are still useful for historical context.

---

### D-011 — Blueprint for initial data layer generation
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: Manually writing migrations, models, and factories for 10+ models is repetitive and error-prone. Laravel Blueprint generates consistent, relationship-aware code from a single YAML file.
- **Decision**: Session 2 uses Laravel Blueprint (`draft.yaml`) to generate the initial data layer. The generated code is reviewed and adjusted before proceeding.
- **Consequences**: Faster, more consistent initial scaffolding. Agents in subsequent sessions should not re-run Blueprint — manual migrations are used for any schema changes after Session 2.

---

### P-001 — Assignment strategy: configurable or always first-available? (Resolved)
- **Date**: 2026-04-12
- **Status**: resolved — see D-028
- **Context**: SPEC §5.4 says automatic collaborator assignment strategy is "configurable (round-robin or first-available)." It is worth questioning whether this needs to be configurable at all in MVP.
- **Proposal**: Add `assignment_strategy` enum to Business (`first_available` | `round_robin`). Alternatively, always use `first_available` for MVP and defer configurability. The Session 3 agent should evaluate both options during planning and propose the right approach — including whether the complexity of round-robin is justified for MVP.
- **Resolution**: Both strategies implemented. Round-robin uses a "least-busy" approach (D-028).

---

### P-002 — React i18n approach (Resolved)
- **Date**: 2026-04-12
- **Status**: resolved — see D-033
- **Context**: SPEC §14 says all user-facing strings use `__()` in PHP and React. `__()` is PHP-only — the React side needs a JS mechanism. A common pattern with Laravel + Inertia is passing a JSON translation file via shared props.
- **Resolution**: Simple `useTrans()` hook implemented in Session 4. See D-033.
