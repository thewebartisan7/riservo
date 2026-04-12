# Architectural Decisions

Decisions made during planning and development of riservo.ch.
Each decision has a stable ID that can be referenced in code comments (e.g., `// see D-004`).

## Format

```
### D-NNN — Title
- **Date**: YYYY-MM-DD
- **Status**: accepted | superseded | revoked
- **Context**: Why this decision was needed
- **Decision**: What was decided
- **Consequences**: What this means going forward
- **Supersedes**: D-NNN (if applicable)
```

---

### D-001 — No Laravel Fortify or Jetstream
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: Laravel Fortify and Jetstream add opinionated abstraction layers over authentication that complicate customisation and make the codebase harder to reason about for agents and developers alike.
- **Decision**: Authentication is implemented with custom Laravel controllers, middleware, and `URL::temporarySignedRoute()` for magic links. No Fortify, no Jetstream.
- **Consequences**: More code to write initially, but full control over every auth flow. No risk of package upgrades breaking customised auth behaviour.

---

### D-002 — No Laravel Zap for scheduling
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: Laravel Zap was evaluated for the scheduling engine but introduced friction during testing and had limitations on edge cases. The scheduling requirements (recurring rules + exceptions at two levels + buffers) are well-defined enough to implement cleanly in-house.
- **Decision**: Scheduling engine implemented as custom `AvailabilityService` and `SlotGeneratorService` using Laravel + Carbon.
- **Consequences**: More initial implementation work, but full control over slot logic, testability, and no third-party dependency risk.

---

### D-003 — Single domain path-based routing for MVP
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: Subdomain routing (`{slug}.riservo.ch`) requires wildcard SSL, more complex local dev setup, and potentially a multi-tenancy package.
- **Decision**: MVP uses catch-all path-based routing (`riservo.ch/{slug}`). Each Business has a unique slug set at registration. A reserved slug blocklist prevents conflicts with system routes.
- **Consequences**: Simpler deployment and dev setup. Migration to subdomain routing is possible post-MVP without data model changes (slug is the stable identifier throughout).
- **See also**: Reserved slug blocklist must be maintained as new system routes are added.

---

### D-004 — Separate `customers` table (not merged with `users`)
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: Customers (people who book appointments) may or may not have a user account. Storing them in the `users` table would require nullable passwords, complicate Laravel auth, and blur the line between "authenticated user" and "booking contact".
- **Decision**: `customers` table is separate. `customer.user_id` is a nullable FK to `users`. Guest customers have `user_id = null`. When a guest registers, their `User` is linked to the existing `Customer` via `user_id`.
- **Consequences**: A small join is needed when resolving a logged-in customer's booking history, but the model is clean and the auth system is not polluted with non-auth records.

---

### D-005 — Business timezone as single source of truth
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: Appointments are physical/local by default. Cross-timezone online appointments are rare and out of scope for MVP.
- **Decision**: Every `Business` has a `timezone` field (default: `Europe/Zurich`). All datetimes are stored in UTC. All slot calculation, display, and reminder scheduling uses the business's configured timezone.
- **Consequences**: Customers booking from a different timezone see the business's local times, which is correct for in-person appointments. Online appointment timezone support deferred to v2.

---

### D-006 — Magic links as default customer auth
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: Customers booking appointments do not need to remember yet another password. Reducing friction in the booking flow increases conversion.
- **Decision**: Customer authentication uses magic links by default (signed URL, one-time use, 15–30 min expiry via `URL::temporarySignedRoute()`). Password-based registration is available as an opt-in alternative. Business owners and collaborators use password auth with magic link as an alternative option.
- **Consequences**: Customers need access to their email to authenticate. Social login (Laravel Socialite) deferred to v2.

---

### D-007 — Laravel Cashier (Stripe) on the Business model
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: riservo.ch is a B2B SaaS — the billing subject is the Business, not individual users. Laravel Spark was evaluated but is too opinionated for a project with a custom data model and React/Inertia frontend.
- **Decision**: `laravel/cashier-stripe` installed and the `Billable` trait applied to the `Business` model. Billing portal is custom-built in React. No Laravel Spark.
- **Consequences**: Full control over billing UI and plan logic. Stripe Connect (for online customer payments) deferred to v2.

