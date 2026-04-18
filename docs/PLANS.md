# Plans Index

Stable entrypoint for every session plan in this project. Each row links to a file whose `status:` frontmatter is the authoritative state — this index is a discoverability table, not an authoritative state store.

## How to use this index

- Do not bulk-read `docs/plans/`. Use this index to find the plan relevant to the current task, then open only that file.
- Rows are grouped into three buckets: **In flight** (anything pre-terminal: `draft | planning | active | review`), **Shipped** (`shipped`), and **Superseded / Abandoned** (`superseded | abandoned`).
- When a plan's status changes, update **both** the frontmatter and the row here. A row may move between buckets — for instance, a plan transitioning from `draft` to `shipped` moves from `## In flight` to `## Shipped`.
- Plan files are **not** moved when they ship. The path stays stable; only the status flips.

## In flight

*(no plan is currently in flight — between roadmaps. `ROADMAP-PAYMENTS` is next up; Session 1 has not opened yet.)*

## Shipped

### Docs system

| File | Status | Scope | Updated |
|---|---|---|---|
| [PLAN-DOCS-RESTRUCTURE](plans/PLAN-DOCS-RESTRUCTURE.md) | shipped | Move docs to status-driven model with index files; dissolve docs/archive/; normalise frontmatter across all process docs | 2026-04-17 |

### MVP completion (MVPC-1..5)

| File | Status | Scope | Updated |
|---|---|---|---|
| [PLAN-MVPC-5-CALENDAR-INTERACTIONS](plans/PLAN-MVPC-5-CALENDAR-INTERACTIONS.md) | shipped | Advanced calendar interactions: drag, resize, click-to-create, hover preview | 2026-04-17 |
| [PLAN-MVPC-4-PROVIDER-SETTINGS](plans/PLAN-MVPC-4-PROVIDER-SETTINGS.md) | shipped | Provider self-service settings: Account + Availability opened to staff | 2026-04-17 |
| [PLAN-MVPC-3-CASHIER-BILLING](plans/PLAN-MVPC-3-CASHIER-BILLING.md) | shipped | Subscription billing via Cashier; single tier, indefinite trial, read-only gate | 2026-04-17 |
| [PLAN-MVPC-2-CALENDAR-SYNC](plans/PLAN-MVPC-2-CALENDAR-SYNC.md) | shipped | Bidirectional Google Calendar sync with push/pull/webhooks/pending-actions | 2026-04-17 |
| [PLAN-MVPC-1-OAUTH-FOUNDATION](plans/PLAN-MVPC-1-OAUTH-FOUNDATION.md) | shipped | Google OAuth Foundation via Socialite | 2026-04-17 |

### E2E foundation

| File | Status | Scope | Updated |
|---|---|---|---|
| [PLAN-E2E-0](plans/PLAN-E2E-0.md) | shipped | Pest 4 browser-testing infrastructure setup (BusinessSetup, AuthHelper, smoke test) | 2026-04-16 |

### Review-remediation (R-1..R-19, Rounds 1 and 2)

