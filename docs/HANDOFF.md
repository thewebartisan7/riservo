# Handoff

**Session**: R-10 + R-11 — Reminder DST/delayed-run resilience; auth-recovery rate limiting
**Date**: 2026-04-16
**Status**: Code complete; no manual QA required (Pest suite covers the full behaviour end-to-end)

---

## What Was Built

R-10 + R-11 closed REVIEW-1 §#11 (reminder scheduling fragility) and §#12
(unthrottled auth-recovery POSTs). Two independent decisions (D-071, D-072),
two orthogonal rewrites, 12 new tests, zero frontend changes, zero
migrations, zero new dependencies.

### D-071 — Reminder eligibility = business-timezone wall-clock; past-due fires with row-level idempotency (new)

Appended to `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md`. Pins two
properties. First, "N hours before" is computed against the booking's
business timezone in wall-clock (not absolute UTC), matching the customer's
mental model for in-person appointments (consistent with D-005, D-030).
Across DST, the absolute UTC interval between the reminder and the
appointment drifts by ±1 hour — explicitly accepted. Second, a reminder is
eligible when `reminderTimeUtc <= now && starts_at > now && no booking_reminders
row exists for (booking, hoursBefore)` — an *open* look-back window with no
tuning knob. A scheduler outage of any duration shorter than `hoursBefore`
cannot drop an eligible reminder; the `booking_reminders` unique
`(booking_id, hours_before)` constraint from D-056 continues to provide
idempotency, now doing double duty as the race-safe slot claim.

### D-072 — Auth-recovery POSTs throttled per-email AND per-IP via FormRequest (new)

Appended to `docs/decisions/DECISIONS-AUTH.md`. `POST /magic-link` and
`POST /forgot-password` each check two independent buckets — one keyed on
the email (5/15min default), one on the IP (20/15min default). Either
bucket exceeding its limit throws
`ValidationException::withMessages(['email' => ...])`, matching the
LoginRequest UX (Inertia-native 302 back with validation error). Values
live under `auth.throttle.{magic_link|password_reset}.{max_per_email,max_per_ip,decay_minutes}`
in `config/auth.php`, env-tunable via `THROTTLE_MAGIC_LINK_*` /
`THROTTLE_PASSWORD_RESET_*`. Hits fire on every invocation (no "success
clears" semantics — the endpoints always return generic success to resist
enumeration per D-037). The `Lockout` event is deliberately NOT emitted —
that's an auth-lockout signal, not an abuse-prevention signal.

### Backend — `SendBookingReminders::handle()` rewritten

`app/Console/Commands/SendBookingReminders.php`. Structural changes:

- `$now = CarbonImmutable::now('UTC')` (was `now()`).
- Single candidate query:
  `Booking::where('status', BookingStatus::Confirmed)->whereBetween('starts_at', [$now, $now->addHours(max + 1)])`
  with `reminders` eager-loaded. The 1-hour buffer past `max(reminder_hours)`
  covers DST fall-back, where wall-clock 24h can span up to 25 absolute UTC
  hours. Replaces the pre-R-10 per-hours-before inner-query loop with the
  ±5-minute window (lines 38-46 of the old file).
- Per-booking × per-business-configured-`hours_before` inner loop:
  - Wall-clock reminder time:
    `$booking->starts_at->toImmutable()->setTimezone($tz)->modify("-{$hoursBefore} hours")->utc()`.
    **Honest drift from plan sketch**: the plan's §3.1.2 sketch used
    `->subHours($hoursBefore)`, but Carbon's `subHours()` subtracts
    absolute UTC seconds (not wall-clock hours) and therefore does NOT
    implement D-071 decision 1. `modify("-N hours")` is the correct
    primitive — it preserves wall-clock across DST and rolls forward into
    the spring-forward gap (matching D-071 decision 4). Verified in
    tinker; the plan's `subHours(24)` lands at `2026-10-25 09:00 UTC`
    post-DST (absolute), while `modify("-24 hours")` matches the
    semantics D-071 decision 4 prescribes.
  - Past-due filter: `if ($reminderTimeUtc->greaterThan($now)) continue;`.
  - In-memory check: `$booking->reminders->contains('hours_before', $hoursBefore)`.
  - Slot claim first:
    `try { BookingReminder::create(...) } catch (UniqueConstraintViolationException) { continue; }`.
    Only after successful claim does `Notification::route('mail', ...)->notify(...)` fire.