---

### D-008 — English as base language, `__()` from day one
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: riservo.ch targets the Swiss market (IT/DE/FR) but translating during feature development slows velocity. Retrofitting `__()` calls after the fact is painful and error-prone.
- **Decision**: All user-facing strings use the `__()` helper from the first line of code. English is the base language key. Translation files for Italian, German, and French are completed pre-launch.
- **Consequences**: Slightly more verbose string writing during development, but zero translation retrofit work.

---

### D-009 — File storage via Laravel Storage facade, Hostpoint for MVP
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: S3 or object storage adds operational complexity early on. The project starts on a Hostpoint shared server.
- **Decision**: All file operations (business logo, collaborator avatars) use Laravel's `Storage` facade with the `local` driver in dev and the `public` disk in production (Hostpoint). No direct filesystem calls anywhere in the codebase.
- **Consequences**: Migration to S3-compatible storage or Laravel Cloud requires only a driver config change — no code changes.

---

### D-010 — Google Calendar sync is last MVP feature
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: Google Calendar sync (bidirectional, with webhooks) is the most complex feature in the MVP. It depends on all other features being stable first.
- **Decision**: Google Calendar sync is Session 12 — the final session. It is built behind a `CalendarProvider` interface so future providers (Outlook, Apple via CalDAV) can be added without touching booking logic.
- **Consequences**: If time or budget requires cutting a feature, this is the first candidate to defer to v2 without impacting core functionality.

---

### D-011 — Blueprint for initial data layer generation
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: Manually writing migrations, models, and factories for 10+ models is repetitive and error-prone. Laravel Blueprint generates consistent, relationship-aware code from a single YAML file.
- **Decision**: Session 2 uses Laravel Blueprint (`draft.yaml`) to generate the initial data layer. The generated code is reviewed and adjusted before proceeding.
- **Consequences**: Faster, more consistent initial scaffolding. Agents in subsequent sessions should not re-run Blueprint — manual migrations are used for any schema changes after Session 2.

---

### D-012 — No multi-tenancy package for MVP
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: `tenancy-for-laravel` and similar packages add significant complexity to the development workflow, local setup, and deployment. Business data isolation can be achieved reliably with `business_id` scoping on all queries.
- **Decision**: No multi-tenancy package. All business-owned data is scoped via `business_id`. Global scopes or policies enforce this at the model level.
- **Consequences**: Developers and agents must be disciplined about scoping all queries. A security review pre-launch should verify no cross-business data leakage is possible.

---

### D-013 — Catch-all `/{slug}` routing (no `/b/` prefix)
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: SPEC.md had inconsistent URL prefixes (`/b/{slug}` in some places, `/{slug}` in others). A `/b/` prefix is safer but less clean for customers.
- **Decision**: Public booking pages use `riservo.ch/{slug}` — a catch-all route registered last. A reserved slug blocklist prevents conflicts with system routes.
- **Consequences**: The reserved slug blocklist must be comprehensive and maintained as new system routes are added.

---

### D-014 — Three roles in MVP: admin, collaborator, customer
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: SPEC listed `owner`, `admin`, and `collaborator` but never defined what distinguishes owner from admin. Maintaining a separate owner role adds complexity without MVP value. Registered customers also need an auth role.
- **Decision**: MVP has three roles: `admin` (full business access), `collaborator` (own calendar/bookings only), and `customer` (separate auth context, can only access own bookings). Admin and collaborator are business-scoped via the `BusinessUser` pivot. Customer auth is entirely separate from the business dashboard. A separate `owner` role with distinct permissions is deferred to v2.
- **Consequences**: Role middleware covers all three auth contexts. Customer sessions are isolated from business sessions.

---

### D-015 — Slot interval is per-service, not per-business
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: Different services benefit from different slot intervals (e.g., 15 min for a quick haircut, 60 min for a long consultation). A single business-level interval is too restrictive.
- **Decision**: `slot_interval_minutes` is a field on the `Service` model, not on `Business`.
- **Consequences**: The onboarding wizard and service management UI must include a slot interval field per service.

