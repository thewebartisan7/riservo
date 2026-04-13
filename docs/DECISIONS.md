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

### P-001 — Assignment strategy: configurable or always first-available? (Resolved)
- **Date**: 2026-04-12
- **Status**: resolved — see D-028
- **Context**: SPEC §5.4 says automatic collaborator assignment strategy is "configurable (round-robin or first-available)." It is worth questioning whether this needs to be configurable at all in MVP.
- **Proposal**: Add `assignment_strategy` enum to Business (`first_available` | `round_robin`). Alternatively, always use `first_available` for MVP and defer configurability. The Session 3 agent should evaluate both options during planning and propose the right approach — including whether the complexity of round-robin is justified for MVP.
- **Resolution**: Both strategies implemented. Round-robin uses a "least-busy" approach (D-028).

---

### D-028 — Round-robin uses least-busy strategy
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: P-001 asked whether round-robin is needed for MVP and how it should work. True sequential round-robin requires state tracking (a `last_assigned_collaborator_id` column and update logic). A simpler approach achieves the same goal of fair workload distribution.
- **Decision**: The `round_robin` assignment strategy picks the collaborator with the fewest upcoming `confirmed`/`pending` bookings for the business. Among ties, the collaborator with the lowest ID wins (stable ordering). No extra state-tracking column needed.
- **Consequences**: Stateless and simple. Distribution is based on actual workload, not arbitrary rotation order. If a collaborator calls in sick and gets their bookings cancelled, they naturally get assigned more bookings when they return.

---

### D-029 — TimeWindow DTO for availability calculations
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: The scheduling engine manipulates time ranges extensively — intersecting business hours with collaborator rules, subtracting exceptions, checking booking overlaps. Using raw arrays of start/end times is error-prone.
- **Decision**: A `TimeWindow` value object (`app/DTOs/TimeWindow.php`) represents a time range with `CarbonImmutable` start/end. Static methods on the class provide collection operations: `intersect`, `subtract`, `merge`, `union`.
- **Consequences**: Type-safe time range operations. All availability calculations use `TimeWindow[]` arrays. Reusable across `AvailabilityService` and `SlotGeneratorService`.

---

### D-030 — Slot calculation works entirely in business timezone
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: Availability rules and business hours are stored as time-of-day strings (e.g., `09:00`). Bookings are stored as UTC datetimes (per D-005). The scheduling engine must reconcile these two representations.
- **Decision**: `AvailabilityService` and `SlotGeneratorService` operate entirely in the business's local timezone. Time-of-day strings from rules/hours are combined with the target date in the business timezone to produce `CarbonImmutable` instances. Booking queries convert the target date's boundaries to UTC for the WHERE clause.
- **Consequences**: Slot start times returned to callers are in business timezone. DST transitions are handled correctly by Carbon. Callers (future sessions) converting slot times to UTC for booking creation must use the business timezone as context.

---

### D-031 — Only pending and confirmed bookings block availability
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: SPEC §5.3 says slot calculation "subtracts existing confirmed/pending bookings." It must be explicit which booking statuses block availability, since getting this wrong either double-books or hides valid slots.
- **Decision**: Only bookings with status `pending` or `confirmed` are considered when checking for conflicts during slot generation. Bookings with status `cancelled`, `completed`, or `no_show` do not block any slots.
- **Consequences**: A cancelled booking immediately frees up its slot. A completed booking also frees its slot (relevant if a business manually marks a past booking as completed and the system checks historical dates for some reason).

---

### D-032 — UI Library: COSS UI replaces VenaUI
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: VenaUI (`vena-ui`) availability could not be confirmed. The project needed a reliable, production-tested UI component library for the React/Inertia frontend.
- **Decision**: Use COSS UI (copy-paste, Tailwind CSS) instead of VenaUI (vanilla CSS, npm package). COSS UI is production-tested (used at Cal.com and coss.com), ships 60+ components built on Base UI primitives, and has first-class AI agent skill support (`pnpm dlx skills add cosscom/coss`). Tailwind CSS v4 is adopted as a consequence of this choice.
- **Consequences**: Tailwind CSS is now part of the frontend stack. Components are copied into the project rather than installed via npm — no UI library version to pin or upgrade. The "No Tailwind CSS" rule is reversed. Session 4 must install the COSS UI skill and set up Tailwind v4 before building any UI.