| File | Status | Scope | Updated |
|---|---|---|---|
| [PLAN-R-19-INVITE-AND-SCHEMA](plans/PLAN-R-19-INVITE-AND-SCHEMA.md) | shipped | Existing-user invitation flow + business_members schema drift | 2026-04-16 |
| [PLAN-R-18-SNAPPED-BUFFERS](plans/PLAN-R-18-SNAPPED-BUFFERS.md) | shipped | Slot generator reads snapped booking buffers (Round 2, Session B) | 2026-04-16 |
| [PLAN-R-17-BOOKABILITY](plans/PLAN-R-17-BOOKABILITY.md) | shipped | Bookability enforcement (Round 2, Session A) | 2026-04-16 |
| [PLAN-R-12-15-PRE-LAUNCH-POLISH](plans/PLAN-R-12-15-PRE-LAUNCH-POLISH.md) | shipped | R-12..R-15: pre-launch polish (welcome links, invite copy, misc UX hardening) | 2026-04-16 |
| [PLAN-R-10-11-OPS-CORRECTNESS](plans/PLAN-R-10-11-OPS-CORRECTNESS.md) | shipped | R-10 + R-11: reminder DST safety and auth-recovery rate limiting | 2026-04-16 |
| [PLAN-R-9-EMBED-PREFILTER](plans/PLAN-R-9-EMBED-PREFILTER.md) | shipped | Popup embed canonical service prefilter + modal robustness | 2026-04-16 |
| [PLAN-R-8-CALENDAR-MOBILE](plans/PLAN-R-8-CALENDAR-MOBILE.md) | shipped | Calendar mobile improvements (agenda view, view switcher) | 2026-04-16 |
| [PLAN-R-7-PROVIDER-CHOICE](plans/PLAN-R-7-PROVIDER-CHOICE.md) | shipped | Server-side enforcement of allow_provider_choice | 2026-04-16 |
| [PLAN-R-5-R-6-PROVIDER-LIFECYCLE-AND-CUSTOMER-TZ](plans/PLAN-R-5-R-6-PROVIDER-LIFECYCLE-AND-CUSTOMER-TZ.md) | shipped | Provider lifecycle coherence + customer-facing timezone | 2026-04-16 |
| [PLAN-R-4B-RACE-GUARD](plans/PLAN-R-4B-RACE-GUARD.md) | shipped | Booking overlap race guard via GIST exclusion constraint | 2026-04-16 |
| [PLAN-R-4A-DB-ENGINE](plans/PLAN-R-4A-DB-ENGINE.md) | shipped | Switch database engine to Postgres | 2026-04-16 |
| [PLAN-R-2-TENANT-CONTEXT](plans/PLAN-R-2-TENANT-CONTEXT.md) | shipped | Tenant context + cross-tenant validation | 2026-04-16 |
| [PLAN-R-1B-ADMIN-AS-PROVIDER](plans/PLAN-R-1B-ADMIN-AS-PROVIDER.md) | shipped | Admin as provider (admin can be their own first Provider row) | 2026-04-16 |
| [PLAN-R-1A-PROVIDER-MODEL](plans/PLAN-R-1A-PROVIDER-MODEL.md) | shipped | Provider model refactor (rename CollaboratorService to ProviderService) | 2026-04-16 |

### Original MVP (Sessions 2–11)

| File | Status | Scope | Updated |
|---|---|---|---|
| [PLAN-SESSION-11](plans/PLAN-SESSION-11.md) | shipped | Session 11: Calendar View | 2026-04-15 |
| [PLAN-SESSION-10](plans/PLAN-SESSION-10.md) | shipped | Session 10: Notifications (Email) | 2026-04-15 |
| [PLAN-SESSION-9](plans/PLAN-SESSION-9.md) | shipped | Session 9: Business Settings | 2026-04-15 |
| [PLAN-SESSION-8](plans/PLAN-SESSION-8.md) | shipped | Session 8: Business Dashboard | 2026-04-15 |
| [PLAN-SESSION-7](plans/PLAN-SESSION-7.md) | shipped | Session 7: Public Booking Flow | 2026-04-15 |
| [PLAN-SESSION-6](plans/PLAN-SESSION-6.md) | shipped | Session 6: Business Onboarding Wizard | 2026-04-15 |
| [PLAN-SESSION-5](plans/PLAN-SESSION-5.md) | shipped | Session 5: Authentication | 2026-04-15 |
| [PLAN-SESSION-4](plans/PLAN-SESSION-4.md) | shipped | Session 4: Frontend foundation (Inertia + React + COSS UI) | 2026-04-15 |
| [PLAN-SESSION-3](plans/PLAN-SESSION-3.md) | shipped | Session 3: Scheduling engine (TDD) | 2026-04-15 |
| [PLAN-SESSION-2](plans/PLAN-SESSION-2.md) | shipped | Session 2: Data Layer (models, migrations, factories, seeders) | 2026-04-15 |

### UI consolidation

| File | Status | Scope | Updated |
|---|---|---|---|
| [PLAN-UI-1](plans/PLAN-UI-1.md) | shipped | UI consolidation: booking flow to COSS UI primitives | 2026-04-15 |

## Superseded / Abandoned

*(none yet)*
