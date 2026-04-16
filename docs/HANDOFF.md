# Handoff

**Session**: R-4A — Database engine swap (Postgres everywhere, Laravel Cloud as production host)
**Date**: 2026-04-16
**Status**: Complete

---

## What Was Built

R-4A is pure infrastructure: it swaps the database engine from `SQLite (dev) /
MariaDB (prod)` to **Postgres 16 across all environments**, and re-targets
production from Hostpoint shared hosting to **Laravel Cloud**. No product
behaviour changes. D-065 is the new architectural decision recorded in
`docs/decisions/DECISIONS-FOUNDATIONS.md`.

The swap is the prerequisite for **R-4B — booking race condition guard**,
which will use Postgres-only features (`EXCLUDE USING GIST` + `btree_gist`)
to declaratively prevent overlapping bookings on the same provider.

### Engine changes

- `.env.example` — single active `pgsql` block against the local DBngin-backed
  database; the former SQLite default, the commented MariaDB block, and the
  commented Postgres block are all removed. No commented alternative engines.
- `config/database.php` — `default` fallback flipped from `sqlite` → `pgsql`.
  The `sqlite`, `mysql`, `mariadb`, and `sqlsrv` connection arrays remain as
  framework defaults (harmless; not referenced by any code or doc).
- `config/queue.php` — `batching` and `failed` fallbacks flipped from `sqlite`
  → `pgsql`.
- `composer.json` — the `post-create-project-cmd` entry that created
  `database/database.sqlite` on fresh installs is removed.
- `database/.gitignore` — emptied (was `*.sqlite*`; no longer relevant).
- `phpunit.xml` — test connection flipped from `sqlite` + `:memory:` to
  `pgsql` against the local `riservo_ch_testing` database. `DB_URL` line
  removed.

### Migration ordering fix

Postgres creates foreign keys strictly; SQLite defers the check. Six
migrations that shared the timestamp `2026_04_12_191019` were renamed to
sequential stamps `191014`–`191018` so each referenced table exists before
the referring table is created:

| New stamp | Table |
| --- | --- |
| `2026_04_12_191014` | `businesses` |
| `2026_04_12_191015` | `business_hours`, `services` |
| `2026_04_12_191016` | `business_user` |
| `2026_04_12_191017` | `customers` |
| `2026_04_12_191018` | `collaborator_service` |
| `2026_04_12_191019` | `bookings` (runs last) |

`bookings` still runs last within the group; the other six now run in
dependency order.

### Test adjustments

Postgres `TIME` columns return `HH:MM:SS`; SQLite returns raw `HH:MM` when
that is the format that went in. Three test assertions compared raw time
strings directly and broke on Postgres:

- `tests/Feature/Onboarding/Step2HoursTest.php`
- `tests/Feature/Settings/BusinessExceptionTest.php`
- `tests/Feature/Settings/WorkingHoursTest.php`

Assertions updated from `'10:00'` → `'10:00:00'` (and similar). Production
code uses `setTimeFromTimeString()`, which accepts either form — no runtime
change.

### CI changes

`.github/workflows/ci.yml` now stands up a Postgres 16 service container and
runs migrations + the Pest suite against it. The `pdo_pgsql` extension is
explicitly requested in the `setup-php` step.

### Documentation swept

- `docs/SPEC.md` §4 Tech Stack: Postgres 16 everywhere; File Storage section
  updated to Laravel Cloud-managed object storage.
- `docs/ARCHITECTURE-SUMMARY.md` Platform: Postgres 16 line replaces SQLite /
  MariaDB.
- `docs/DEPLOYMENT.md`: full rewrite around Laravel Cloud. Supervisor,
  Hostpoint SMTP, and shared-host cron removed. Managed Postgres / queue
  workers / scheduler documented.
- `docs/ROADMAP.md`: Session 1 / 2 / 10 checklist items updated; the
  post-MVP Hostpoint entries removed or rewritten.
- `docs/reviews/ROADMAP-REVIEW.md` §R-4 — context text refreshed to point at
  Postgres-only features.
- `.claude/CLAUDE.md` and `.agents/AGENTS.md`: Database and File-storage lines
  updated.
- `docs/decisions/DECISIONS-FOUNDATIONS.md`: D-009 Consequences extended with
  the Laravel Cloud storage note; D-065 appended. D-009 and D-025 remain
  accepted on their own merits — neither is superseded.

### Permitted remaining references

Case-insensitive grep for `sqlite | mariadb | mysql | hostpoint` across the
repo returns only the explicitly allowed exceptions:

- `config/database.php` — framework-default connection arrays.
- `docs/archive/plans/` — historical plans, left untouched.
- `docs/decisions/DECISIONS-FOUNDATIONS.md` — D-025's historical context and
  D-065's own context/consequences, both intentional.
- `docs/plans/PLAN-R-4A-DB-ENGINE.md` — this session's plan (moved to
  `docs/archive/plans/` at session close).
- `composer.lock` / `vendor/` — dependency metadata; not authored.

---

## Current Project State

- **Backend**: Postgres 16 is the single supported engine (`pgsql` driver).
  The application itself is unchanged — no new services, rules, policies, or
  controllers.
- **Frontend**: no changes.
- **Routes**: no changes.
- **Tests**: full Pest suite green on Postgres — **433 passed, 1695
  assertions**. Exactly the same count as the R-2 baseline.
- **Decisions**: D-065 appended to `docs/decisions/DECISIONS-FOUNDATIONS.md`.
  D-009's Consequences extended; D-025 left intact.
- **CI**: GitHub Actions now uses a Postgres 16 service container.

---

## Local Dev Pull Checklist

Every developer who pulls this session must update their local `.env`:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=riservo-ch
DB_USERNAME=postgres
DB_PASSWORD=
```

Then, one-off:

```bash
php artisan config:clear
php artisan migrate:fresh --seed
```

The test database `riservo_ch_testing` must exist locally (created once via
DBngin / `CREATE DATABASE` against the local Postgres instance). `phpunit.xml`
points at it by default.

A developer who skips the `.env` update hits an immediate loud migration
failure — no silent bad state.

---

## What the Next Session Needs to Know

**R-4B — booking race condition guard** is the next session, with its own
plan already checked in at `docs/plans/PLAN-R-4B-RACE-GUARD.md`. R-4B adds
the actual concurrency invariant on top of this Postgres baseline:
`EXCLUDE USING GIST` with `btree_gist` to declaratively prevent overlapping
confirmed/pending bookings for the same provider, plus a concurrency test
that fires two near-simultaneous booking attempts and asserts only one
succeeds.

R-4A on its own produces no user-visible improvement. Its value is unlocking
R-4B.

**Laravel Cloud operational setup** — account, project, Postgres instance
sizing, build pipeline, env-var population on the platform, mail provider
choice — is tracked as a separate operational task outside the code roadmap.
`docs/DEPLOYMENT.md` is written against that target state so the first deploy
after R-4B lands has a correct reference.

---

## Open Questions / Deferred Items

- **Parallel test execution** (`paratest` with per-worker databases) — the
  Postgres suite runs slightly slower than SQLite `:memory:` but well inside
  the fast-feedback budget. Revisit only if it becomes painful.
- **Mail provider on Laravel Cloud** — Postmark / Mailgun / SES integration
  is chosen at deploy time; not encoded in code or config today.
- **Multi-business join flow + business-switcher UI (R-2B)** — carried over
  from the R-2 handoff; still deferred.
- **Dashboard-level "unstaffed service" warning** — carried over from R-1B;
  still deferred.