---

### P-002 — React i18n approach (Resolved)
- **Date**: 2026-04-12
- **Status**: resolved — see D-033
- **Context**: SPEC §14 says all user-facing strings use `__()` in PHP and React. `__()` is PHP-only — the React side needs a JS mechanism. A common pattern with Laravel + Inertia is passing a JSON translation file via shared props.
- **Resolution**: Simple `useTrans()` hook implemented in Session 4. See D-033.

---

### D-033 — React i18n: useTrans() hook with Laravel JSON translations
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: P-002 asked how to handle translations in React. Options considered: react-i18next (full library) vs. a lightweight custom hook. The project uses Laravel's `__()` for PHP and needs an equivalent in React.
- **Decision**: `HandleInertiaRequests` middleware shares the current locale's JSON translation file (`lang/{locale}.json`) as an Inertia prop. A `useTrans()` hook reads translations from page props and returns a `t(key, replacements?)` function. `t()` mirrors `__()` behavior: falls back to the key itself, uses `:placeholder` replacement syntax. No i18n library dependency.
- **Consequences**: All React components use `const { t } = useTrans()` for translated strings. New translation keys are added to `lang/en.json`. Pre-launch, `lang/it.json`, `lang/de.json`, `lang/fr.json` provide translations per D-008.

---

### D-034 — COSS UI via shadcn CLI, components copied into project
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: COSS UI components need to be installed into the project. The CLI (`npx shadcn@latest`) handles dependency resolution, file placement, and transitive component imports.
- **Decision**: COSS UI was initialized with `npx shadcn@latest init @coss/style --template laravel`. This installed all 55 primitives into `resources/js/components/ui/`, the `cn()` utility into `resources/js/lib/utils.ts`, theme tokens into `resources/css/app.css`, and Inter font via `@fontsource-variable/inter`. The `components.json` config file maps aliases to the Laravel project structure.
- **Consequences**: Components are local files — no npm UI package to version. Future COSS updates can be applied by re-running `npx shadcn@latest add @coss/<component>` to overwrite individual files. The `geist` mono font package was removed (Next.js-only) — monospace font falls back to system defaults.

---

### D-035 — Same web guard for all user types, role-based middleware
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: D-014 established three roles (admin, collaborator, customer). The question was whether customers should use a separate auth guard with their own session cookie, or share the `web` guard with business users.
- **Decision**: All user types share the single `web` guard. A custom `EnsureUserHasRole` middleware checks the user's role (admin/collaborator via BusinessUser pivot, customer via Customer record). After login, users are redirected based on role: business users → `/dashboard`, customers → `/my-bookings`. A user can satisfy multiple roles simultaneously (e.g., a business admin who also has a Customer record).
- **Consequences**: Simpler implementation — one guard, one login page, one session. A user who is both a business admin and a customer gets redirected to the dashboard (business takes priority) but can navigate to `/my-bookings` manually.

---

### D-036 — business_invitations table for collaborator invites
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: Collaborators are invited by email. The invite must be stored until accepted. Two approaches were considered: (a) pre-create a User record with null password, or (b) use a dedicated invitations table.
- **Decision**: A `business_invitations` table stores pending invites (business_id, email, role, token, expires_at, accepted_at). No User record is created until the collaborator accepts the invite and sets their password. Invitations expire after 48 hours.
- **Consequences**: No orphan User records for unaccepted invites. The acceptance flow creates both the User and BusinessUser pivot atomically. Session 9 builds the admin UI for sending invites; Session 5 builds the backend and acceptance page.

---