### Backend — Two new FormRequests for auth-recovery throttle

`app/Http/Requests/Auth/SendMagicLinkRequest.php` and
`app/Http/Requests/Auth/SendPasswordResetRequest.php`. Shape mirrors
`LoginRequest::ensureIsNotRateLimited()` but (a) checks two buckets
(per-email + per-IP), (b) hits unconditionally on every call, (c) does
NOT emit `Illuminate\Auth\Events\Lockout`. Rules reduce to
`['email' => ['required', 'string', 'email']]` — validation migrates
out of the controller. Keys are `magic-link:email:…`, `magic-link:ip:…`,
`password-reset:email:…`, `password-reset:ip:…` — deliberately namespaced
so the two endpoints don't share counters.

### Controllers — signature swaps only

`MagicLinkController::store` now takes `SendMagicLinkRequest`, calls
`$request->ensureIsNotRateLimited()` as the first line, and drops its
inline `$request->validate([...])`. `PasswordResetController::store`
mirrors the same three-line change. Rest of both methods is untouched.

### Config + i18n

`config/auth.php` grows an `auth.throttle.*` subtree with defaults
5/email, 20/ip, 15min decay per endpoint. `lang/en.json` gains one new
key: `"Too many requests. Please try again in :minutes minute(s)."`.

### New test coverage (+12 tests)

`tests/Feature/Commands/SendBookingRemindersTest.php` (+5 tests,
appended for cohesion):

- DST fall-back (2026-10-25, Europe/Zurich) — booking 2026-10-26 09:00
  UTC with `reminder_hours=[24]`; travel to `now = 2026-10-25 09:00 UTC`;
  assert reminder fires.
- DST spring-forward + gap hour (2026-03-29) — two bookings in one test:
  a regular 10:00 CEST appointment and a 02:30 CEST appointment whose
  24h wall-clock predecessor falls in the non-existent 02:00-03:00
  local hour; Carbon's `modify()` rolls forward into post-transition.
  Asserts both reminders fire without exception.
- Delayed run — `now = 2026-04-13 10:30 UTC`, booking at 2026-04-14
  10:00 UTC with `[24]`; the wall-clock 24h-before eligibility passed
  30 min ago. New code fires; the pre-R-10 ±5-min window would have
  missed.
- Delayed-run idempotency — same fixture, command invoked twice;
  assert `BookingReminder::count() === 1` and notification sent exactly
  once.
- Past-appointment cutoff — booking 1 hour in the past; the
  `whereBetween(starts_at, [now, ...])` lower bound excludes it; no
  reminder fires.

`tests/Feature/Auth/AuthRecoveryThrottleTest.php` (+7 test cases across
5 test blocks, using `dataset(['magic-link', 'forgot-password'])` where
the behaviour is symmetric):

- Per-email bucket blocks after 5 hits (dataset over both endpoints → 2 cases).
- Per-IP bucket blocks after 20 hits with rotating emails (dataset → 2 cases).
- Per-email and per-IP buckets are orthogonal (4 alice + 1 bob + 1 alice
  all succeed; 6th alice trips the per-email bucket).
- Throttle keys are endpoint-scoped (exhaust magic-link for alice@;
  forgot-password for alice@ still succeeds — different namespace).
- Decay window frees the bucket (`$this->travel(16)->minutes()` after
  exhaustion; the next hit succeeds).

---

## Current Project State

