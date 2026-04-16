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

### D-009 — File storage via Laravel Storage facade
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: S3 or object storage adds operational complexity early on.
- **Decision**: All file operations (business logo, collaborator avatars) use Laravel's `Storage` facade with the `local` driver in dev and the `public` disk in production. No direct filesystem calls anywhere in the codebase.
- **Consequences**: Migration between storage backends requires only a driver config change — no code changes. Production host changed from Hostpoint to Laravel Cloud per D-065; the `Storage` facade abstraction enables the swap to Laravel Cloud's managed object storage with no code change.

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

---

### D-065 — Postgres as the single database engine; Laravel Cloud as production host
- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: R-4 (booking race condition) required a correctness-grade
  solution for preventing overlapping bookings on the same provider. Postgres
  exposes `EXCLUDE USING GIST` exclusion constraints with the `btree_gist`
  extension — a declarative, transaction-scoped, atomic invariant that
  expresses exactly "no two rows overlap on (provider, effective_interval)
  where status in (pending, confirmed)". MariaDB / MySQL have no equivalent;
  every workaround is application-level lock management (row locks, advisory
  locks, triggers). SQLite has no `SELECT FOR UPDATE` semantics and only
  connection-level locking. Moving to Postgres in production required
  choosing a new host, since Hostpoint shared hosting does not offer
  Postgres.
- **Decision**: Postgres 16 is the only supported database engine across
  development, test, and production. SQLite is dropped as a dev target.
  MariaDB is dropped as a production target. Production hosting moves from
  Hostpoint to **Laravel Cloud** — first-party host with managed Postgres,
  managed queue workers, managed scheduler, and a zero-friction Laravel
  deploy story.
- **Consequences**:
  - The R-4B race guard uses `EXCLUDE USING GIST` with `btree_gist`, giving a
    declarative DB-level invariant against overlapping bookings.
  - `config/database.php` default is `pgsql`. `phpunit.xml` runs tests on a
    dedicated local Postgres database, not `:memory:`. Test runs are slightly
    slower than SQLite-in-memory but stay inside the "fast feedback" budget.
  - D-025 (enums stored as strings) remains in effect on its own merits —
    portable schema, no DB-enum ALTER churn. The SQLite rationale in D-025
    is obsolete but the decision outcome is unchanged.
  - D-009 (file storage via Laravel Storage facade) remains in effect. The
    production storage target changes from Hostpoint public-disk file
    storage to Laravel Cloud's object storage driver, configured at deploy
    time. No code change — the `Storage` facade abstraction is precisely
    what enables this swap.
  - `docs/DEPLOYMENT.md` is rewritten around Laravel Cloud. Hostpoint
    Supervisor / cron / SMTP sections are removed. Queue workers and
    scheduler are managed by the platform.
  - Every reference to SQLite, MariaDB, MySQL, and Hostpoint across docs,
    env files, SPEC, architecture summary, and CLAUDE guides is removed.
  - Laravel Cashier (not currently installed, noted aspirational in SPEC)
    supports Postgres natively whenever it is adopted.
- **Supersedes**: none. D-009 and D-025 remain accepted; their consequences
  are extended in this decision rather than replaced.