### D-037 — Magic link one-time use via token column on users table
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: D-006 requires magic links to be one-time use. `URL::temporarySignedRoute()` handles expiry and tamper protection but does not enforce single use. A mechanism is needed to invalidate a link after it's been clicked.
- **Decision**: A `magic_link_token` nullable string column on the users table. When a magic link is requested, a random token is generated, stored on the user, and included as a parameter in the signed URL. On verification, the controller checks the token matches, then clears it. Requesting a new magic link overwrites the old token, invalidating previous links.
- **Consequences**: One active magic link per user at a time. Simple and stateless — no extra table needed. The signed URL handles expiry (15 min) and integrity; the token column handles one-time use.

---

### D-038 — Email verification required for business dashboard access
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: Business owners register with email + password. The question was whether email verification should be required before accessing the dashboard or just encouraged.
- **Decision**: Email verification is required. The `verified` middleware is applied to all dashboard routes. Unverified users are redirected to a "verify your email" page with a resend button. Collaborators who accept an invite are automatically marked as verified (they proved email ownership by clicking the invite link). Customers authenticated via magic link are also auto-verified. Customer routes (`/my-bookings`) do not require email verification.
- **Consequences**: Prevents fake signups from accessing business features. Adds a verification step to the registration flow but is standard SaaS practice.

---

### D-039 — Reserved slug blocklist for business registration
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: D-003 and D-013 established catch-all `/{slug}` routing. Business slugs must not collide with system routes. A blocklist is needed to prevent registration of slugs like `login`, `dashboard`, `api`, etc.
- **Decision**: A `SlugService` maintains a constant array of reserved slugs (all current and planned system route prefixes). Business registration generates a slug from the business name via `Str::slug()`, checks against the blocklist and existing slugs, and appends an incrementing number if taken.
- **Consequences**: The blocklist must be maintained as new routes are added. Slug generation is centralized in `SlugService` — used by registration (Session 5) and business settings (Session 9).

---

### D-040 — Onboarding state via onboarding_step + onboarding_completed_at
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: New business owners must complete a multi-step onboarding wizard before accessing the dashboard. The system needs to know (a) whether onboarding is complete, and (b) which step the user was on if they left mid-flow.
- **Decision**: Two fields on the `businesses` table: `onboarding_step` (unsignedTinyInteger, default 1) tracks the current/furthest step, and `onboarding_completed_at` (nullable timestamp) marks completion. An `EnsureOnboardingComplete` middleware redirects unboarded admins to `/onboarding/step/{step}`. Each step saves data immediately to the real models (Business, BusinessHour, Service, BusinessInvitation), so the wizard is naturally resumable with pre-populated data.
- **Consequences**: Each step is independently persistent — no temporary draft storage needed. The `onboarding_step` value only advances forward, never backwards, even if the user re-edits an earlier step.

---

### D-041 — Service pre-assignment via service_ids JSON on business_invitations
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: During onboarding step 4, the admin invites collaborators and can select which services they should be assigned to. However, collaborators don't exist as Users until they accept the invite (D-036). The `collaborator_service` pivot requires a `user_id`.
- **Decision**: A `service_ids` nullable JSON column on `business_invitations` stores an array of service IDs that should be auto-assigned when the collaborator accepts. `InvitationController@accept` reads this field and creates `collaborator_service` records for valid service IDs that still exist.
- **Consequences**: If a service is deleted between invitation and acceptance, the orphaned ID is silently ignored. Service assignment can also happen later in Session 9's collaborator management UI.

---

### D-042 — Logo uploaded immediately via separate endpoint
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: The onboarding wizard step 1 includes a logo upload. Two approaches: (a) upload the file with the form submission, or (b) upload immediately via a separate endpoint and store the path.
- **Decision**: Logo is uploaded immediately via `POST /onboarding/logo-upload`, which stores the file to `Storage::disk('public')` under `logos/`, updates `business.logo` with the relative path, and returns the path and public URL as JSON. The main profile form stores only the path string, not the file.
- **Consequences**: Instant preview feedback for the user. Old logos are deleted on replacement. The endpoint returns JSON, not an Inertia response — consumed via `fetch()` on the frontend (will use `useHttp` after upgrading to Inertia client v3).