- **Backend**: `SendBookingReminders` now computes eligibility in
  wall-clock local time with past-due eligibility and row-level
  idempotency. Two new FormRequests wrap the two auth-recovery
  POST endpoints with a per-email + per-IP throttle pair. Two
  controllers got three-line signature swaps; no other backend code
  changed.
- **Frontend**: no changes. The throttle error surfaces on the existing
  `errors.email` prop that magic-link and forgot-password pages already
  render (same prop login uses) — no React changes needed.
- **Routes**: no changes. `php artisan route:list --path=magic-link`
  and `--path=forgot-password` confirm the endpoints still resolve to
  the same controllers.
- **Scheduler**: cadence unchanged — `everyFiveMinutes()->withoutOverlapping()`
  per `routes/console.php:11`. Correctness now lives in the eligibility
  math, not the cadence (D-071 decision 6).
- **Tests**: full Pest suite green on Postgres — **484 passed, 2013
  assertions**. +12 from the R-9 baseline of 472 (5 reminder + 7
  throttle cases).
- **Decisions**: D-071 in `DECISIONS-BOOKING-AVAILABILITY.md`
  (reminder semantics). D-072 in `DECISIONS-AUTH.md` (throttle
  pattern). D-001 – D-068 untouched.
- **Migrations**: none. The existing `booking_reminders` table
  (D-056, migration `2026_04_16_100012_create_booking_reminders_table.php`)
  carries both the deduplication and the race-safe slot-claim duty.
- **i18n**: one new key in `lang/en.json`
  (`"Too many requests. Please try again in :minutes minute(s)."`).
- **Config**: `config/auth.php` grew `auth.throttle.*` subtree;
  defaults encoded as
  `(int) env('THROTTLE_MAGIC_LINK_EMAIL', 5)` etc., so ops can tune
  post-launch without a redeploy.

---

