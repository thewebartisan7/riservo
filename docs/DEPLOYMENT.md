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

# Google Calendar OAuth + sync (MVPC-1 + MVPC-2)
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=${APP_URL}/dashboard/settings/calendar-integration/callback
```

Additional variables (Stripe keys when Cashier is adopted, etc.) are added as
each integration lands.

---

## Scheduler

The Laravel scheduler is executed by Laravel Cloud every minute. No cron
configuration is required. Scheduled commands:

| Command | Frequency | Description |
|---------|-----------|-------------|
| `bookings:send-reminders` | Every 5 minutes | Sends reminder emails based on each business's `reminder_hours` config |
| `bookings:auto-complete` | Every 15 minutes | Transitions confirmed bookings past their end time to `completed` status |
| `calendar:renew-watches` | Daily at 03:00 | Refreshes Google Calendar push-notification channels approaching their 30-day expiry (D-086) |

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

---

## Google Calendar OAuth + Sync

riservo supports bidirectional sync with Google Calendar: riservo bookings push
to the user's destination calendar; events on configured conflict calendars pull
into riservo as external bookings that block availability. Operational
requirements:

### Google Cloud Console setup

1. Create a Google Cloud project and enable **Google Calendar API**.
2. Configure the OAuth consent screen (external, production).
3. Create an OAuth 2.0 Client ID of type **Web application**.
4. Register the exact redirect URI — must match `GOOGLE_REDIRECT_URI` byte-for-byte:
   - Production: `https://riservo.ch/dashboard/settings/calendar-integration/callback`
   - Local: `https://{tunnel-domain}/dashboard/settings/calendar-integration/callback`
5. Copy the client ID + secret into the Laravel Cloud project's env vars.

### Webhook endpoint

Google delivers push notifications to `POST {APP_URL}/webhooks/google-calendar`.

- **HTTPS is required.** Google rejects HTTP. In local dev use a tunnel (ngrok /
  Expose / Herd Share) and register the tunnel's HTTPS URL alongside the
  redirect URI.
- The domain hosting the webhook endpoint must be **verified** in Google Cloud
  Console (Webmaster Central) before channels can be created. Without
  verification, `events.watch` returns 401/403.
- The endpoint is CSRF-excluded (`bootstrap/app.php`). Authenticity is enforced
  inside the controller by comparing `X-Goog-Channel-Token` against the
  per-channel secret (`calendar_watches.channel_token`, constant-time compare).

### Queue worker

Push and pull flow through queued jobs:

- `PushBookingToCalendarJob` — dispatched from every booking mutation site (D-083).
- `PullCalendarEventsJob` — dispatched by the webhook and by the sync-now button.
- `StartCalendarSyncJob` — dispatched once per integration at configure time.

A queue worker must run in production for any calendar activity to reach Google
(and vice versa). Laravel Cloud handles this automatically; for local dev:
`php artisan queue:work`.

### Scheduler

`calendar:renew-watches` runs daily at 03:00 UTC. Google caps watch channels at
~30 days; the command refreshes any channel expiring within the next 24 hours
(stops the old channel, starts a new one, updates `calendar_watches`).

---

## Billing (Stripe)

Subscription billing on the `Business` model via `laravel/cashier:^16` (D-007,
D-089..D-095). Stripe test mode for MVP; live mode is pre-launch activity.

### Stripe account setup (test mode)

1. Create a Stripe account at https://dashboard.stripe.com (or use an existing
   one). Stay in **Test mode** for MVP.
2. Create one Product named **"riservo Plan"** with two recurring prices:
   - `CHF 29.00 / month` — copy the price ID into `STRIPE_PRICE_MONTHLY`.
   - `CHF 290.00 / year` — copy the price ID into `STRIPE_PRICE_ANNUAL`.
   Both prices ride on the same product.
3. **Enable Stripe Tax** in the dashboard (Tax → Settings). Set the origin
   address (riservo.ch business address). Stripe will compute Swiss VAT
   automatically on every invoice. No riservo-side VAT logic is required
   (D-094).