---

### D-043 — Public booking uses single Inertia page with client-side steps
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: The public booking flow has 5-6 steps (service, collaborator, date/time, details, summary, confirmation). Using separate Inertia pages per step would cause server roundtrips and require server-side state management between steps.
- **Decision**: A single Inertia page at `booking/show` renders all steps. Client-side `useState` manages the current step and accumulated selections. Slot data is fetched via JSON API endpoints using Inertia v3's `useHttp` hook. The final booking creation is a `useHttp` POST returning JSON.
- **Consequences**: Single server-rendered page load. Step transitions are instant. Back-button behavior requires care. Page refresh returns to step 1 (with service pre-selected if URL has service slug).

---

### D-044 — Available dates API returns month-level availability map
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: The calendar must grey out days with no available slots. Checking each day individually from the frontend would cause 28-31 API calls per month.
- **Decision**: `GET /booking/{slug}/available-dates` accepts `service_id`, optional `collaborator_id`, and `month` (YYYY-MM). Returns `{ dates: { "2026-04-14": true, "2026-04-15": false, ... } }` for the entire month. The backend calls `getAvailableSlots()` for each day.
- **Consequences**: One request per month navigation. Simple for MVP. If performance becomes an issue, the service can be optimized to short-circuit after finding the first slot per day.

---

### D-045 — Honeypot field rejects with 422
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: Public booking forms need bot prevention. Options: CAPTCHA (friction), honeypot (invisible), rate limiting (already added separately).
- **Decision**: A hidden `website` field is included in the booking form. If it contains any value, the server returns 422 with a generic validation error. The field is positioned off-screen via CSS (not `display:none`, which bots detect).
- **Consequences**: Simple, zero-friction bot prevention. Does not stop sophisticated bots. Combined with rate limiting (5 bookings/min/IP) for layered protection.

---

### D-046 — `booking` added to reserved slug blocklist
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: Session 7 adds JSON API routes under `/booking/{slug}/...` prefix. If a business registers the slug "booking", the URL `/booking` would be ambiguous between the catch-all route and the API prefix.
- **Decision**: Add `'booking'` to the `SlugService::RESERVED_SLUGS` array.
- **Consequences**: No business can use "booking" as their slug. The blocklist now includes both `booking` and `bookings`.

---

### D-047 — Booking layout with minimal riservo branding
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: The public booking page needs its own layout distinct from guest-layout, authenticated-layout, and onboarding-layout. The question was how much riservo branding to show.
- **Decision**: New `booking-layout.tsx` with a small riservo logo in the header and "Powered by riservo.ch" link in the footer. Business name and logo are displayed prominently. No navigation links, no sidebar.
- **Consequences**: Clean, professional customer-facing page. Business branding is primary. Similar to Cal.com and Calendly's approach.

---

### D-048 — Upgrade Inertia client to v3 for useHttp hook
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: The Inertia server adapter is already v3 (`inertiajs/inertia-laravel@3`). The client (`@inertiajs/react`) was v2. Session 7 needs standalone HTTP requests for slot availability and booking creation APIs. The `useHttp` hook (v3 only) provides reactive state management, automatic validation error parsing, and a consistent HTTP layer.
- **Decision**: Upgrade `@inertiajs/react` from ^2 to ^3. Remove the `bootstrap.js` file (axios setup, no longer needed). All new AJAX calls use `useHttp`. Existing `fetch()` calls in onboarding pages are outside scope but noted for future migration.
- **Consequences**: Access to `useHttp` with reactive `processing`, `errors`, `wasSuccessful` state. Axios dependency removed. React 19 requirement already met.

---

### D-049 — Internal notes as single column on bookings
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: SPEC §7.3 requires "Add internal notes to a booking." The existing `notes` column stores customer-provided notes from the booking form. Staff internal notes are a separate concept. Two approaches: a single `internal_notes` text column, or a `booking_notes` table with author tracking.
- **Decision**: Add a nullable `internal_notes` text column to the `bookings` table. One note per booking, editable by any staff member with access.
- **Consequences**: Simple and sufficient for MVP. Can be upgraded to a multi-note table with author/timestamp tracking post-MVP if needed. No extra table or join required.

