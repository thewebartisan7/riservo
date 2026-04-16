# Deployment Guide

riservo.ch is deployed on **Laravel Cloud** — the first-party managed host for
Laravel applications. This guide covers what Laravel Cloud provides, what the
project needs configured there, and what the local development setup looks
like.

---

## Platform

- **Application runtime**: Laravel Cloud-managed PHP 8.3 with Composer
  dependencies installed by the platform's build pipeline.
- **Database**: Laravel Cloud-managed **Postgres 16** (see D-065).
- **Queue workers**: Laravel Cloud-managed — no Supervisor, no manual process
  supervision.
- **Scheduler**: Laravel Cloud-managed — no server-level cron entry; the
  platform runs `schedule:run` each minute.
- **File storage**: Laravel Cloud-managed object storage, exposed through the
  Laravel `Storage` facade with the `public` disk.
- **Deployments**: triggered by pushes to the configured branch; the platform
  builds, runs migrations, caches config/routes/views, and rolls the new
  release.

---

## Environment Variables

Configured on the Laravel Cloud project, not in a committed `.env`.

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://riservo.ch

DB_CONNECTION=pgsql
# DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD
# are injected by Laravel Cloud from the managed Postgres instance.

QUEUE_CONNECTION=database

# Mail provider is chosen at deploy time.
# Laravel Cloud offers Postmark / Mailgun / SES integrations; credentials
# are injected via the selected integration's secrets.
MAIL_MAILER=
MAIL_FROM_ADDRESS=noreply@riservo.ch
MAIL_FROM_NAME="riservo"
```

Additional variables (Google OAuth for calendar sync, Stripe keys when Cashier
is adopted, etc.) are added as each integration lands.

---

## Scheduler

The Laravel scheduler is executed by Laravel Cloud every minute. No cron
configuration is required. Scheduled commands:

| Command | Frequency | Description |
|---------|-----------|-------------|
| `bookings:send-reminders` | Every 5 minutes | Sends reminder emails based on each business's `reminder_hours` config |
| `bookings:auto-complete` | Every 15 minutes | Transitions confirmed bookings past their end time to `completed` status |

---

## Queue Worker

The application uses the `database` queue driver. Laravel Cloud runs the
worker(s) for the project; scaling and restarts on deploy are handled by the
platform. No Supervisor setup required.

For local development:

```bash
php artisan queue:work --sleep=3 --tries=3 --timeout=90
```

---

## Frontend Assets

Laravel Cloud builds Vite assets as part of the deploy pipeline. Locally:

```bash
npm ci
npm run build
```

---

## Post-Deploy Commands

Laravel Cloud runs the standard cache warm-up sequence on each release. The
equivalent sequence locally (e.g., after pulling a schema change):

```bash
php artisan migrate
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

---

## Local Development

- **PHP**: served by Laravel Herd at `https://riservo-ch.test` (the default).
  `php artisan serve` (defaults to `http://localhost:8000`) is also supported
  as a manual fallback — run it in a side terminal if you prefer the artisan
  port. `composer dev` runs only queue, logs, and vite; it no longer spawns
  `php artisan serve`.
- **Database**: Postgres 16 via DBngin at `127.0.0.1:5432`. The local database
  is `riservo-ch`; the test database is `riservo_ch_testing` (used by
  `phpunit.xml`). Copy `.env.example` to `.env` and fill the `DB_PASSWORD`
  value for your local Postgres instance.
- **Frontend**: `npm run dev` for HMR, or `npm run build` for a one-off bundle.
- **Queues / scheduler**: run on demand (`queue:work`, `schedule:work`); neither
  runs automatically in local dev.

If a local migration or seeder fails with a `pgsql` driver error, verify that
`pdo_pgsql` is enabled in the Herd-selected PHP runtime.