## How to Verify Locally

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm run build
```

All three green: **484 passed** (in ~21 s), `{"result":"pass"}`, clean
Vite build in under 1 s (only the pre-existing 500 kB chunk-size notice,
unchanged).

Targeted checks:

```bash
php artisan test --compact --filter=SendBookingReminders   # 9 passed
php artisan test --compact --filter=AuthRecoveryThrottle   # 7 passed
php artisan schedule:list                                  # cadence unchanged
php artisan route:list --path=magic-link                   # POST → MagicLinkController@store
php artisan route:list --path=forgot-password              # POST → PasswordResetController@store
```

**No browser QA required this session.** Unlike R-8 and R-9 (which
depended on visual + keyboard + screen-reader behaviour the agent
cannot automate), the R-10 + R-11 scope is purely backend logic.
The 12 new Pest tests cover DST math, delayed-run recovery,
idempotency, past-due cutoff, and both throttle dimensions including
decay — end-to-end via the route layer, not unit-level mocks. A
smoke-check in a real browser would re-discover the same behaviour
the tests already pin.

---

## What the Next Session Needs to Know

R-10 and R-11 are complete. The remediation roadmap moves on to the
**R-12 + R-13 + R-14 + R-15** polish bundle (copy drift + customer
registration scope + notification delivery/branding + dependency and
URL-generation cleanup — per ROADMAP-REVIEW's "can be one session"
grouping), then **R-16** (frontend code splitting / lazy page
resolver, standalone).

When touching reminder code or auth throttling:

- **D-071 is the spec.** Wall-clock local semantics, past-due
  eligibility, row-level idempotency via `booking_reminders`. Do not
  reintroduce a `±N` minute window — it silently drops reminders past
  the `N` minute outage boundary. Do not add a watermark table — the
  unique constraint already provides the correctness guarantee at the
  right granularity.
- **Carbon `subHours()` is absolute-UTC; `modify("-N hours")` is
  wall-clock.** The two differ by 1 hour whenever the wall-clock
  interval crosses a DST transition. `modify()` is the primitive D-071
  requires; a future refactor must not silently swap back to
  `subHours()`.
- **D-072 decision 2 — two independent buckets, NOT combined.**
  Rejecting the LoginRequest `email|ip` single-key shape was a
  deliberate call — auth-recovery has no "success clears" signal
  (D-037 generic response) so a combined key leaves either attack
  axis open to rotation. Any future auth-adjacent throttle endpoint
  (e.g., email-verification resend, if it migrates off route-level
  `throttle:`) should follow the same two-bucket shape.
- **Do not emit `Illuminate\Auth\Events\Lockout` for abuse-prevention
  throttles.** That event is reserved for auth-lockout semantics
  (LoginRequest). The two new FormRequests deliberately skip it
  (D-072 decision 7).
- **Throttle values are in `config/auth.php`, not a new
  `config/throttle.php`.** If a third auth-adjacent throttle endpoint
  lands, consider a unified `config/throttle.php` consolidation pass
  (captured in PLAN §10 as a carry-over), but do not split values
  across two config files piecemeal.

---

## Open Questions / Deferred Items

- **R-9 manual QA — developer-driven.** The 15-item browser + SR
  checklist from the prior HANDOFF is still the one remaining gate on
  R-9. Unrelated to this session.
- **R-8 manual QA** — also carried over from the R-8 HANDOFF.
- **Scheduler-lag alerting.** Nothing today pages ops if `schedule:run`
  hasn't executed for an hour. D-071 accepts a reminder being dropped
  when a scheduler outage exceeds `hoursBefore`; the detection gap is
  a post-launch ops concern, captured in the R-10-11 plan §10.
- **Consolidate rate-limiter definitions in `config/throttle.php`.**
  `booking-api` / `booking-create` are hardcoded in
  `AppServiceProvider::boot()`; `magic_link` / `password_reset` live
  in `config/auth.php`. A future pass can unify. Non-blocking.
- **`X-RateLimit-Remaining` / `Retry-After` headers on throttled
  auth-recovery responses.** The current validation-error shape
  doesn't set them (parity with LoginRequest). API-first clients
  would want them; post-launch concern.
- **Per-email-bucket lockout telemetry.** If abuse monitoring shows
  >1% of legitimate users tripping the per-email cap, tune
  `THROTTLE_MAGIC_LINK_EMAIL` / `THROTTLE_PASSWORD_RESET_EMAIL` up via
  env flip (no redeploy).
- **Emit a `RateLimitExceeded` event for auth-recovery throttle.**
  Not today. Add a listener if abuse telemetry wants it.
- **SMS / WhatsApp reminder channel.** SPEC §9 post-MVP. The
  eligibility shape (wall-clock local, past-due, `booking_reminders`
  idempotency) composes over a per-channel extension — likely via
  `(booking_id, hours_before, channel)` unique.
- **Reminder `hours_before` per-booking override.** `reminder_hours`
  is per-Business per D-019; per-booking override is a post-MVP
  product decision.
- **Dashboard UI for reminder status / replay / manual trigger.**
  Post-MVP. The `booking_reminders` table is already queryable, so
  this is a UI-only follow-up.
- **Fall-back-overlap wall-clock ambiguity.** Carbon picks the first
  occurrence (earlier UTC instant); D-071 decision 4 accepts this.
  If a support case surfaces customer-perceived-wrong behaviour on
  the autumn DST weekend, we revisit.
- **Browser-test infrastructure** (Pest Browser, Playwright) —
  carry-over from R-7 / R-8 / R-9.
- **Popup widget i18n** — carried over from R-9.
- **`docs/ARCHITECTURE-SUMMARY.md` stale terminology** — carry-over
  from R-5/R-6.
- **Real-concurrency smoke test** — carried over from R-4B.
- **Availability-exception race** — carried over from R-4B.
- **Parallel test execution** (`paratest`) — carried over from R-4A.
- **Multi-business join flow + business-switcher UI (R-2B)** — still
  deferred.
- **Dashboard-level "unstaffed service" warning** — still deferred.
