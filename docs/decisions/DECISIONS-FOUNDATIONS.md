# Foundations Decisions

This file contains live cross-cutting architectural decisions about platform defaults, routing, tenancy, and broad project conventions.

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