4. Register a webhook endpoint at the URL `https://<your-domain>/webhooks/stripe`
   (or a tunnel like `https://<random>.ngrok.app/webhooks/stripe` for local
   dev). Subscribe to at least these events:
   - `customer.subscription.created`
   - `customer.subscription.updated`
   - `customer.subscription.deleted`
   - `invoice.payment_succeeded`
   - `invoice.payment_failed`
   Copy the signing secret into `STRIPE_WEBHOOK_SECRET`.
5. Copy the publishable key (`pk_test_…`) into `STRIPE_KEY` and the secret key
   (`sk_test_…`) into `STRIPE_SECRET`.

### Environment variables

```
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRICE_MONTHLY=price_...
STRIPE_PRICE_ANNUAL=price_...
CASHIER_CURRENCY=chf
```

`config/billing.php` reads `STRIPE_PRICE_*`. `config/cashier.php` reads
`STRIPE_*` and `CASHIER_*`. Display amounts in `config/billing.php`
(`display.{monthly,annual}.amount`) are intentional duplicates of the Stripe
prices — keep them in lockstep.

### Webhook endpoint

`POST /webhooks/stripe` is the only Stripe-facing endpoint. CSRF is excluded in
`bootstrap/app.php`. Cashier's `VerifyWebhookSignature` middleware validates the
`Stripe-Signature` header against `STRIPE_WEBHOOK_SECRET` automatically (set the
secret to a non-empty value to enable verification).

The handler is `App\Http\Controllers\Webhooks\StripeWebhookController`, a
subclass of Cashier's `WebhookController` that adds cache-layer event-id
deduplication (D-092). Stripe retries with the same event id are short-
circuited within a 24-hour window. Cache driver in production is `database`
(durable across deploys).

### Read-only behaviour after cancellation

Subscription state transitions:

- `trial` — no `subscriptions` row exists. Indefinite trial. Full dashboard
  access.
- `active` — subscription is healthy. Full access.
- `past_due` — Stripe is dunning a failing card. **Write-allowed** (D-089's
  freeload envelope) — the salon retains access during the ~7-day default
  retry window. If retries exhaust, Stripe fires
  `customer.subscription.deleted` and the business transitions to `read_only`.
- `canceled` — `cancel_at_period_end=true` is set, still inside the paid
  period (`onGracePeriod()`). Full access until period end.
- `read_only` — subscription has fully ended (`ended()` true). Dashboard is
  read-only: `GET` requests work, every mutating verb redirects to
  `/dashboard/settings/billing` with a flash error. Admin can re-subscribe
  from there. Banner across every dashboard page surfaces the state.

The `EnsureBusinessCanWrite` middleware (alias `billing.writable`) closes
every dashboard mutation. **It does NOT gate**:

- Public booking (`/{slug}`) — guests can still book existing services on a
  lapsed business so existing customers aren't surprised.
- Webhook endpoints (`/webhooks/stripe`, `/webhooks/google-calendar`) —
  required for Stripe → resubscribe transitions and inbound calendar events.
- Authentication, invitation, onboarding routes — outside the dashboard
  group.
- Customer area `/my-bookings` — separate role middleware.
- Server-side automation: `AutoCompleteBookings`, `SendBookingReminders`,
  `calendar:renew-watches`, `PullCalendarEventsJob`, `PushBookingToCalendarJob`
  continue to run on lapsed businesses for already-created bookings. The
  business paid for the period those bookings live in; their customers and
  connected Google calendars must continue to reflect the true booking
  lifecycle.

### Pre-launch — switch to live keys

Documented separately in the pre-launch checklist (`docs/SPEC.md §15` — Stripe
go-live). Switching is a flip of the env vars from `*_test_*` to live keys plus
re-creating the live-mode product + prices + webhook endpoint. The application
code does not change.

---

## Stripe Connect (customer-to-professional payments)