---

### D-050 — Status transitions encoded on BookingStatus enum
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: Dashboard booking management requires changing booking status (confirm, cancel, no-show, complete). Valid transitions must be enforced to prevent invalid state changes (e.g., cancelled → confirmed).
- **Decision**: `BookingStatus` enum gets an `allowedTransitions()` method returning valid target statuses: pending → confirmed/cancelled; confirmed → cancelled/completed/no_show; cancelled/completed/no_show → none (terminal). A `canTransitionTo()` convenience method wraps this.
- **Consequences**: Transition logic is centralized on the enum, not scattered across controllers. Both dashboard and any future status-change code use the same rules.

---

### D-051 — Manual bookings: multi-step dialog, source=manual, status=confirmed
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: Business staff create manual bookings from the dashboard for phone/walk-in customers. The UI pattern and default status need to be defined.
- **Decision**: Manual booking creation uses a multi-step dialog (customer → service → collaborator → date/time → confirm) that keeps the user in context on the dashboard. Manual bookings are created with `source: manual` and always `status: confirmed` — there is no pending state for staff-created bookings since the business is already aware of them.
- **Consequences**: The `confirmation_mode` setting does not affect manual bookings. The dialog reuses the same AvailabilityService for slot calculation. Placeholder notification (from Session 7) is sent to the customer.

---

### D-052 — Settings controllers under Dashboard\Settings namespace
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: Settings pages need their own controllers separate from onboarding, even though they share similar logic, because they operate under different middleware (admin-only, onboarded) and serve different UX flows (edit-in-place vs wizard).
- **Decision**: Create a `Dashboard\Settings` controller namespace with 7 controllers (Profile, BookingSettings, WorkingHours, BusinessException, Service, Collaborator, Embed). Validation rules are duplicated from onboarding form requests rather than shared via a service class, because the code is thin enough that extraction would be premature abstraction.
- **Consequences**: Some validation rule duplication between onboarding and settings form requests. If rules diverge in the future, this is actually desirable.

---

### D-053 — Collaborator is_active on pivot, not soft delete
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: Deactivating a collaborator should preserve their booking history and data but exclude them from scheduling. Soft-deleting the `User` would affect their ability to log in to other businesses. Soft-deleting the pivot row would lose the role information.
- **Decision**: Add `is_active` boolean (default true) to the `business_user` pivot. Deactivated collaborators are excluded from slot generation and booking but their data and history remain intact. They can be reactivated at any time.
- **Consequences**: `SlotGeneratorService` and public booking flow should filter for `is_active = true` on the pivot when listing available collaborators. The migration is additive and non-breaking.

---

### D-054 — Embed mode via query parameter, not separate route
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: The public booking page at `/{slug}` needs to be embeddable in iframes on third-party sites with a stripped-down UI (no nav/footer).
- **Decision**: `?embed=1` query parameter on the existing `/{slug}` route triggers embed mode. The same controller and page component are used; the React layout conditionally hides header and footer. A separate `public/embed.js` file provides a popup modal script for third-party sites — a vanilla JS file that is not bundled through Vite.
- **Consequences**: No route duplication. The booking page component receives an `embed` boolean prop. The popup JS script reads `data-slug` from its own script tag and opens an iframe overlay when any `[data-riservo-open]` element is clicked.

---

### D-055 — Settings sub-navigation via nested layout
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: Settings pages need consistent left-side navigation across 7 sections without repeating the nav markup in each page.
- **Decision**: `settings-layout.tsx` wraps `authenticated-layout.tsx` and renders a sub-navigation sidebar specific to settings. Each settings page uses `settings-layout` as its layout.
- **Consequences**: Two levels of layout nesting: `authenticated-layout` (sidebar + header) > `settings-layout` (sub-nav + content area). This follows the existing layout composition pattern.
