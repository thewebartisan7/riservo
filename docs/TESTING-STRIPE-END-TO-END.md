# Testing Stripe End-to-End — manual walkthrough

This guide walks you through a real end-to-end test of the PAYMENTS roadmap (Sessions 1–5) against Stripe's test mode. At the end you will have:

- A test Stripe Connect Express account connected to a test riservo Business
- Completed a real customer booking with online payment
- Verified a webhook-driven refund
- (Optionally) triggered a dispute

Allow ~45 minutes for the first run. Subsequent runs re-use the same Stripe test account and `.env` config, so the second end-to-end flight is ~10 minutes.

---

## Part 0 — What Stripe test mode gives you

Stripe runs a full parallel "test mode" environment with its own API keys, dashboard, test cards, and simulated webhooks. Everything described here happens in test mode — no real money moves, no real KYC documents needed. Switching to live mode later is a matter of swapping API keys + redoing webhook endpoint registration.

Two things are worth knowing up-front:

1. **Connect Express test mode**: when you create a Stripe Express account via the API in test mode, Stripe's hosted onboarding page auto-fills most KYC fields for you. You click "Skip" / "Accept" / "Submit" through ~4 screens and the account comes back as `charges_enabled: true`, `payouts_enabled: true` in under a minute. No real documents.
2. **TWINT in test mode**: Stripe supports TWINT as a payment method on test Checkout sessions targeting CH-registered accounts, but the TWINT flow cannot be fully completed in test mode (no real TWINT app). For end-to-end payment verification use a **test card** (`4242 4242 4242 4242`) and keep TWINT as a "rendered in UI" check rather than a payment-completion check.

---

## Part 1 — Prerequisites

### 1.1 Stripe account (one-time, free)

If you don't already have one:

1. Sign up at <https://stripe.com> — no business registration required to run test mode.
2. Verify your email.
3. Confirm the dashboard lands in test mode (toggle "**Test mode**" in the top-right corner of the Stripe dashboard is **ON**).

### 1.2 Stripe CLI (one-time)

The CLI forwards test-mode webhooks from Stripe to your local machine. Install via Homebrew on macOS:

```bash
brew install stripe/stripe-cli/stripe
```

Then login once:

```bash
stripe login
```

This opens a browser tab; authenticate with your Stripe account. The CLI saves credentials in `~/.config/stripe/config.toml`.

Verify install:

```bash
stripe --version
# stripe version 1.x.x
```

### 1.3 Local dev environment running

You need riservo running locally to receive the webhook forwards:

```bash
# Terminal 1 — Laravel + Vite
composer run dev
# or equivalently:
# php artisan serve & npm run dev
```

Keep that terminal running. The app should be reachable at <http://localhost:8000> (or whichever port your dev script uses — note the port for the webhook step below).

---

## Part 2 — Stripe test keys

### 2.1 Platform keys (the riservo-owned Stripe account)

Stripe dashboard → **Developers** → **API keys**.

In test mode you'll see two keys on this page:

- **Publishable key**: `pk_test_51...`
- **Secret key**: `sk_test_51...` (click "Reveal test key" if redacted)

Copy both. You'll paste them into `.env` in Part 3.

### 2.2 Connect setup

