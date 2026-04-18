---
name: PLAN-R-4A-DB-ENGINE
description: "R-4A: switch database engine to Postgres"
type: plan
status: shipped
created: 2026-04-16
updated: 2026-04-16
---

# PLAN-R-4A — Switch database engine to Postgres

**Session**: R-4A — Database engine swap (prerequisite for R-4B)
**Source**: `docs/reviews/ROADMAP-REVIEW.md` §R-4
**Status**: proposed (plan-only)
**Date**: 2026-04-16

---

## 1. Context

The R-4 remediation is "close the booking double-booking race". The roadmap text originally required the fix to work on both SQLite (dev) and MariaDB (prod). The developer has since lifted that constraint for this session and is willing to change database engines — locally and in production — if a different engine offers a materially better solution for the race problem.

R-4B will implement the actual concurrency guard. This plan (R-4A) is the **prerequisite infrastructure swap**: move the application from `SQLite (dev) / MariaDB (prod)` to **Postgres everywhere** so R-4B can rely on Postgres-only features (`EXCLUDE USING GIST` with `btree_gist`).

### Why a separate plan

Splitting the engine swap from the race guard buys real clarity:

- R-4A is pure infrastructure — no product behavior change. If anything breaks after R-4A, it is engine-related (enum cast, timestamp handling, seeder, test runner), not concurrency-related.
- R-4B is a focused correctness change on a clean Postgres baseline — its blast radius is contained to bookings.
- Each session ends with `php artisan test --compact` green and a clean commit.

One combined plan would mix signal: a failing test could be a Postgres quirk or a guard bug, and localising the cause would be harder.

### What R-4B will need from this plan

- A running Postgres connection locally and in test.
- The `btree_gist` extension available (installed by an R-4B migration, but the engine has to be Postgres).
- Every existing migration, seeder, model cast, and query behaving the same on Postgres as it does today on SQLite / MariaDB.

---

## 2. Database engine evaluation

### The three candidates

**SQLite**
- Connection-level locking only; no `SELECT FOR UPDATE`.
- Not viable as a production target. User confirmed it should be dropped from dev as well.
- **Rejected.**

**MariaDB / MySQL**
- Currently the production target per `docs/DEPLOYMENT.md`.
- No native exclude-range constraint. A plain unique on `(provider_id, starts_at, ends_at)` catches only identical rows, not overlapping intervals.
- Available workarounds for the race guard:
  - `SELECT … FOR UPDATE` on existing overlapping bookings — does not close the race when the set is empty (the classic "both requests see no conflict, both insert" window).
  - `SELECT … FOR UPDATE` on the `providers` row to serialize writes per provider — works, but conflates data integrity with application-level lock management.
  - `GET_LOCK('booking-provider-{id}', 5)` named advisory lock — works, session-scoped, released on commit or disconnect; imperative, relies on every writer remembering the lock.
  - Triggers + generated columns to simulate an exclusion constraint — messy, per-row, composes poorly.
- All MariaDB options are imperative application-level orchestration. No declarative invariant at the schema level.

**Postgres**
- Native `tsrange` / `tstzrange` types and `EXCLUDE USING GIST` exclusion constraints. This is the textbook solution for "no two rows overlap on (key, interval) where status is in a set".
- Declarative, transaction-scoped, atomic across concurrent writers without explicit locks.
- The constraint can be partial (`WHERE status IN ('pending','confirmed')`), so cancelled / completed / no-show rows do not participate — matches D-031 exactly.
- `btree_gist` is a standard contrib extension; enabled with `CREATE EXTENSION IF NOT EXISTS btree_gist` in a migration.
- First-class Laravel support (`pgsql` driver already present in `config/database.php`).
- The race guard becomes a schema property: correct by construction, impossible to forget at a new write site.

### Decision — Postgres

Postgres is materially better for the R-4 problem:

1. **Correctness quality**: declarative schema invariant beats imperative application locks. A schema rule survives refactors that would otherwise silently drop a `lockForUpdate()` from a controller.
2. **Race-window elimination**: the exclusion constraint closes the "both check empty, both insert" window that no MariaDB read-lock can close.
3. **No lock contention**: busy providers do not serialize on an advisory lock — the constraint is per-row and short-lived in the index.
4. **Buffer semantics**: works cleanly with either stored or generated columns for the buffered effective interval (detail deferred to R-4B).

