# Decision Index

Use this file as the stable entrypoint for architectural decisions. Decision IDs remain unchanged; only the storage layout has changed.

## How to Use This Index

- Start here, then open only the topical decision files relevant to the current task.
- Keep existing decision IDs stable. Do not renumber or recycle IDs.
- Record new decisions in the appropriate topical file below.
- If a decision is superseded or a planning question is resolved historically, move it into `docs/decisions/DECISIONS-HISTORY.md` rather than deleting it.

## Topic Files

| File | Read for |
| --- | --- |
| `docs/decisions/DECISIONS-FOUNDATIONS.md` | platform choices, tenancy, routing, deployment-facing architectural defaults, cross-cutting app conventions |
| `docs/decisions/DECISIONS-AUTH.md` | auth model, roles, invitations, verification, slug ownership rules, customer/user boundaries |
| `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md` | booking flow, scheduling engine, availability, reminder logic, booking state handling |
| `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md` | onboarding, settings architecture, embed/share management, staff and provider lifecycle rules |
| `docs/decisions/DECISIONS-FRONTEND-UI.md` | React/Inertia frontend conventions, COSS UI, public booking UI, dashboard calendar UI decisions |
| `docs/decisions/DECISIONS-CALENDAR-INTEGRATIONS.md` | external calendar sync scope and future integration-specific decisions |
| `docs/decisions/DECISIONS-PAYMENTS.md` | customer-to-professional payment flow (Stripe Connect Express); distinct from the SaaS subscription billing in `DECISIONS-FOUNDATIONS.md` |
| `docs/decisions/DECISIONS-HISTORY.md` | superseded decisions and resolved planning questions kept for historical reference |

## Format

```md
### D-NNN — Title
- **Date**: YYYY-MM-DD
- **Status**: accepted | superseded | revoked
- **Context**: Why this decision was needed
- **Decision**: What was decided
- **Consequences**: What this means going forward
- **Supersedes**: D-NNN (if applicable)
```