No dedicated setup step. Stripe Connect Express is enabled by default on every Stripe account. The platform uses the same `STRIPE_SECRET` for both platform-level calls (account creation) and connected-account-level calls (the `Stripe-Account` header is added per-request by riservo's code).

### 2.3 Webhook secrets — two of them

riservo uses **two separate webhook endpoints** with **two separate secrets**:

1. `STRIPE_WEBHOOK_SECRET` — the Cashier subscription webhook (MVPC-3, platform-level events like `customer.subscription.updated`, `invoice.payment_succeeded`). Already present in `.env.example`.
2. `STRIPE_CONNECT_WEBHOOK_SECRET` — the Connect webhook for PAYMENTS (account events like `account.updated`, checkout events like `checkout.session.completed`, charge events like `charge.refunded`, `charge.dispute.created`). **Not yet in `.env.example`** — you need to add it.

You'll generate both webhook secrets in Part 4 via the Stripe CLI.

---

## Part 3 — `.env` setup

Open `.env` in the project root (copy from `.env.example` if you don't have one yet).

### 3.1 Platform keys

```env
STRIPE_KEY=pk_test_51...   # publishable key from Part 2.1
STRIPE_SECRET=sk_test_51...   # secret key from Part 2.1
CASHIER_CURRENCY=chf
```

### 3.2 PAYMENTS config (new — not yet in `.env.example`)

Add these lines to `.env`:

```env
# PAYMENTS Session 1–5 — config-driven country / TWINT gating (D-43)
PAYMENTS_SUPPORTED_COUNTRIES=CH
PAYMENTS_DEFAULT_ONBOARDING_COUNTRY=CH
PAYMENTS_TWINT_COUNTRIES=CH

# Connect webhook secret — filled in Part 4.2 after first `stripe listen`
STRIPE_CONNECT_WEBHOOK_SECRET=
```

Keep the three `PAYMENTS_*` values as `CH` for MVP. Extending them is a fast-follow roadmap (`docs/BACKLOG.md` — "Online payments for non-CH connected accounts").

### 3.3 Flush config cache

```bash
php artisan config:clear
```

---

## Part 4 — Webhook forwarding via Stripe CLI

You need **two** `stripe listen` processes running in parallel — one per webhook endpoint. Use two terminal tabs.

### 4.1 Cashier subscription webhook (MVPC-3 — optional for this test)

If you want SaaS-billing webhooks to land while you're testing (not strictly required for PAYMENTS testing):

```bash
# Terminal 2
stripe listen --forward-to http://localhost:8000/stripe/webhook \
  --events customer.subscription.created,customer.subscription.updated,customer.subscription.deleted,invoice.payment_succeeded,invoice.payment_failed
```

Copy the displayed `Ready! Your webhook signing secret is: whsec_...` line into `.env` as `STRIPE_WEBHOOK_SECRET`. Then `php artisan config:clear`.

### 4.2 PAYMENTS Connect webhook (required)

```bash
# Terminal 3
stripe listen --forward-to http://localhost:8000/webhooks/stripe-connect \
  --events account.updated,account.application.deauthorized,checkout.session.completed,checkout.session.expired,checkout.session.async_payment_succeeded,checkout.session.async_payment_failed,payment_intent.payment_failed,payment_intent.succeeded,charge.refunded,charge.refund.updated,refund.updated,charge.dispute.created,charge.dispute.updated,charge.dispute.closed
```

Copy the `whsec_...` line that prints into `.env` as `STRIPE_CONNECT_WEBHOOK_SECRET`. Then `php artisan config:clear`.

Leave both terminals running — they'll display every event Stripe forwards to your local app, plus the response riservo returns. Errors (e.g. signature mismatch) surface here.

**Troubleshooting**: if Laravel logs a 401 or "Invalid webhook signature", you likely forgot the `config:clear` step or pasted the wrong `whsec_...` into the wrong env var. The two secrets are NOT interchangeable.

---

## Part 5 — Create a test riservo Business

In a browser, navigate to <http://localhost:8000/register>. Complete signup:

- Use a throwaway email (e.g. `test+$(date +%s)@example.com`).
- Business name / slug is up to you (any unique slug works).
- Business country: **Switzerland (CH)** — this is important for the PAYMENTS gate.

After onboarding completes you land on the dashboard at <http://localhost:8000/dashboard>.

Leave this browser logged in; you'll use it as the **admin**.

---

## Part 6 — Connect Stripe Express (Session 1)

### 6.1 Start onboarding

Dashboard → **Settings** (sidebar) → **Connected Account**.

You'll see the "Enable online payments" CTA. Click it.

### 6.2 Complete Stripe-hosted KYC (test mode)

You'll be redirected to a Stripe URL like `https://connect.stripe.com/express/...`. This is Stripe's hosted onboarding page in test mode. In test mode:

- Country is pre-filled to **Switzerland**.
- Fill in any values for name / address / DOB — Stripe does not verify them in test mode. Use shortcuts:
  - Phone: `+41 79 000 00 00`
  - DOB: any date over 18 years ago (e.g. `01 / 01 / 1990`)
  - Address: any Swiss address (e.g. "Bahnhofstrasse 1, 8001 Zürich")
  - Bank: pick "Test bank" option if offered, or use IBAN `CH93 0076 2011 6238 5295 7` (a Stripe test IBAN — search "stripe test iban CH" for the up-to-date list).
- Accept the terms and submit.

Stripe redirects you back to the riservo dashboard. The Connected Account page should now show:

- **Status: Active** (green chip)
- **Country: CH**
- **Charges enabled: Yes**
- **Payouts enabled: Yes**

In the terminal running `stripe listen` for Connect webhooks (Part 4.2), you should see one or more `account.updated` events fire. The app's log (`storage/logs/laravel.log` or via `php artisan pail`) should show each event processed successfully.

### 6.3 Smoke test — verify `canAcceptOnlinePayments()` is true

Quick tinker check:

```bash
php artisan tinker --execute 'echo \App\Models\Business::first()->canAcceptOnlinePayments() ? "YES" : "NO";'
# Expected: YES
```

If it prints `NO`, re-check the Connected Account page — Stripe may have flagged the account for additional info. Re-run the onboarding if needed.

---

## Part 7 — Enable `payment_mode = online` (Session 5)

Still in the admin dashboard: **Settings** → **Booking**.

The "Payment mode" select now shows three options. Select **"Customers pay when booking"**. Save.

(If the "online" / "customer_choice" options are still disabled with an inline reason, `canAcceptOnlinePayments()` returned false — go back to Part 6.2 and complete onboarding.)

Tip: for a more ambitious test, switch to `customer_choice` instead — you'll see the pay-now / pay-on-site picker on the public booking page in Part 8.

---

## Part 8 — Create a test booking as a customer (Session 2a happy path)

### 8.1 Seed a service

If your test Business has no service yet: **Settings** → **Services** → **Add service**. Pick:

- Name: "Test haircut"
- Duration: 30 minutes
- Price: **50 CHF** (any positive value — zero or null forces the offline path per locked decision #8)
- Assign your provider (the admin is auto-provisioned as the first provider)
- Save.

Also confirm you have at least one availability rule: **Settings** → **Hours** or under the provider's own schedule.

### 8.2 Open the public booking page in an incognito tab

Navigate to <http://localhost:8000/{your-slug}> in a fresh incognito / private-browsing window (so you're not logged in as admin).

Walk through the flow:

1. Pick the service.
2. Pick a date + time.
3. Fill name / email / phone.
4. Reach the **summary** step.

The summary should show:

- CTA: **"Continue to payment →"**
- Caption: "Your card will be charged CHF 50.00 on the next step."
- Small uppercase badge: **"Secured by Stripe"** + **"TWINT"** pill.

Click **"Continue to payment"**.

### 8.3 Stripe Checkout

You're now on `https://checkout.stripe.com/...`. Stripe displays its hosted Checkout page with card + TWINT options.

Use the test card:

- Card number: `4242 4242 4242 4242`
- Expiry: any future date (e.g. `12 / 34`)
- CVC: any 3 digits (e.g. `123`)
- Postal code: any (e.g. `8000`)

Click **Pay**.

### 8.4 Verify the promotion

Stripe redirects back to `http://localhost:8000/booking/success/{token}` (or similar). The page should render:

- Booking status: **Confirmed**
- Payment status: **Paid**
- Amount: **CHF 50.00**

In the Connect webhook terminal (Part 4.2), you should see:

- `checkout.session.completed` → 200 OK
- (possibly) `payment_intent.succeeded` → 200 OK
- (possibly) `charge.succeeded` → 200 OK

In the database:

```bash
php artisan tinker --execute '$b = \App\Models\Booking::latest()->first(); echo "status={$b->status->value} payment_status={$b->payment_status->value} amount={$b->paid_amount_cents}";'
# Expected: status=confirmed payment_status=paid amount=5000
```

The customer receives the booking-confirmed email (captured in `storage/logs/laravel.log` if `MAIL_MAILER=log`, or in Mailpit / Mailtrap if configured).

---

## Part 9 — Test a refund (Session 3)

### 9.1 Customer-side cancel (automatic in-window refund)

Back in the incognito tab, open the customer's booking management page (there's a link in the confirmation email, or go to `/my-bookings/{cancellation_token}`).

Click **Cancel booking**. The dashboard should show:

- "Your booking is cancelled. A full refund is being issued."

In the Connect webhook terminal:

- `charge.refunded` → 200 OK
- `charge.refund.updated` → 200 OK (or `refund.updated` depending on Stripe API version)

Check the booking:

```bash
php artisan tinker --execute '$b = \App\Models\Booking::latest()->first(); echo "status={$b->status->value} payment_status={$b->payment_status->value}";'
# Expected: status=cancelled payment_status=refunded
```

### 9.2 Admin-initiated manual refund

For this flow you need a fresh paid booking — repeat Part 8 with a new customer email.

Back as admin: **Bookings** → click the booking → the **Payment & refunds** panel shows the paid amount and a **"Refund"** button.

Click **Refund**. The dialog offers:

- **Full refund** (preset — issues the full 50 CHF back)
- **Partial refund** with an amount input (try e.g. 2000 cents = 20 CHF)

Submit. The Stripe CLI log shows the same `charge.refunded` / `charge.refund.updated` sequence.

### 9.3 Disconnected-account refund fallback

Optional test: if you disconnect Stripe in the middle (**Settings** → **Connected Account** → **Disconnect**), then try to cancel a paid booking, the refund will fail with a Stripe permission error. riservo handles this gracefully:

- Booking transitions to `cancelled` regardless.
- `payment_status` becomes `refund_failed`.
- A **Pending Action** appears in the admin dashboard ("Refund could not be processed automatically").
- The customer-facing email omits the "refund issued" clause (D-175).

Reconnect Stripe via **Settings** → **Connected Account** → **Enable online payments** to retry.

---

## Part 10 — Test a dispute (Session 3, optional)

Stripe provides a dedicated test card that automatically triggers a dispute:

Card: `4000 0000 0000 0259`

Repeat Part 8 with a new customer and use that card instead of `4242 4242 4242 4242`. The booking completes normally, but within a few seconds Stripe sends a `charge.dispute.created` event.

In the Connect webhook terminal you'll see:

- `charge.dispute.created` → 200 OK

In the admin dashboard, the booking detail page shows a dispute section with a deep-link to the dispute in the Stripe Express dashboard. An admin email is dispatched.

Resolve the dispute in the Stripe dashboard (click "Submit evidence" or "Accept" — in test mode you can directly choose the outcome). riservo receives `charge.dispute.closed` and updates the Pending Action with the outcome.

---

## Part 11 — Test the race banner (Session 5, D-176)

Scenario: a `payment_mode = 'online'` business whose Stripe account gets degraded mid-session.

1. Set the Business to `payment_mode = 'online'` (Part 7).
2. Open `/{slug}` in an incognito tab and walk to the summary step — do NOT click "Continue to payment" yet.
3. In a separate shell, simulate a KYC failure on the connected account:

```bash
php artisan tinker --execute "\App\Models\StripeConnectedAccount::first()->update(['requirements_disabled_reason' => 'rejected.fraud']);"
```

4. Back in the incognito tab, click "Continue to payment".

Expected: 422 response; the page renders the banner "This business is no longer accepting online payments right now — try again later or contact them directly." The CTA stays visible but disabled.

Alternative (more realistic): if the degraded state is set BEFORE the page loads, the unavailable banner renders at page-load and the CTA is disabled from the start (D-176 Round-4 fix).

Reset:

```bash
php artisan tinker --execute "\App\Models\StripeConnectedAccount::first()->update(['requirements_disabled_reason' => null]);"
```

---

## Part 12 — Test the Payouts page (Session 4)

### 12.1 Trigger a test payout

In the Stripe dashboard (test mode):

**Dashboard** → switch account to your Express account (top-left switcher, or: **Connect** → **Accounts** → click the test account) → **Balance** → **Pay out now** (test-mode only, available immediately after charges accumulate).

Or simulate via Stripe CLI:

```bash
stripe payouts create --amount=3000 --currency=chf --stripe-account=acct_1...
```

(Get the `acct_1...` id from `\App\Models\StripeConnectedAccount::first()->stripe_account_id`.)

### 12.2 View on riservo

Dashboard → **Payouts** (sidebar, admin-only, appears only when a connected account exists).

You should see:

- **Connected account health strip** (3 chips: charges enabled, payouts enabled, Stripe Tax status)
- **Available balance** + **Pending balance** cards
- **Recent payouts** table with the test payout you created
- **Payout schedule** card (default: daily rolling)
- **"Manage payouts in Stripe" button** — clicking mints a fresh Stripe Express dashboard login link and opens it in a new tab

---

## Part 13 — Cleanup + reset (when done)

### 13.1 Clear test data

```bash
# Wipe everything — Business, bookings, connected accounts, refunds
php artisan migrate:fresh --seed
```

### 13.2 Delete the Stripe test account (optional)

In the Stripe dashboard → **Connect** → **Accounts** → find your test Express account → click it → bottom of the page has a "Reject / Delete" option. This cleans up the test Stripe-side state so the next end-to-end run starts fresh.

### 13.3 Stop the CLI listeners

Ctrl+C in terminals 2 and 3.

---

## Troubleshooting

- **Webhook returns 400 "Invalid signature"**: mismatched `STRIPE_CONNECT_WEBHOOK_SECRET` or forgot `php artisan config:clear`. Re-copy the `whsec_...` from the `stripe listen` output.
- **Checkout session creation returns 422 with `online_payments_unavailable`**: the Business's `canAcceptOnlinePayments()` returned false. Most common causes: account not verified (check the Connected Account page), country not in `PAYMENTS_SUPPORTED_COUNTRIES`, or `requirements_disabled_reason` got set. Run the tinker snippet in Part 6.3 to debug.
- **Public booking page shows "Confirm booking" instead of "Continue to payment"**: the Business is still in `payment_mode = offline` OR the service's price is null / 0. Double-check Part 7 + 8.1.
- **`stripe listen` forward returns 404**: your dev server's port differs from `localhost:8000`. Adjust the `--forward-to` URL. Or routing changed — verify the endpoint with `php artisan route:list --path=webhooks`.
- **Refund fails with "No such charge"**: happens only if you test-disconnect-and-reconnect Stripe between the charge and the refund. D-158 pins the minting account id onto the booking, so this should fail closed gracefully; if it crashes, file a bug against `RefundService`.
- **Mail isn't reaching you**: `MAIL_MAILER=log` writes to `storage/logs/laravel.log`. For visible inboxes, configure Mailpit locally (`brew install mailpit` + `MAIL_MAILER=smtp MAIL_HOST=127.0.0.1 MAIL_PORT=1025`), or Mailtrap / a real SMTP provider.

---

## What this guide does NOT cover

- **Live mode deployment** — production webhook endpoint registration, `.env.production` differences, Stripe Tax configuration per country, Stripe Connect platform settings (branding, hosted onboarding customization). See `docs/DEPLOYMENT.md`.
- **Load testing / stress testing** — this is a single-flow manual walkthrough. A multi-concurrent-booking race test would need k6 or similar.
- **Real IBAN / real KYC** — test mode only. When going to live mode the professional provides real bank details and real IDs via the same Stripe-hosted onboarding; riservo's code paths don't change.
- **TWINT completion flow** — TWINT in test mode renders in the Checkout UI but cannot be completed without a real TWINT app. Verify TWINT ships by looking for the TWINT icon on the Stripe Checkout page; use a test card to complete the charge.

---

## Reference

- **Session 1 (Connect onboarding)**: `docs/decisions/DECISIONS-PAYMENTS.md` D-109..D-128
- **Session 2a (happy path)**: D-151..D-161
- **Session 2b (failures, reaper, refunds stub)**: D-162..D-168
- **Session 3 (refunds expanded, disputes)**: D-169..D-175
- **Session 4 (payouts)**: no new D-IDs (see HANDOFF)
- **Session 5 (toggle activation, race banner)**: D-176
- **Stripe test cards catalog**: <https://stripe.com/docs/testing> (includes cards for success, decline, 3DS, disputes)
- **Stripe CLI reference**: <https://stripe.com/docs/stripe-cli>
- **Stripe Connect Express docs**: <https://stripe.com/docs/connect/express-accounts>