Cost:

- Production host switch to **Laravel Cloud** (developer-confirmed 2026-04-16). Hostpoint shared hosting does not offer Postgres, and Laravel Cloud is the first-party host with native Postgres, tight Laravel integration, and a deployment story that matches the rest of the stack.
- No effect on Cashier: `laravel/cashier-stripe` is **not installed** (verified — composer.json has no `laravel/cashier`, `vendor/laravel/` contains no cashier directory). The `docs/SPEC.md` §4 "Billing" note is aspirational, not current. When Cashier is adopted later it supports Postgres out of the box.
- Enums-as-strings (D-025) keep working. The rationale in D-025 (SQLite compatibility) becomes obsolete, but the decision itself (string-stored enums with PHP casts) stays on its own merits: portable schema, no DB-enum ALTER churn. D-065 will note this; D-025 is not superseded.
- Local dev: Postgres is already installed via DBngin at `127.0.0.1:5432`, empty `riservo-ch` database created. No install work required.

### Rejected alternatives summary

| Engine | Why rejected |
| --- | --- |
| SQLite (dev) | No `SELECT FOR UPDATE`; connection-level locking; unsuitable as the basis for a shared-DB race guard. |
| MariaDB (dev + prod) | No declarative exclude-range constraint. All solutions are imperative application locks — worse correctness quality, more surface area, more bug potential. |

### Hosting implication — Laravel Cloud (confirmed 2026-04-16)

Switching to Postgres in production requires moving off Hostpoint shared hosting. The developer has confirmed **Laravel Cloud** as the new production host. Laravel Cloud was selected over alternatives (Forge + Hetzner / DigitalOcean / Exoscale, Fly.io, Render, Supabase + separate app host) because it is the first-party host: managed Postgres is native, queue workers / scheduler / build / deploy are all bundled, and there is no impedance mismatch between the app and the runtime.

R-4A's documentation updates therefore:

- Rewrite `docs/DEPLOYMENT.md` around Laravel Cloud (Postgres managed, queue workers managed, scheduler managed, Hostpoint Supervisor / SMTP / cron instructions removed).
- Remove every remaining reference to Hostpoint, SQLite, MySQL, and MariaDB across docs, env files, SPEC, and architecture summary. Nothing in the codebase should still imply those engines or that host.
- Keep the SMTP / mail configuration as a generic Laravel mail block; if Laravel Cloud provides a mail integration (Postmark / Mailgun / SES), document that integration is TBD and configured at deploy time — not in code.

---

## 3. Goal and scope

### Goal

Make Postgres the single database engine for local dev, test, and production. Leave every existing behaviour unchanged. Prepare the schema surface so R-4B can add a Postgres-specific exclusion constraint without additional engine work.

### In scope