---

### D-016 — Cancellation window is customer-only
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: The cancellation window controls how close to the appointment time a cancellation is allowed. It was unclear whether this applies to the business as well.
- **Decision**: The cancellation window is enforced only on customer-side cancellations (public booking management). Admins can always cancel from the dashboard without restrictions.
- **Consequences**: Dashboard cancellation UI does not need to check the cancellation window.

---

### D-017 — Separate `business_hours` table for business-level working hours
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: Business-level working hours (§5.1) act as outer bounds for collaborator availability. The existing `AvailabilityRule` model belongs to collaborators and serves a different purpose (granular availability windows). Making it polymorphic would conflate two distinct concepts.
- **Decision**: A dedicated `business_hours` table belonging to Business with fields: `day_of_week`, `open_time`, `close_time`. Business hours are checked first in slot calculation (is the business open?), then collaborator rules are checked second (is this collaborator free?).
- **Consequences**: Slot calculation logic is explicit and straightforward — two clearly separated layers. The onboarding wizard (Session 6) and business settings (Session 9) manage business hours independently from collaborator schedules.

---

### D-018 — AvailabilityException uses date range (start_date + end_date)
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: SPEC §5.2 describes exceptions as overriding "a specific date range" (e.g., a week-long holiday). The original data model had a single `date` field, requiring one row per day for multi-day exceptions.
- **Decision**: `AvailabilityException` uses `start_date` + `end_date` instead of a single `date`. A single-day exception has `start_date == end_date`.
- **Consequences**: Multi-day closures or absences are a single record. Queries must check date range overlap instead of exact date match.

---

### D-019 — Booking reminder intervals stored as JSON on Business
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: SPEC §9 says booking reminders are "configurable: 24h / 1h before." The business needs to store which reminder intervals are active.
- **Decision**: `reminder_hours` field on Business as a JSON array (e.g., `[24, 1]`). Each value represents hours before the appointment when a reminder email is sent. Empty array means no reminders.
- **Consequences**: Flexible — businesses can configure any combination of reminder intervals. The scheduled reminder job (Session 10) reads this field to determine when to send.

---

### D-020 — Service price null means "on request"
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: SPEC §3 says service price "can be 0 / on request." Need to distinguish between free (price = 0) and price not disclosed (on request).
- **Decision**: `price` is a nullable decimal on Service. `0` = free, `null` = "on request", any positive value = the price. No extra boolean flag needed.
- **Consequences**: UI must handle three display states: free, on request, and a specific price. Null checks are needed wherever price is displayed or used in calculations.

---

### D-021 — AvailabilityException uses nullable FK, not polymorphic
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: AvailabilityExceptions can belong to either a Business (business-level) or a specific Collaborator (collaborator-level). Two approaches were considered: polymorphic relationship (`exceptionalable_type` + `exceptionalable_id`) vs. simple FK approach.
- **Decision**: `availability_exceptions` table has `business_id` (always set, for scoping per D-012) and nullable `collaborator_id`. When `collaborator_id` is null, it's a business-level exception. When set, it's collaborator-level.
- **Consequences**: Simpler queries, no polymorphic joins. `business_id` is always available for scoping.

---

### D-022 — Avatar field on User model, not BusinessUser pivot
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: SPEC §13 lists avatar as being "on BusinessUser pivot or User profile." Multi-business collaborators needing different avatars per business is unlikely for MVP.
- **Decision**: `avatar` is a nullable string column on the `users` table. One avatar per person across all businesses.
- **Consequences**: Simpler model, no pivot complexity. If per-business avatars are needed post-MVP, a migration can move the field.

---

### D-023 — Assignment strategy column added in Session 2
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: P-001 proposed adding `assignment_strategy` to Business. The Business migration is created in Session 2, and adding the column now avoids an extra migration in Session 3.
- **Decision**: `assignment_strategy` string column on `businesses` table with default `first_available`. Session 3 implements the actual assignment logic.
- **Consequences**: Session 3 agent can focus on the scheduling engine without needing a migration. P-001 is partially resolved — the field exists, but the Session 3 agent still decides whether to implement round-robin or keep first-available only.