Connected-account onboarding shipped in PAYMENTS Session 1. This section covers
the operator-side setup; payment-taking, refund, payout, and dispute handlers
land in PAYMENTS Sessions 2a / 2b / 3 / 4. The full roadmap lives in
`docs/ROADMAP.md`; the cross-cutting decisions are at
`docs/decisions/DECISIONS-PAYMENTS.md` (D-109..D-119).

### Stripe-side configuration

The Connect webhook is a SEPARATE subscription from the platform-subscription
webhook above. In the Stripe dashboard, navigate to
**Developers → Webhooks → Connected accounts** (the second tab — distinct from
the "Account" tab that hosts the subscription webhook).

1. Create a new endpoint pointing at `https://<your-domain>/webhooks/stripe-connect`.
   For local development, an ngrok tunnel works the same as the subscription
   webhook (`https://<random>.ngrok.app/webhooks/stripe-connect`).
2. Subscribe to the following events. The controller log-and-200's any
   unhandled type, so configuring all of them now is forward-compatible.
   - **Session 1 handles**: `account.updated`, `account.application.deauthorized`,
     `charge.dispute.created`, `charge.dispute.updated`, `charge.dispute.closed`
     (dispute events persist a Pending Action per D-123; Session 3 wires the
     admin email + dashboard deep-link).
   - **Session 2a handles (live as of this session)**: `checkout.session.completed`,
     `checkout.session.async_payment_succeeded`. Both route to the same
     `CheckoutPromoter` service (shared with the success-page return flow);
     the handler branches on `$session->payment_status` per locked decision
     #41, so a future Stripe flip of TWINT to async won't regress the path.
   - **Sessions 2b / 3 will add**: `checkout.session.expired`,
     `checkout.session.async_payment_failed`, `payment_intent.payment_failed`,
     `payment_intent.succeeded`, `charge.refunded`, `charge.refund.updated`,
     `refund.updated`. Configuring them at Session 2a time is harmless.
3. Copy the signing secret into `STRIPE_CONNECT_WEBHOOK_SECRET` (env). This is
   DISTINCT from `STRIPE_WEBHOOK_SECRET` (Cashier's subscription webhook,
   above). Per locked roadmap decision #38 the two endpoints use different
   cache-key prefixes (`stripe:subscription:event:` vs `stripe:connect:event:`)
   so they cannot collide.
4. Use the SAME `STRIPE_KEY` / `STRIPE_SECRET` test-vs-live pair as the
   subscription side — Connect is a marketplace abstraction over the same
   account, not a separate Stripe account.

### Environment variables

Added in PAYMENTS Session 1:

```
STRIPE_CONNECT_WEBHOOK_SECRET=whsec_connect_...

# config/payments.php — country gating per locked roadmap decision #43.
# MVP value = CH only; the seam is open for IT/DE/FR/AT/LI in a fast-follow.
PAYMENTS_SUPPORTED_COUNTRIES=CH
PAYMENTS_DEFAULT_ONBOARDING_COUNTRY=CH
PAYMENTS_TWINT_COUNTRIES=CH
```

`config/payments.php` reads the three `PAYMENTS_*` keys; comma-separated lists
are parsed into arrays.

### Testing the Checkout flow (Session 2a)

Stripe's test mode supports TWINT on CH connected accounts. To exercise the
full customer-pays round-trip end-to-end against a real Stripe sandbox:

1. Seed a business with `payment_mode = online` and a test-verified connected
   account (the `StripeConnectedAccount::factory()->active()` state produces
   the correct shape; onboarding it via the Session 1 flow also works).
2. In a second terminal, forward Connect events to local dev:
   ```
   stripe listen --forward-to http://localhost:8000/webhooks/stripe-connect --connect
   ```
3. Book a service on `http://localhost:8000/{slug}`. Click "Continue to payment".
4. On Stripe Checkout:
   - **Card**: use `4242 4242 4242 4242` + any future expiry + any CVC.
   - **TWINT** (CH accounts only): the hosted page short-circuits the mobile
     redirect in test mode and offers "Succeed" / "Fail" buttons.