- `.env.example` — default connection flipped to Postgres; **the commented-out SQLite and MariaDB/MySQL blocks are removed entirely** — we are not supporting those engines anywhere.
- `config/database.php` — change `default` fallback from `sqlite` to `pgsql`. Leave the `sqlite`, `mysql`, `mariadb`, and `sqlsrv` connection arrays in place (framework defaults — harmless, but we do not encourage their use in our docs).
- `phpunit.xml` — replace `DB_CONNECTION=sqlite` + `DB_DATABASE=:memory:` with `DB_CONNECTION=pgsql` + a test database name; remove `DB_URL=""` if it interferes.
- `docs/DEPLOYMENT.md` — full rewrite around **Laravel Cloud**. Remove Hostpoint-specific sections (Supervisor, cron on shared host, Hostpoint SMTP). Postgres is the managed DB. Queue / scheduler are managed by Laravel Cloud.
- `docs/SPEC.md` — §4 "Tech Stack" database line updated to "Postgres 16 (all environments, managed on Laravel Cloud in production)". Any other SQLite / MariaDB / Hostpoint mentions scrubbed.
- `docs/ARCHITECTURE-SUMMARY.md` — "Platform" section updated; SQLite / MariaDB removed.
- `docs/CLAUDE.md` (or whichever file carries the scoped agent guide — `.claude/CLAUDE.md` / project CLAUDE.md) — any mention of SQLite, MariaDB, or Hostpoint scrubbed. The file currently says "SQLite (local dev), MariaDB (production)"; update to Postgres.
- `docs/decisions/DECISIONS-FOUNDATIONS.md` — add D-065 (this decision). D-025's wording is left intact (accepted historical rationale); D-065's consequences note that D-025 remains in effect on its own merits.
- `docs/decisions/DECISIONS-FOUNDATIONS.md` — also scan D-009 (Hostpoint file storage) and update: file storage facade usage is unchanged, but the storage driver will use Laravel Cloud's object storage in production. Record the production storage detail inside D-065's consequences; do not supersede D-009 — its architectural outcome (always use `Storage` facade) is still correct.
- Search the **entire repo** for `hostpoint`, `Hostpoint`, `HOSTPOINT`, `sqlite`, `SQLite`, `mariadb`, `MariaDB`, `MARIADB`, `mysql`, `MySQL` (case-insensitive grep) and remove or rewrite each match. Expected hit sites:
  - `.env.example` (mail comment block, DB block).
  - `docs/DEPLOYMENT.md`.
  - `docs/SPEC.md`.
  - `docs/ARCHITECTURE-SUMMARY.md`.
  - `docs/CLAUDE.md` / `.claude/CLAUDE.md` / `CLAUDE.md` (project root).
  - `docs/decisions/DECISIONS-FOUNDATIONS.md` (D-009 mentions Hostpoint; D-025 mentions SQLite).
  - `docs/README.md` if it references SQLite or MariaDB anywhere.
  - Any HANDOFF / ROADMAP files.
  - `docs/plans/PLAN-SESSION-12.md` is archived context and may still mention old defaults — leave it alone unless a reference would mislead a future reader; the plan is historical.
  - `config/mail.php` (Hostpoint SMTP defaults not likely there, but check).