---

### D-024 — day_of_week uses ISO 8601 numbering
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: Multiple tables store `day_of_week` values. Different systems use different numbering: Carbon's `dayOfWeek` (0=Sunday), `dayOfWeekIso` (1=Monday), JavaScript's `getDay()` (0=Sunday).
- **Decision**: All `day_of_week` columns use ISO 8601 numbering: 1=Monday through 7=Sunday. This matches Carbon's `dayOfWeekIso` property.
- **Consequences**: When checking availability, use `$date->dayOfWeekIso` (not `$date->dayOfWeek`). A `DayOfWeek` int-backed enum enforces valid values.

---

### D-025 — Enum fields stored as strings for SQLite compatibility
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: MySQL/MariaDB native ENUM types are not supported by SQLite. The project uses SQLite for development.
- **Decision**: All enum-backed fields (status, source, role, type, etc.) are stored as `string` columns. PHP string-backed enums with Eloquent casts enforce valid values at the application layer.
- **Consequences**: Cross-database compatible. No migration issues between SQLite (dev) and MariaDB (prod). Validation at the database level is sacrificed for portability.

---

### D-026 — Skip Blueprint, use artisan make commands
- **Date**: 2026-04-12
- **Status**: accepted
- **Supersedes**: D-011
- **Context**: D-011 proposed using Laravel Blueprint for initial data layer scaffolding. However, Blueprint generates old-convention code (`$fillable` arrays) incompatible with Laravel 13's `#[Fillable]` attributes, cannot express custom FK names like `collaborator_id`, and requires extensive manual adjustment.
- **Decision**: Session 2 uses `php artisan make:model`, `make:migration`, and `make:factory` instead of Blueprint. The `draft.yaml` task from the roadmap is replaced by manually written, convention-following code.
- **Consequences**: No Blueprint dependency. All generated code follows Laravel 13 conventions from the start. Slightly more upfront work, but no post-generation cleanup.

---

### D-027 — FK naming: collaborator_id for user-as-collaborator references
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: Several tables reference the `users` table to represent the collaborator performing a service. Using `user_id` everywhere is ambiguous — `bookings.user_id` could mean the customer's user, the business owner, or the collaborator.
- **Decision**: Tables where the FK represents a collaborator use `collaborator_id` (pointing to `users.id`): `bookings`, `availability_rules`, `availability_exceptions`, `collaborator_service`. Tables where the FK represents a generic user use `user_id`: `customers`, `calendar_integrations`.
- **Consequences**: Self-documenting schema. Relationship methods are named `collaborator()` where appropriate. Requires explicit `->constrained('users')` in migrations since Laravel can't infer the table from `collaborator_id`.

---

### P-001 — Assignment strategy: configurable or always first-available? (Open Proposal)
- **Date**: 2026-04-12
- **Status**: open — for Session 3 agent to evaluate
- **Context**: SPEC §5.4 says automatic collaborator assignment strategy is "configurable (round-robin or first-available)." It is worth questioning whether this needs to be configurable at all in MVP.
- **Proposal**: Add `assignment_strategy` enum to Business (`first_available` | `round_robin`). Alternatively, always use `first_available` for MVP and defer configurability. The Session 3 agent should evaluate both options during planning and propose the right approach — including whether the complexity of round-robin is justified for MVP.

---

### P-002 — React i18n approach (Open Proposal)
- **Date**: 2026-04-12
- **Status**: open — for Session 4 agent to investigate
- **Context**: SPEC §14 says all user-facing strings use `__()` in PHP and React. `__()` is PHP-only — the React side needs a JS mechanism. A common pattern with Laravel + Inertia is passing a JSON translation file via shared props.
- **Proposal**: The Session 4 agent should research the best practice for translations with Laravel 13 + Inertia + React and decide the approach during planning. The decision should cover: how translations are loaded, how they're accessed in components, and whether a JS i18n library is needed or if a simple helper function suffices.