5. On return the success page redirects to `/bookings/{token}` showing
   status=Confirmed + payment_status=Paid. Check the `stripe listen` output —
   the `checkout.session.completed` webhook fires second (the success-page's
   synchronous retrieve already promoted the booking; the webhook 200s
   via the outcome-level idempotency guard).

### Webhook endpoint

`POST /webhooks/stripe-connect` is a fresh controller (NOT a Cashier subclass —
Cashier's base routes platform-subscription events that don't apply here).
CSRF is excluded in `bootstrap/app.php`. Signature verification runs in
`StripeConnectWebhookController` against `STRIPE_CONNECT_WEBHOOK_SECRET`; a
missing or empty secret in production logs a critical line and accepts the
payload without verification (test escape hatch — production MUST set the
secret).

The controller treats every `account.*` payload as a nudge per locked roadmap
decision #34 — it calls `stripe.accounts.retrieve(...)` to pull authoritative
state rather than trusting the payload. This makes the handler immune to
out-of-order delivery.

### Connected Account onboarding flow (admin-side)

1. Admin visits `/dashboard/settings/connected-account` (Settings → Online Payments).
2. Click **Enable online payments** → server creates a Stripe Express account
   via API (country = `config('payments.default_onboarding_country')`, MVP =
   `'CH'`), persists the local row, mints a Stripe Account Link, and 302s the
   admin into Stripe-hosted KYC.
3. Stripe redirects back to `/dashboard/settings/connected-account/refresh` on
   completion or abandonment. The controller re-fetches state via
   `stripe.accounts.retrieve(...)` and either:
   - mints a fresh Account Link if the user bailed mid-KYC (transparent
     re-entry into Stripe-hosted onboarding), OR
   - shows the verified-account state (charges enabled, payouts enabled,
     default currency) on the settings page.
4. Disconnect soft-deletes the local row (retaining `stripe_account_id` for
   audit + Session 2b's late-webhook refund path) and forces
   `Business.payment_mode` back to `offline`.

### Migration deploy requirement: `pending_actions` rename is NOT rolling-deploy-safe

PAYMENTS Session 1 includes the migration `2026_04_22_231235_rename_calendar_pending_actions_to_pending_actions.php` which renames `calendar_pending_actions` → `pending_actions` AND switches every model / reader to the new name in the same release. This is a classic expand/contract violation under any rolling deployment:

- **Before migration**: new code reads `pending_actions` → table does not exist → 500.
- **After migration**: old code (still serving traffic during a rolling cutover) reads `calendar_pending_actions` → table no longer exists → 500.

**Operator requirement (D-125)**: deploy this release with **zero overlap between old and new code**. Acceptable shapes:

- **Single-instance restart** (the simplest path; matches riservo's MVP / pre-launch deploy posture). Stop the old process, run `php artisan migrate`, start the new process. Brief downtime (seconds).
- **Blue-green cutover**. Spin up the new fleet, run the migration, switch traffic atomically, drain the old fleet. No code overlap.
- **Maintenance mode** during the migration: `php artisan down && php artisan migrate && <deploy new code> && php artisan up`.

A standard rolling deploy (e.g. Laravel Cloud's default for some plans) will surface 500s on whichever side of the rollout is out of sync with the schema for the duration of the rollout. **Do not ship this release through a rolling deploy.**

This trade-off is acceptable for PAYMENTS Session 1 because riservo is pre-launch — there is no production traffic to disrupt. If a future session edits a similar table-rename pattern after launch, redesign as a phased expand/contract migration (rename via `Schema::rename`, then a follow-up release that drops the legacy reader paths after both code and DB are unified).

### Hide on Booking Settings (until Session 5)

PAYMENTS Sessions 1–4 keep `payment_mode = online` and `customer_choice` HIDDEN
from the Settings → Booking select. Session 5 lifts the ban after the full
payment lifecycle (charging, refunds, disputes, payouts) is shipped. A DB-seeded
non-offline value still reads back without error; the React Select silently
shows the persisted value as its trigger label even though the popup only
contains `offline`.