- Smoke-check every migration on Postgres. They are all Laravel schema-builder-driven and should run clean, but we verify:
  - `unsignedInteger` → `integer` (Postgres has no unsigned type; values still in range, and PHP won't send negatives).
  - `dateTime()` → `timestamp(0) without time zone` — SlotGeneratorService and other consumers already read / write UTC strings explicitly, so tz-less is fine. Existing casts (`'datetime'`) handle this through Eloquent.
  - `json` → `json` (Postgres native) — `reminder_hours` on businesses and `service_ids` on invitations serialise identically.
  - `string` → `varchar` — no difference.
  - `foreignId` chains — work identically.
- Smoke-check seeders (`DatabaseSeeder`, `BusinessSeeder`) on Postgres for any MySQL-specific syntax. Reading `BusinessSeeder`, nothing looks engine-specific (no raw SQL, no MySQL functions, no ONLY_FULL_GROUP_BY quirks). The `Carbon::now()->next(Carbon::MONDAY)` calls and `Str::uuid()` are engine-agnostic.
- `tests/Pest.php` (`RefreshDatabase`) — continues to work on Postgres. Tests will run slower than `:memory:` but not prohibitively. Alternatives (DatabaseTransactions trait per class, or a persisted test DB re-used across runs) are left for a later performance session if needed.
- A brief smoke test that `php artisan migrate:fresh --seed` succeeds against the new engine.

### Out of scope

- Any R-4B work (the race guard, buffer snapshots, exclusion constraint, concurrency test).
- Choosing a production host.
- Migrating any production data (pre-launch, no data to preserve).
- Parallel test execution tuning (`paratest`, per-worker databases).
- Changing D-025 (enums as strings) — the decision stands; D-065's "consequences" will reference it.
- Adopting Laravel Cashier.
- Any application behaviour change.

---

## 4. Chosen approach

**Postgres 16 as the single database engine for all environments, with Laravel Cloud as the production host.** `.env.example` default connection set to `pgsql` against the developer's existing DBngin-provisioned `riservo-ch` database. `DEPLOYMENT.md` is rewritten around Laravel Cloud. All Hostpoint / MariaDB / SQLite mentions across docs and env files are removed.

A dedicated testing database (`riservo_ch_testing`) is created locally. `phpunit.xml` uses it. `RefreshDatabase` drops / recreates the schema per test run.

All twenty-seven existing migrations run unchanged on Postgres (each uses only Laravel schema-builder DSL). Seeders and factories are engine-agnostic. Enum casts (D-025) continue to store as `varchar`.

---

## 5. New architectural decision — D-065

**Proposed wording (to be written to `docs/decisions/DECISIONS-FOUNDATIONS.md` during implementation, not now):**

```md
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
```

(No other decisions are added in R-4A. D-066 lives in R-4B.)

---

## 6. Implementation order

Each step is its own commit, and `php artisan test --compact` stays green at every commit boundary.

### Step 1 — Create local test database

Local-only setup, no files touched. DBngin UI or CLI:

```
psql -h 127.0.0.1 -U postgres -c 'CREATE DATABASE "riservo_ch_testing";'
```

Developer runs this once. Verified by a `psql \l` check. Not committed.

### Step 2 — Flip the default DB connection

Files:

- `.env.example`: replace the current SQLite default + commented MariaDB block + commented Postgres block with a single uncommented `pgsql` block for the local DBngin database. Remove the other blocks entirely — no commented alternatives kept.
- `config/database.php`: change `'default' => env('DB_CONNECTION', 'sqlite')` → `'default' => env('DB_CONNECTION', 'pgsql')`.
- Developer manually updates their local `.env` to match (not committed).

Run locally:
```
php artisan config:clear
php artisan migrate:fresh --seed
```
Expect: all migrations and the `BusinessSeeder` succeed. Spot-check by running the dev server and visiting the dashboard and a public booking page.

Commit: "Switch default DB connection to Postgres".

### Step 3 — Flip tests to Postgres

Files:

- `phpunit.xml`:
  - `<env name="DB_CONNECTION" value="pgsql"/>`
  - `<env name="DB_DATABASE" value="riservo_ch_testing"/>`
  - `<env name="DB_HOST" value="127.0.0.1"/>`
  - `<env name="DB_PORT" value="5432"/>`
  - `<env name="DB_USERNAME" value="postgres"/>`
  - `<env name="DB_PASSWORD" value=""/>`
  - Remove `DB_URL` line (irrelevant).

Run: `php artisan test --compact`. Expect all 433 tests green on Postgres.

Likely rough edges to verify (resolve inline, not deferred):

- **Enum string comparisons**: Postgres is case-sensitive on string equality. Our enum values (`pending`, `confirmed`, etc.) are lowercase literals; no change needed.
- **JSON casts**: `reminder_hours` (array) on businesses, `service_ids` (array) on invitations. Postgres stores as `json`. `json_encode`/`json_decode` roundtrip is identical.
- **Boolean casts**: Postgres has native `BOOLEAN`. Laravel's `boolean` cast handles it. Test: `allow_provider_choice`, `is_active` on services, `is_active` flags in factories.
- **Timestamp precision**: Postgres `timestamp(0)` truncates sub-second. Any test that compares `Carbon::now()` exactly to a stored value should already use whole-second resolution; inspect any `->eq()` comparisons that might trip. The SlotGenerator tests already parse back with `createFromFormat('Y-m-d H:i:s', ..., 'UTC')` which yields whole seconds.
- **Soft deletes on `providers`**: Postgres handles `SoftDeletes` identically. The `unique(['business_id', 'user_id', 'deleted_at'])` compound index is valid — NULL values are distinct in both MySQL and Postgres uniqueness semantics, which is what we want (a soft-deleted provider and an active provider for the same user don't conflict).
- **`auto_increment` → `SERIAL` / `IDENTITY`**: Laravel abstracts this. `id()` becomes `bigserial` on Postgres.

If any test fails, the cause is almost certainly one of the above; fix in this step before moving on.

Commit: "Run tests on Postgres".

### Step 4 — Documentation sweep (SQLite / MariaDB / MySQL / Hostpoint → Postgres / Laravel Cloud)

This step is a full-repo scrub plus the structured rewrites below. Run a case-insensitive grep for `sqlite|mariadb|mysql|hostpoint` across `docs/`, `.env.example`, `CLAUDE.md`, `.claude/CLAUDE.md`, `config/`, and top-level README / AGENTS files. Every hit gets rewritten or removed.

Files:

- `docs/SPEC.md` §4 "Tech Stack": replace `Database: MariaDB (production), SQLite (local development)` with `Database: Postgres 16 (all environments, managed on Laravel Cloud in production)`. Scan the rest of the file for any lingering SQLite / MariaDB / Hostpoint mention.
- `docs/ARCHITECTURE-SUMMARY.md` "Platform" section: replace the DB line with Postgres 16. Remove MariaDB / SQLite.
- `docs/DEPLOYMENT.md`: **full rewrite** around Laravel Cloud. Drop Supervisor sections, Hostpoint SMTP, shared-host cron. Document Laravel Cloud's managed Postgres, managed queue workers, managed scheduler, deploy flow (git-push + Laravel Cloud pipeline), environment-variable surface (`APP_URL`, `APP_KEY`, Google OAuth future vars, mail provider TBD). Keep the local dev section concise: DBngin-backed Postgres, `npm run dev`, Herd-hosted `.test` URL.
- `docs/README.md`: scan for SQLite / MariaDB / Hostpoint references; rewrite or remove.
- `CLAUDE.md` and `.claude/CLAUDE.md` (both carry the scoped agent guide): the current "Database: SQLite (local dev), MariaDB (production)" line becomes "Database: Postgres 16 (all environments, managed on Laravel Cloud in production)". Scan for every other stale engine mention.
- `docs/decisions/DECISIONS-FOUNDATIONS.md`:
  - D-009 — Hostpoint mention is now stale. Do not supersede — edit the **Consequences** section to add: "Production host changed from Hostpoint to Laravel Cloud per D-065; the `Storage` facade abstraction enables the swap with no code change." D-009's decision (use `Storage` facade, not direct filesystem calls) is still correct.
  - D-025 — leave the wording alone (historical rationale). D-065's consequences capture the updated status.
  - Append D-065 using the wording in §5 above.
- Any HANDOFF / ROADMAP files — scrub stale engine mentions; do not touch anything else in them beyond engine / host references (that is for R-4B's HANDOFF rewrite in Step 5 or R-4B Step 7).

Sanity check: after this step, a repo-wide case-insensitive grep for `sqlite`, `mariadb`, `mysql`, `hostpoint` should return **only**:
- `config/database.php` (framework-default connection arrays — keep; harmless).
- `docs/archive/plans/` (archived historical plans — leave alone).
- `docs/decisions/DECISIONS-HISTORY.md` if a superseded decision mentions them — leave alone.
- Any vendor / node_modules hits — ignore.

Commit: "D-065 docs — Postgres as single engine, Laravel Cloud as production host".

### Step 5 — HANDOFF rewrite

Rewrite `docs/HANDOFF.md` to describe the new state: engine swap complete, R-4B is the next step, host selection is a separate operational task.

Commit: "HANDOFF: R-4A complete".

### Step 6 — Session close

- `vendor/bin/pint --dirty --format agent` (almost certainly no PHP changes, but run for hygiene).
- `npm run build` (verify frontend still compiles; no frontend changes expected).
- `php artisan test --compact` one final full run.
- Move this plan from `docs/plans/` to `docs/archive/plans/`.
- Update the R-4 checkbox in the roadmap (or `docs/ROADMAP.md` equivalent) to note R-4A complete, R-4B pending.

---

## 7. Files to create / modify / delete

**Modify:**

- `.env.example` — SQLite / MariaDB blocks removed, `pgsql` block made the default.
- `config/database.php` — `default` fallback → `pgsql`.
- `phpunit.xml` — Postgres test connection.
- `docs/SPEC.md` — Postgres 16 in Tech Stack; scrub stale mentions elsewhere.
- `docs/ARCHITECTURE-SUMMARY.md` — Postgres in Platform; scrub stale mentions.
- `docs/DEPLOYMENT.md` — **full rewrite** around Laravel Cloud.
- `docs/README.md` — scrub stale mentions if any.
- `CLAUDE.md` and `.claude/CLAUDE.md` — Postgres everywhere, Laravel Cloud in production; scrub stale mentions.
- `docs/decisions/DECISIONS-FOUNDATIONS.md` — append D-065; extend D-009's Consequences with the Laravel Cloud storage note.
- Any other docs that mention SQLite / MariaDB / MySQL / Hostpoint — grep-sweep and rewrite.
- `docs/HANDOFF.md` (full rewrite at session close).
- `docs/ROADMAP.md` (check off R-4A).

**Create:** none.

**Delete:** none. (The removal of commented alternative DB blocks in `.env.example` is an in-file deletion, not a file deletion.)

**Rename:** none.

**Migrations:** none in R-4A. All schema-level work for R-4 happens in R-4B.

---

## 8. Testing plan

No new tests in R-4A. The engine swap is proved by:

1. **Full existing suite green on Postgres**: `php artisan test --compact` against the new `pgsql` test connection. This is the real engine-compatibility proof — 433 tests across availability, booking creation, notifications, tenant context, cross-tenant validation, seeders, and commands. If any of these misbehave on Postgres, the failure surfaces here.
2. **`migrate:fresh --seed` smoke**: run against the dev database. `BusinessSeeder` creates ~100+ rows across providers, services, availability, customers, bookings. Any Postgres-specific migration or seeder gap surfaces immediately.
3. **Manual dashboard + public booking page check**: verify at least one full booking flow against the Postgres-backed local app.

No new Pest files, no new DB-level tests. R-4B adds those.

---

## 9. Verification steps

Run in order at session close:

```
php artisan migrate:fresh --seed
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm run build
```

Expected outcomes:

- `migrate:fresh --seed`: every migration applies, `BusinessSeeder` runs, no errors.
- `test --compact`: 433 tests passed (matching current baseline).
- `pint`: no changes required, exit 0.
- `npm run build`: emits the same bundle as before; nothing frontend changed.

---

## 10. Risks and mitigations

**Postgres quirks surfacing in existing tests.** Likely culprits: timestamp precision, enum comparisons, JSON roundtrips. Mitigation: every quirk is discovered in Step 3 by running the suite on Postgres; each failure is fixed inline before the step's commit. If a quirk cannot be fixed inline without behaviour change, abort R-4A and raise the specific issue to the developer.

**Test suite slows down.** `:memory:` SQLite is the fastest test engine available to Laravel. Postgres on local disk adds per-test migration overhead under `RefreshDatabase`. Mitigation: accept the slowdown for R-4A; if it is painful, a future session introduces `DatabaseTransactions` or `--parallel` with per-worker databases. Measure in Step 3 and note the before/after in HANDOFF.

**Host selection — Laravel Cloud.** Confirmed 2026-04-16. R-4A does not itself ship to production — R-4B is the functional change — but DEPLOYMENT.md is rewritten to reflect Laravel Cloud so the next deploy after R-4B lands has a correct target. The actual Laravel Cloud account setup, build pipeline wiring, managed-Postgres provisioning, DNS cut-over, and env-var configuration on the platform are operational tasks owned outside this plan; R-4A's deliverable is documentation + code that is ready for them.

**D-025 (enums as strings) rationale obsolete but decision retained.** Someone reading D-025 later may expect enums to be stored as native Postgres enums because the SQLite rationale no longer applies. Mitigation: D-065's consequences explicitly note that D-025 remains accepted on its own merits (portable schema, no DB-enum ALTER churn). No decision churn.

**Laravel Cashier claim in SPEC is aspirational, not current.** The R-4 brief said Cashier is installed; it is not (verified). Mitigation: D-065's note records the actual state. Adopting Cashier later is a separate session and works on Postgres.

**Developer local `.env` drift.** Each developer keeps their own `.env`. When they pull R-4A, their connection still points at SQLite and things break. Mitigation: HANDOFF includes an explicit "update your local `.env` to match `.env.example`" note. A developer who misses the note sees an immediate, loud, clear migration failure — no silent bad state.

**R-4B prerequisite.** R-4A on its own produces no user-visible benefit. Its value is unlocking R-4B. Mitigation: R-4B is scoped and queued to follow immediately; this plan explicitly references R-4B as the next step in HANDOFF.

---

## 11. Open questions

- **Laravel Cloud operational setup**: account, project, Postgres instance sizing, deploy pipeline, env-var population, mail provider choice (Postmark / Mailgun / SES). Tracked as a separate operational task outside this plan; does not block R-4A or R-4B code work.
- **`DB_URL` handling in `phpunit.xml`**: currently present and empty. Dropping it in Step 3 is the simplest fix; if Laravel Cloud's test-runner integration later prefers `DB_URL`-style connection strings, revisit at that time.
- **Parallel tests**: out of scope for R-4A. If the Postgres test run is noticeably slower, we can add `paratest` in a later session without changing the schema.

---

*Wait for developer approval before implementation. R-4A is a prerequisite for R-4B; no product correctness improvement lands until R-4B ships.*
