---
name: PLAN-R-10-11-OPS-CORRECTNESS
description: "R-10 + R-11: reminder DST safety and auth-recovery rate limiting"
type: plan
status: shipped
created: 2026-04-16
updated: 2026-04-16
---

# PLAN-R-10-11 — Operational correctness: reminder DST safety and auth-recovery rate limiting

**Session**: R-10 + R-11 — Reminder DST + delayed-run resilience; rate limiting on auth-recovery endpoints
**Source**: `docs/reviews/ROADMAP-REVIEW.md` §R-10 and §R-11, `docs/reviews/REVIEW-1.md` §#11 (reminders) and §#12 (rate limiting)
**Status**: proposed (plan-only)
**Date**: 2026-04-16
**Depends on**: D-005 (business timezone as single source of truth), D-019 (reminder_hours JSON on Business), D-056 (reminder dedup via `booking_reminders` table), D-035 (all roles share the `web` guard), login-throttle precedent in `app/Http/Requests/Auth/LoginRequest.php`. Independent of R-9 (different files, different concepts).

---

## 1. Context

### 1.1 R-10 — the findings

REVIEW-1 §#11 ("Reminder scheduling is fragile around missed scheduler windows and DST-sensitive expectations") flagged two correctness gaps in `app/Console/Commands/SendBookingReminders.php`:

1. **Tight ±5-minute eligibility window**. The command loops unique `reminder_hours` across all businesses and, per interval, selects bookings in `[now + hoursBefore - 5 min, now + hoursBefore + 5 min]`. A scheduler run that is delayed by more than 5 minutes (deploy, worker restart, load spike, `withoutOverlapping()` on a long previous run) silently drops every reminder whose eligibility window passed during the outage. Idempotency (the `booking_reminders` unique (booking_id, hours_before) row per D-056) does not recover these — it only prevents *duplicates*, not *misses*.
2. **Absolute-UTC eligibility math instead of business-timezone-aware wall-clock**. The computation is `$now + $hoursBefore` where `$now = now()` is UTC (`CACHE`/`APP_TIMEZONE` is the Laravel default). That means "24 hours before `starts_at`" is measured as an absolute 24-hour interval in UTC. Because `starts_at` is stored in UTC but represents a wall-clock local time in the business's timezone (D-005, D-030), the *local* interval between the sent reminder and the appointment drifts by ±1 hour around DST transitions.

ROADMAP-REVIEW §R-10 adds the explicit ask that the plan **declare the semantics** — wall-clock local vs absolute UTC — in a decision file, and implement + test to match that declaration.

### 1.2 R-11 — the findings

REVIEW-1 §#12 ("Auth recovery endpoints are not rate-limited even though they send email and expose abuse surfaces") flagged that two public auth endpoints send email on every call with no throttling:

1. `POST /magic-link` (`routes/web.php:43`, `MagicLinkController::store`) — resolves the user, generates a token, calls `$user->notify(new MagicLinkNotification($url))`. If the email doesn't resolve, still returns the same generic success message to avoid enumeration — but still crawls the user + customer tables on every call.
2. `POST /forgot-password` (`routes/web.php:46`, `PasswordResetController::store`) — calls `Password::sendResetLink($request->only('email'))`. Same generic-success pattern.

The login POST at `routes/web.php:40` is throttled — but via an in-`LoginRequest` pattern (`ensureIsNotRateLimited()` + `RateLimiter::tooManyAttempts` with key `email|ip`, 5 attempts max, `auth.throttle` validation-error shape). That pattern is the precedent to extend.

ROADMAP-REVIEW §R-11 recommends "5 attempts per email per 15 minutes" plus per-IP segmentation, configurable. The plan must make those values concrete.

### 1.3 Audit — what's actually at HEAD

Ran the code audit before drafting. Findings vs ROADMAP-REVIEW's characterization:

**R-10 — `SendBookingReminders`**

| Claim | State at HEAD | Verdict |
| --- | --- | --- |
| "±5-minute window" | `SendBookingReminders.php:39-40` — `$targetStart = $now->copy()->addHours($hoursBefore)->subMinutes(5)`; `$targetEnd = $now->copy()->addHours($hoursBefore)->addMinutes(5)`. Exact match. | HOLDS |
| "Adds `hours_before` to `now()` in UTC" | `SendBookingReminders.php:21` — `$now = now();`. Laravel's default `APP_TIMEZONE` is UTC (unset in `config/app.php`), so `now()` returns a Carbon instance in UTC. `$now->copy()->addHours($hoursBefore)` is UTC-arithmetic absolute-offset. No `setTimezone($business->timezone)` anywhere in the eligibility math. | HOLDS |
| "Does not derive '24 hours before' from each booking's business timezone" | Confirmed. The only `setTimezone()` is in `BookingReminderNotification::toMail()` line 33, which converts `starts_at` for *display in the email*. The command's eligibility logic is pure UTC. | HOLDS |
| "Tests cover UTC happy paths, but not DST or delayed scheduler runs" | `tests/Feature/Commands/SendBookingRemindersTest.php` has 4 tests — all fix `$now = CarbonImmutable::parse('2026-04-13 10:00:00', 'UTC')`. No DST, no backfill/delay scenarios. | HOLDS |
| "Idempotency via `booking_reminders`" | Model `App\Models\BookingReminder` at `app/Models/BookingReminder.php`; migration `2026_04_16_100012_create_booking_reminders_table.php`; unique `(booking_id, hours_before)` confirmed in schema via `php artisan db:table booking_reminders`. The command inserts the row before dispatching (`SendBookingReminders.php:53-57`), and the query guards via `whereDoesntHave('reminders', fn ($q) => $q->where('hours_before', $hoursBefore))`. D-056 holds in full. | HOLDS |
| Scheduler cadence | `routes/console.php:11` — `Schedule::command('bookings:send-reminders')->everyFiveMinutes()->withoutOverlapping()`. `php artisan schedule:list` confirms cron `*/5 * * * *`. A delayed run is therefore a realistic failure mode: if `withoutOverlapping()` holds the lock while a slow run completes, the next window shifts. | HOLDS |

**Adjacent findings surfaced during audit** (not all rolled into R-10 scope; see §10 for carry-overs):

- **`BookingReminderNotification` implements `ShouldQueue`.** `app/Notifications/BookingReminderNotification.php:11`. Dispatch is async; the eligibility calculation is the only latency-sensitive step. That makes per-iteration per-booking computation acceptable.
- **`Notification::route('mail', ...)` sends via on-demand notifiable.** `SendBookingReminders.php:59-60`. This works, but the tests assert via `Notification::assertSentOnDemand(BookingReminderNotification::class, fn(...)`. Rewrite must preserve this shape.
- **`Booking::provider()` uses `withTrashed` (D-067).** Reminder code does `->with(['business', 'service', 'provider.user', 'customer'])` — safe against historical bookings whose provider has been soft-deleted.
- **`Business::reminder_hours` is a JSON array cast.** `app/Models/Business.php` casts `'reminder_hours' => 'array'`. The command defensively re-checks `in_array($hoursBefore, $booking->business->reminder_hours ?? [])` inside the per-booking loop (line 49) to handle the case where a business has `[1]` but a sibling business has `[24]` — the outer collection is a *union* of all hours in play.
- **No business-timezone round-trip test anywhere.** Repo-wide grep for `setTimezone.*reminder|reminder.*setTimezone` returns hits only in the notification template. The command is timezone-naive by construction.

**R-11 — the auth-recovery endpoints**

| Claim | State at HEAD | Verdict |
| --- | --- | --- |
| "`POST /magic-link` has no throttle" | `routes/web.php:42-43` — no middleware beyond the `guest` group. `php artisan route:list --path=magic-link` confirms no `throttle:` is attached. `MagicLinkController::store` has no in-request rate-limit check. | HOLDS |
| "`POST /forgot-password` has no throttle" | `routes/web.php:46` — no `throttle:` middleware. `PasswordResetController::store` has no in-request rate-limit check. Same enumeration-hardening generic-success response pattern as magic-link. | HOLDS |
| "Login has throttling" | `app/Http/Requests/Auth/LoginRequest.php:36-72` — full pattern: `ensureIsNotRateLimited()` → `RateLimiter::tooManyAttempts($throttleKey, 5)` → on limit, `throw ValidationException::withMessages(['email' => __('auth.throttle', ...)])`. Key is `strtolower(email).'|'.ip()` (line 79). No explicit route-level `throttle:` middleware — the FormRequest is the throttle. | HOLDS |
| "Throttle suggests 5/email/15 min" | ROADMAP-REVIEW wording, not code. Ready for the plan to confirm or revise. | N/A (decision) |

**Adjacent findings surfaced during audit**:

- **`RateLimiter::for` registrations exist but only for booking endpoints.** `app/Providers/AppServiceProvider.php:26-28` defines `booking-api` (60/min/ip) and `booking-create` (5/min/ip). The hardcoded values live in code, not config. No existing `config/throttle.php`. This is the "existing convention" to compare against.
- **Zero throttle tests in the suite.** Repo-wide grep: `throttle|RateLimiter|tooManyAttempts|Lockout|auth\.throttle` returns zero test file matches. R-11 sets the precedent for the first throttle test.
- **Test env uses `CACHE_STORE=array`.** `phpunit.xml:23`. Rate-limiter state resets cleanly between tests — no manual `RateLimiter::clear()` needed in test teardown. Deterministic time manipulation (`Carbon::setTestNow`) composes with the array cache.
- **Session 13-ish concern**: `MagicLinkController::resolveUser()` auto-creates a User for a Customer on first magic-link request. If a throttle blocks a request, no User is created — so a legitimate customer-register-via-magic-link flow can be stalled by an attacker pre-consuming the per-email bucket. Surfaces as a risk in §8.
- **`routes/web.php:73`**: there's one other throttled route today — `POST /email/verification-notification` with `throttle:6,1`. Confirms route-middleware-`throttle:` is an acceptable pattern in this codebase alongside the FormRequest-internal pattern used by login. Two precedents exist; R-11 must choose which to extend.

### 1.4 Bundle-or-split — R-10 + R-11 bundled

Applied the four bundling conditions from PLAN-R-8 §1.3:

| Condition | R-10 + R-11 | Verdict |
| --- | --- | --- |
| 1. Shared files or shared concepts | R-10 touches `app/Console/Commands/SendBookingReminders.php`, `app/Notifications/BookingReminderNotification.php` (read-only), the reminder tests, and the reminder decision file. R-11 touches `routes/web.php`, two auth controllers' FormRequest bindings, `config/auth.php`, `lang/en.json`, and new throttle tests. **Zero file overlap, zero concept overlap.** Reminders are a scheduled command + timezone math; rate limiting is request-side enforcement on public POST endpoints. | FAIL |
| 2. No new architectural decision blocked behind the sibling | R-10 needs **D-071** — reminder eligibility semantics + delayed-run strategy. R-11 needs **D-072** — auth-recovery throttle policy. Two independent decisions, neither depending on the other. They land in *different* decision files (D-071 → `DECISIONS-BOOKING-AVAILABILITY.md`, D-072 → `DECISIONS-AUTH.md`). | FAIL |
| 3. Combined diff reviewable in one sitting | R-10: ~50 lines rewrite of the command, ~5-8 new/updated tests with DST fixtures. R-11: ~30 lines across two new FormRequest classes + controller signature updates + `config/auth.php` additions + 3-4 lang strings + ~4-6 tests. Combined: ~150 LOC + ~10-14 tests. Reviewable in one sitting. | PASS |
| 4. Implementation order unambiguous | Independent. Either half could ship first. Chosen order (§5) is R-10 first because the DST work is harder to reason about with a full head and the reminder decision (D-071) is the riskier of the two. | NEUTRAL (weakly favours bundle) |

Two conditions fail; one passes; one neutral. **The split default would have us do R-10 and R-11 separately** — but ROADMAP-REVIEW deliberately bundles both as "operational correctness" for session efficiency, matching how it bundled R-5+R-6 (provider lifecycle + customer TZ) and R-4A+R-4B (concurrency guard). The prompt also instructs bundling. **Verdict: bundle**, honouring the roadmap's theme-based grouping. The two parts are *packaged together* but implemented as two orthogonal sub-rewrites with distinct decisions (§3.1 and §3.2). The diff size is well within one-sitting reviewability, and no implementation detail of one half constrains the other.

---

## 2. Goal and scope

### Goal

Close the two operational correctness gaps:

- **R-10**: `SendBookingReminders` computes eligibility using **business-timezone wall-clock** semantics (declared explicitly in D-071) and is resilient to delayed scheduler runs via **past-due eligibility** (replaces the ±5-minute tight window). Idempotency continues to flow through the `booking_reminders` unique constraint from D-056.
- **R-11**: `POST /magic-link` and `POST /forgot-password` are rate-limited with **separate per-IP and per-email buckets**, values configurable in `config/auth.php`, and reject-response UX matches the login throttle (validation error on the email field via `auth.throttle`-style translation) — declared in D-072.

### In scope — R-10

- **Decision D-071** — wall-clock-local eligibility semantics + past-due eligibility strategy, to be appended to `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md`.
- **Rewrite of `SendBookingReminders::handle()`** to:
  - Fetch confirmed, upcoming bookings within a horizon (`starts_at BETWEEN now AND now + max(reminder_hours) + safety buffer`) once, with `reminders` eager-loaded.
  - Per booking × per configured `hours_before`, compute `reminderTimeUtc = $booking->starts_at->setTimezone($business->timezone)->subHours($hoursBefore)->utc()`.
  - Eligibility: `reminderTimeUtc <= now && !alreadySentForThisHoursBefore`.
  - Claim the idempotency slot first (`BookingReminder::create`), catch `UniqueConstraintViolationException` as a race loss, then dispatch the notification.
- **New + updated tests** (§6) covering:
  - DST fall-back (Europe/Zurich, 2026-10-25 transition).
  - DST spring-forward (Europe/Zurich, 2026-03-29 transition, including gap-hour handling).
  - Delayed run: scheduler skipped for 30 min → reminder still fires on next run.
  - Delayed run, idempotent: repeated late run sends at most once per (booking, hours_before).
  - Past-due cutoff: appointment that has already started is NOT reminded (upper bound).
  - Confirmation that existing tests still pass (UTC happy path, cancelled-skip, dedup, per-business config).

### In scope — R-11

- **Decision D-072** — per-IP + per-email bucket throttle pattern, FormRequest-based enforcement, configurable via `config/auth.php`, to be appended to `docs/decisions/DECISIONS-AUTH.md`.
- **Two new Form Requests**:
  - `App\Http\Requests\Auth\SendMagicLinkRequest` (validates email, enforces throttle).
  - `App\Http\Requests\Auth\SendPasswordResetRequest` (validates email, enforces throttle).
  - Both implement `ensureIsNotRateLimited()` mirroring `LoginRequest` structure but **with two bucket checks** — one per-email, one per-IP — each independently triggering lockout.
- **Controller updates**:
  - `MagicLinkController::store(SendMagicLinkRequest $request)` — signature swap only. Validation + throttle move to the FormRequest.
  - `PasswordResetController::store(SendPasswordResetRequest $request)` — same pattern.
- **Config**: extend `config/auth.php` with a `throttle` subkey:
  - `auth.throttle.magic_link.max_per_email` (default 5), `max_per_ip` (default 20), `decay_minutes` (default 15).
  - `auth.throttle.password_reset.*` — same shape, same defaults.
- **Lang strings** — add `'Too many requests. Please try again in :minutes minute(s).'` to `lang/en.json` (used for both endpoints; specific enough to not reuse the login-specific `auth.throttle`).
- **Tests** — throttle behaviour per endpoint (§6.4).

### Out of scope

- **Booking reminder delivery channel changes.** Stays email-only via `BookingReminderNotification`. SMS / WhatsApp / push deferred per SPEC §9.
- **Moving the hardcoded `booking-api` / `booking-create` limiter values into config.** Adjacent to R-11 but a separate cleanup; captured in §10.
- **Customizing the business-specific reminder copy.** Out of scope; the message shape is unchanged.
- **Per-booking reminder overrides.** `reminder_hours` lives on Business per D-019; per-booking overrides are a product decision for post-MVP.
- **Rate-limiting the `GET` views for `/magic-link` and `/forgot-password`.** Those are read-only Inertia page renders; no email, no cost. Only the `POST` paths are throttled.
- **A CAPTCHA, proof-of-work, or any non-throttle anti-abuse.** Rate limiting is the declared scope; CAPTCHA is a separate decision.
- **Globally-unifying login, magic-link, and password-reset into one FormRequest pattern.** Not the scope. R-11 follows the login's shape but does not refactor the login itself.
- **Changes to `POST /login` throttle.** Existing login throttling (5/email|ip/minute implicit decay via Laravel RateLimiter defaults) stays as-is. ROADMAP-REVIEW §R-11 says the login already has throttling; we confirm and do not churn.
- **Invalidating existing magic-link tokens on throttle.** No — the throttle blocks *new* link emissions; any already-issued link continues to work until its 15-minute expiry.
- **Changes to `SendBookingReminders` scheduling cadence.** Stays `everyFiveMinutes()->withoutOverlapping()`. A longer cadence plus a wider look-back is equivalent, but correctness comes from the rewrite in §3.1, not the cadence.
- **Rewriting `BookingReminderNotification`.** The notification's `toMail` already handles timezone display correctly (`->setTimezone($business->timezone)`). Unchanged.
- **Dashboard UI for viewing reminder status / replay / manual trigger.** Post-MVP.
- **`X-RateLimit-*` headers on the throttled responses.** Not needed for the Inertia-form flows; Laravel's in-FormRequest approach doesn't set them. Noted for §10.

---

## 3. Approach

### 3.1 D-071 (new) — reminder eligibility semantics + delayed-run strategy

**File**: `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md` (reminder logic decisions; same home as D-019 and D-056).

**Proposed text:**

> ### D-071 — Reminder eligibility uses business-timezone wall-clock; delayed runs fire past-due with row-level idempotency
>
> - **Date**: 2026-04-16
> - **Status**: accepted
> - **Context**: `SendBookingReminders` pre-R-10 computed eligibility as `now()+hoursBefore ± 5 minutes`, with `now()` in UTC. Two silent-failure modes followed. First, across DST transitions, "24 hours before `starts_at`" in absolute UTC is not the same as "24 hours before the local-wall-clock appointment time"; for a 10:00 Europe/Zurich appointment the day after DST ends, the two interpretations differ by up to an hour, and for late-night appointments near a DST transition the difference can reach two hours (spring-forward gap). Second, the tight ±5-minute window dropped every eligible reminder whose window passed during any scheduler outage longer than five minutes (deploy, restart, `withoutOverlapping()` holding behind a slow prior run). The `booking_reminders` unique `(booking_id, hours_before)` constraint (D-056) prevents *duplicates* but cannot recover *misses* — once the window passes, the existing query never selects that booking for that interval again. REVIEW-1 §#11 flagged both modes as REGRESSION-PRONE.
> - **Decision**:
>   1. **Eligibility semantics — business-timezone wall-clock.** "N hours before" is computed as `starts_at` projected into the business's timezone, shifted by `N` wall-clock hours, then reprojected to UTC for the eligibility check. In code: `$reminderTimeUtc = $booking->starts_at->setTimezone($business->timezone)->subHours($hoursBefore)->utc()`. This matches the customer's mental model for an in-person appointment product (D-005 says the business timezone is the single source of truth; D-030 says slot calculation operates in business-local time). Across DST, the absolute UTC interval between the reminder and the appointment drifts by ±1 hour, and this is accepted explicitly — a customer with a 10 AM Monday appointment receiving a reminder "around 10 AM on Sunday" is correct regardless of whether the intervening Saturday crossed a DST boundary.
>   2. **Delayed-run strategy — past-due eligibility with row-level idempotency.** A reminder is eligible when `reminderTimeUtc <= now && starts_at > now && no booking_reminders row exists for (booking, hoursBefore)`. This is an *open* look-back window: no `N` to tune. A scheduler outage of any duration cannot drop an eligible reminder as long as the appointment itself has not yet started. The `starts_at > now` upper bound prevents sending a reminder *after* the appointment. Idempotency continues to flow through the `booking_reminders` unique `(booking_id, hours_before)` constraint (D-056), enforced at insert time before dispatching the notification — catching `UniqueConstraintViolationException` (Postgres `23505`) as a race loss.
>   3. **No watermark / no state beyond `booking_reminders`.** The watermark-based alternative was considered and rejected. `booking_reminders` already provides row-level idempotency at the correct granularity; a watermark would double-book the correctness invariant and re-introduce a tuning knob ("last-processed `starts_at` / `booking_id`") that drifts under concurrency.
>   4. **DST-gap and DST-overlap handling is deferred to Carbon's deterministic behaviour.** For the spring-forward gap (a wall-clock time that doesn't exist locally), Carbon rolls forward into the existing hour; for the fall-back overlap (a wall-clock time that occurs twice), Carbon picks the first occurrence (the earlier UTC instant). Both are documented, both are tested (§6.1.1 / §6.1.2), and both are explicitly judged acceptable — sending the reminder slightly earlier rather than slightly later matches the "better too early than missed" product bias.
>   5. **Per-booking cap for not-yet-configured reminder hours.** The inner loop still re-checks `in_array($hoursBefore, $business->reminder_hours ?? [])` to handle the outer hours-list being a union across all businesses — identical to the pre-R-10 code path, preserved for correctness.
>   6. **Scheduler cadence unchanged** — `everyFiveMinutes()->withoutOverlapping()` stays. Correctness lives in the eligibility math, not the cadence. The cadence controls *how often* catch-up opportunity arrives; correctness is independent.
> - **Consequences**:
>   - A scheduler outage of up to `hoursBefore` hours can still recover every eligible reminder on the next successful run. A 24-hour reminder that would have fired during a two-hour outage fires 23h58m before the appointment instead. A 1-hour reminder that would have fired during a 40-minute outage fires 20 minutes before.
>   - A reminder is never sent after the appointment has started, even if the scheduler was down for longer than `hoursBefore`. The booking simply doesn't get that reminder — accepted as the safer failure mode than "reminder arrives in the middle of the appointment."
>   - The unique constraint does double duty: deduplication across concurrent runs, and the race-safe-slot-claim for past-due eligibility.
>   - Display in `BookingReminderNotification::toMail()` is already wall-clock-local via `->setTimezone($business->timezone)` (`BookingReminderNotification.php:33`). Post-R-10, eligibility + display share the same timezone semantics. No change to the notification.
>   - Future extensions (SMS, per-booking override, different `reminder_hours` granularity) inherit the same eligibility shape.
>   - The performance horizon `whereBetween('starts_at', [$now, $now + max(reminder_hours) + buffer])` keeps the candidate set bounded — a business with `reminder_hours = [168]` (a week) does mean a week's worth of upcoming bookings per run, but that is bounded in practice and still much smaller than the full bookings table.
> - **Rejected alternatives**:
>   - *Absolute-UTC semantics.* Simpler in code, but wrong for the product: customer expectation in an in-person-appointment product is wall-clock. Also inconsistent with D-005 and D-030, which already treat business timezone as authoritative.
>   - *Look-back window with a tuning knob (`N` minutes back + `M` minutes forward).* Trades one arbitrary value (±5 min) for another. Any `N` silently drops reminders past an outage of `N` minutes; the past-due design has no knob to tune.
>   - *Watermark (last-processed `(hours_before, starts_at)` cursor).* Extra state, per-hours-before, with concurrency implications. The `booking_reminders` table already gives row-level idempotency; a watermark is strictly more complicated for no added correctness.
>   - *Moving eligibility check to the queue worker instead of the scheduled command.* Would re-centre the correctness problem on queue delay; queue delay is a different distribution than scheduler delay. Keeping the eligibility check in the command preserves clear ownership.

### 3.1.1 Decision matrix — R-10 option space

| Option | Correctness under DST | Correctness under delayed run | Implementation complexity | Test surface | Observability | Idempotency guarantees | Verdict |
| --- | --- | --- | --- | --- | --- | --- | --- |
| **Wall-clock local + past-due eligibility + row-level idempotency (CHOSEN)** | ✓ Matches customer mental model; ±1 hour drift across DST is accepted and tested. Spring-forward gap and fall-back overlap both handled deterministically by Carbon. | ✓ Open look-back; an outage of any duration < `hoursBefore` recovers on next run. | Low-medium: ~50 LOC rewrite in `handle()`, ~5-8 new tests with explicit DST fixtures. | Concrete DST tests (both directions), delayed-run tests, past-due-cutoff test, idempotency test. | Command output already prints "Sent N reminders"; add no new instrumentation. Logs from `BookingReminderNotification`'s queue worker inherit. | Row-level via `booking_reminders` unique `(booking_id, hours_before)`; claim-first-then-dispatch + `UniqueConstraintViolationException` catch closes the race window. | **CHOSEN.** |
| Absolute UTC + past-due eligibility | ✓ Past-due recovery same as chosen. ✗ DST drift causes customer-visible "reminder at 9 AM instead of 10 AM" in October; wrong semantic. | ✓ Same. | Slightly simpler (no `setTimezone` call) but wrong direction. | Smaller; no DST tests needed — and that's the problem. | Same. | Same. | Rejected — fails the customer-expectation column. |
| Wall-clock local + look-back window (`[now-N, now+M]`) | ✓ DST correct same as chosen. | ~ Catches an outage ≤ N; drops past-N. Tuning knob that wants to grow. | Similar LOC. | DST tests + N-boundary tests. | Same. | Same. | Rejected — knob-tuning is the original bug in a different shape. |
| Wall-clock local + watermark per `(business, hours_before)` | ✓ DST correct. | ✓ As long as watermark advances cleanly. | Medium-high: new column or table, watermark advancement, race handling across concurrent runs. | DST tests + watermark-concurrency tests (new surface). | Watermark state is queryable (slight win). | Watermark + row-level idempotency is double-guarded — but row-level already suffices. | Rejected — double-guarded for no added correctness; adds surface area and concurrency risk. |
| Wall-clock local + hybrid (watermark + look-back) | ✓ DST correct. | ✓ Most robust in pathological failure modes. | Medium-high: watermark + tuning knob. | Highest. | Best observability (watermark visible). | Triple-guarded. | Rejected — over-engineered for the failure budget. |

**Recommendation: Wall-clock local + past-due eligibility + row-level idempotency.** The column where every other option falls down is either "customer semantics" (absolute UTC) or "tuning knob that wants to grow" (look-back). The chosen option has neither.

### 3.1.2 R-10 code sketch

```php
public function handle(): int
{
    $now = CarbonImmutable::now('UTC');

    $allReminderHours = Business::whereNotNull('reminder_hours')
        ->pluck('reminder_hours')
        ->flatten()
        ->unique()
        ->filter(fn ($h) => $h > 0)
        ->values();

    if ($allReminderHours->isEmpty()) {
        $this->info('No businesses have reminder hours configured.');
        return self::SUCCESS;
    }

    // Bound the candidate set: a booking whose starts_at is further out than
    // the largest configured hours_before cannot be reminder-eligible yet.
    $horizonEnd = $now->addHours((int) $allReminderHours->max() + 1);

    $candidates = Booking::where('status', BookingStatus::Confirmed)
        ->whereBetween('starts_at', [$now, $horizonEnd])
        ->with(['business', 'service', 'provider.user', 'customer', 'reminders'])
        ->get();

    $totalSent = 0;

    foreach ($candidates as $booking) {
        $tz = $booking->business->timezone;
        $reminderHours = $booking->business->reminder_hours ?? [];

        foreach ($reminderHours as $hoursBefore) {
            $hoursBefore = (int) $hoursBefore;
            if ($hoursBefore <= 0) {
                continue;
            }

            // Wall-clock local semantics (D-071 decision 1):
            // subHours in business TZ, then back to UTC for the eligibility check.
            $reminderTimeUtc = $booking->starts_at
                ->setTimezone($tz)
                ->subHours($hoursBefore)
                ->utc();

            // Past-due eligibility (D-071 decision 2).
            if ($reminderTimeUtc->greaterThan($now)) {
                continue;
            }

            // Already sent (cheap in-memory check from eager-loaded relation).
            if ($booking->reminders->contains('hours_before', $hoursBefore)) {
                continue;
            }

            // Claim the idempotency slot first; catch race loss.
            try {
                BookingReminder::create([
                    'booking_id' => $booking->id,
                    'hours_before' => $hoursBefore,
                    'sent_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            Notification::route('mail', $booking->customer->email)
                ->notify(new BookingReminderNotification($booking, $hoursBefore));

            $totalSent++;
        }
    }

    $this->info("Sent {$totalSent} reminder(s).");
    return self::SUCCESS;
}
```

Notes on the sketch (not the final diff):

- `booking->reminders` is eager-loaded, so the `contains` check is in-memory — no per-iteration SELECT. A fresh `BookingReminder::create` inside the same iteration does NOT update the eager-loaded collection in place, but we continue inside the `foreach ($reminderHours as $hoursBefore)` loop and the `UniqueConstraintViolationException` catch is the backstop; the in-memory check is a cheap first filter, not the authoritative one.
- `UniqueConstraintViolationException` is namespaced `Illuminate\Database\UniqueConstraintViolationException`; available Laravel 11+.
- `CarbonImmutable::now('UTC')` is explicit; avoids relying on the Laravel default timezone stays UTC.

### 3.2 D-072 (new) — auth-recovery throttle policy

**File**: `docs/decisions/DECISIONS-AUTH.md` (auth boundaries, roles, invitations; same home as D-006 and D-037).

**Proposed text:**

> ### D-072 — Auth-recovery POSTs are throttled per-email AND per-IP via FormRequest, configurable in `config/auth.php`
>
> - **Date**: 2026-04-16
> - **Status**: accepted
> - **Context**: Pre-R-11, `POST /magic-link` and `POST /forgot-password` had no rate limiting. Both send an email on every call and both return a generic success response to resist user enumeration (D-037 preserves the magic-link enumeration hardening). That combination means every request has a delivery cost (mailer invocation, queued job) with no defensive cap — trivially abusable for email spam and noisy account probing. Login (`POST /login`) was already throttled, but via an in-`LoginRequest` pattern keyed on `strtolower(email).'|'.ip()` with a 5-attempt cap (`LoginRequest::throttleKey()`), not via route middleware. Two precedents for throttling exist in the codebase: the LoginRequest FormRequest pattern, and the route middleware `throttle:` pattern (`POST /email/verification-notification` uses `throttle:6,1`, and `RateLimiter::for('booking-api')` / `booking-create` are registered in `AppServiceProvider::boot()` for the public booking flow).
> - **Decision**:
>   1. **Pattern — FormRequest-internal, matching LoginRequest.** Two new FormRequests, `SendMagicLinkRequest` and `SendPasswordResetRequest`, each with an `ensureIsNotRateLimited()` hook called early in the controller's `store()` method. This extends the existing auth-throttle precedent rather than introducing a third pattern (middleware `throttle:` + `RateLimiter::for` + FormRequest-internal would be three distinct patterns for three auth-adjacent endpoints; we keep it at two by choosing FormRequest-internal for the auth-recovery endpoints).
>   2. **Segmentation — independent per-email and per-IP buckets.** Each request checks both `RateLimiter::tooManyAttempts($perEmailKey, $maxPerEmail)` AND `RateLimiter::tooManyAttempts($perIpKey, $maxPerIp)`. EITHER bucket exceeding the limit throws lockout. This closes both attack axes that a combined `email|ip` key leaves open: IP-rotating attackers can't flood one email (per-email bucket caps), and email-rotating attackers can't flood from one IP (per-IP bucket caps). The two buckets are orthogonal and additive — a legitimate user at a shared office NAT hitting the per-IP bucket with unrelated emails is accepted as a tradeoff.
>   3. **Values — configurable via `config/auth.php`.** Defaults: per-email 5 attempts, per-IP 20 attempts, 15-minute decay. Rationale: 5/email/15min matches ROADMAP-REVIEW §R-11's suggestion and is permissive enough for legitimate retries (user typos + retry + check spam + retry) while tight enough that volume abuse becomes uneconomic. Per-IP 20 is four times the per-email limit — allowing a shared-IP customer-facing office to cover several different distinct users within the decay window. Values live in `config/auth.php` under `auth.throttle.{magic_link|password_reset}.{max_per_email,max_per_ip,decay_minutes}`, readable via environment variables (`THROTTLE_MAGIC_LINK_EMAIL`, etc.) so ops can tune post-launch without a deploy.
>   4. **Keys** — deterministic, scoped, and independent across endpoints:
>      - `magic-link:email:{strtolower(email)}`
>      - `magic-link:ip:{ip}`
>      - `password-reset:email:{strtolower(email)}`
>      - `password-reset:ip:{ip}`
>      Namespace prefix prevents the two endpoints from sharing counters (a burst against `/magic-link` does not also lock out `/forgot-password` and vice versa).
>   5. **Increment semantics — always hit on invocation.** Unlike login's pattern (`RateLimiter::hit` only on failed auth + `RateLimiter::clear` on success), auth-recovery throttles hit unconditionally. The recovery endpoints always return generic success to resist enumeration (D-037); there's no "success" signal to clear on, and the limit is about volume, not failure.
>   6. **Response shape** — on lockout, throw `ValidationException::withMessages(['email' => __('throttle.too_many_requests', ['minutes' => ceil($seconds / 60)])])`. A new translation key `throttle.too_many_requests` is added (not reusing `auth.throttle`, which hardcodes "Too many login attempts"). The response is Inertia-native 302-back with a validation error, identical to LoginRequest's UX. No separate `Retry-After` header is set (the translated message carries the duration).
>   7. **Lockout event** — do not emit `Illuminate\Auth\Events\Lockout` for these endpoints. `Lockout` is semantically an authentication-lockout event; auth-recovery throttle is an abuse-prevention cap, not an auth lockout.
>   8. **Applies to** — only `POST /magic-link` and `POST /forgot-password`. The `GET` routes for both endpoints are untouched (they render static Inertia pages, no email, no cost). The `GET /reset-password/{token}` and `POST /reset-password` (password update with token) are untouched — per-token signed URLs are the abuse guard for those.
> - **Consequences**:
>   - An abusive actor cannot use either endpoint as an email-bomb delivery mechanism: at 5 requests per email per 15 minutes, a burst attempt sends 5 mails and then silently rate-limits for 15 minutes.
>   - Legitimate users hitting the per-email bucket (typo + retry loop) see a familiar-looking validation error on the email field with a minute count — no new UI surface to learn.
>   - Legitimate users on shared IP (office NAT, mobile carrier NAT) hit the per-IP bucket last; the 20-request window covers common cases. When they do hit it, the same validation error pattern fires.
>   - Cache driver dependency: Laravel's `RateLimiter` uses the default cache store; the app runs on `database` in prod and `array` in tests (already verified, no change needed).
>   - The two new FormRequest files mirror `LoginRequest` in shape, making future auth-throttle additions a copy-paste (while noting that the roadmap does NOT currently demand a third such endpoint).
>   - Config-driven values let ops tune without redeploying. Post-launch, if abuse telemetry shows 5/email is too tight, the env variable flip is immediate.
> - **Rejected alternatives**:
>   - *Route middleware `throttle:<limiter-name>` with a named `RateLimiter::for`.* Works and is tidier at the route level; rejected because it diverges from the login UX (Laravel's default `ThrottleRequests` returns a 429 JSON for JSON requests, a 429 HTML page otherwise — Inertia surfaces this as a dialog-error rather than a field-level validation error). Extending LoginRequest's pattern keeps all auth-endpoint throttle UX identical.
>   - *Single combined `email|ip` bucket.* What login uses; rejected here because auth recovery's attack surface is different. Login's key works because login's success path clears the counter — one legitimate success resets the state. Auth-recovery has no success signal to clear on (generic success response resists enumeration), so a combined key means an attacker rotating either axis slips through.
>   - *Only per-IP.* Rejected — caps volume but not targeted user probing.
>   - *Only per-email.* Rejected — caps targeted probing but not aggregate volume from a rotating-email attacker.
>   - *Putting values in a new `config/throttle.php`.* Adjacent and reasonable, but pre-existing rate-limiter registrations (`booking-api`, `booking-create`) live as hardcoded values in `AppServiceProvider`. A new config file just for two new keys creates a second convention. `config/auth.php` is auth-themed and already loaded — cleaner for now. Future consolidation into `config/throttle.php` is captured in §10.
>   - *Emitting `Illuminate\Auth\Events\Lockout`.* The event listener surface is for auth-lockout reactions (logging, user notification, admin alert). Rate-limit-exceeded on a magic-link request isn't an auth lockout in the domain sense; emitting the event would add noise.
>   - *Returning 429 with a friendly page.* Would require a new error template / Inertia page. Validation-error shape reuses the existing auth form rendering.

### 3.2.1 Decision matrix — R-11 option space

| Option | Abuse resistance (volume) | User-enumeration resistance | User friction under legitimate retry | Configurability | Parity with existing login throttle | Verdict |
| --- | --- | --- | --- | --- | --- | --- |
| **FormRequest-internal, per-email AND per-IP buckets, values in `config/auth.php` (CHOSEN)** | High. Volume capped per IP (20/15min); targeted probing capped per email (5/15min). Two orthogonal axes close both attack shapes. | High. Generic success response preserved (D-037); throttle triggers identical validation error regardless of whether email exists. | Moderate. Legitimate retries within 15 min are capped at 5/email; a copy says minutes-until-retry. | High. Env-tunable via `THROTTLE_MAGIC_LINK_*` / `THROTTLE_PASSWORD_RESET_*`. | High. Mirrors `LoginRequest::ensureIsNotRateLimited()` structure and `ValidationException::withMessages(['email' => ...])` shape. | **CHOSEN.** |
| Route middleware `throttle:<name>` with `RateLimiter::for(...)` returning two `Limit` objects (one per IP, one per email) | High. Same resistance shape as chosen. | High. | Moderate. | Medium. Limiter callback reads `config('auth.throttle.*')`, but the middleware is declarative on the route. | Medium. Different response shape (Laravel default 429 JSON or HTML error page). Inertia surface for a 429 is less familiar. | Rejected — UX divergence from login. |
| In-FormRequest single bucket keyed `email|ip` (match login key shape exactly) | Medium. An attacker rotating either axis slips through. | High. | Low. | Medium. | High (exact key-shape match) but wrong for the endpoint. | Rejected — login's success-clears semantics don't apply; single key is weaker here. |
| Per-IP only, 20/min | Medium. Volume capped but targeted probing uncapped. | Medium. | Low. | Low. | Medium. | Rejected — misses targeted probing. |
| Per-email only, 5/15min | Medium. Targeted probing capped but volume from rotating emails uncapped. | Medium. | Low. | Low. | Medium. | Rejected — misses volume. |

**Recommendation: FormRequest-internal, per-email AND per-IP buckets, values in `config/auth.php`.** Matches login's UX, closes both attack shapes, configurable for post-launch tuning.

### 3.2.2 R-11 code sketch

`app/Http/Requests/Auth/SendMagicLinkRequest.php` (new):

```php
<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SendMagicLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
        ];
    }

    public function ensureIsNotRateLimited(): void
    {
        $config = config('auth.throttle.magic_link');

        $this->hitOrLockout(
            $this->emailKey(),
            (int) $config['max_per_email'],
            (int) $config['decay_minutes'],
        );

        $this->hitOrLockout(
            $this->ipKey(),
            (int) $config['max_per_ip'],
            (int) $config['decay_minutes'],
        );
    }

    private function hitOrLockout(string $key, int $maxAttempts, int $decayMinutes): void
    {
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => __('Too many requests. Please try again in :minutes minute(s).', [
                    'minutes' => (int) ceil($seconds / 60),
                ]),
            ]);
        }

        RateLimiter::hit($key, $decayMinutes * 60);
    }

    private function emailKey(): string
    {
        return 'magic-link:email:'.Str::lower((string) $this->input('email'));
    }

    private function ipKey(): string
    {
        return 'magic-link:ip:'.$this->ip();
    }
}
```

`SendPasswordResetRequest` — same shape, prefix keys with `password-reset:`, read `config('auth.throttle.password_reset')`.

`MagicLinkController::store` — swap type-hint from `Request` to `SendMagicLinkRequest` and call `$request->ensureIsNotRateLimited()` at the top. The rest of the method body is unchanged.

`config/auth.php` additions:

```php
'throttle' => [
    'magic_link' => [
        'max_per_email' => (int) env('THROTTLE_MAGIC_LINK_EMAIL', 5),
        'max_per_ip' => (int) env('THROTTLE_MAGIC_LINK_IP', 20),
        'decay_minutes' => (int) env('THROTTLE_MAGIC_LINK_DECAY', 15),
    ],
    'password_reset' => [
        'max_per_email' => (int) env('THROTTLE_PASSWORD_RESET_EMAIL', 5),
        'max_per_ip' => (int) env('THROTTLE_PASSWORD_RESET_IP', 20),
        'decay_minutes' => (int) env('THROTTLE_PASSWORD_RESET_DECAY', 15),
    ],
],
```

`lang/en.json` additions: `"Too many requests. Please try again in :minutes minute(s).": "Too many requests. Please try again in :minutes minute(s)."`

### 3.3 Test strategy — R-10 DST edges

Use `CarbonImmutable::parse(...)->utc()` for absolute UTC assertions, `CarbonImmutable::parse(..., 'Europe/Zurich')->utc()` for wall-clock local assertions. Travel via `$this->travelTo(...)`.

- **3.3.1 Fall-back (autumn) edge** — `Europe/Zurich` DST ends on the last Sunday of October. In 2026 that's **2026-10-25**, at 03:00 local the clocks roll back to 02:00 (UTC offset changes from +2 to +1). Fixture:
  - Business with `timezone = 'Europe/Zurich'`, `reminder_hours = [24]`.
  - Booking with `starts_at = 2026-10-26 09:00 UTC` (= 10:00 local CET, the day after DST ends).
  - Travel to `now = 2026-10-25 09:00 UTC` (= 11:00 local CEST, still in DST, reminder time expected).
  - **Wall-clock 24h before** `10:00 local on 2026-10-26` = `10:00 local on 2026-10-25` = `08:00 UTC` (CEST). `reminderTimeUtc = 2026-10-25 08:00 UTC`. At `now = 09:00 UTC`, that's past-due → send.
  - **Absolute 24 UTC hours before** would be `09:00 UTC` on 2026-10-25 (the boundary case — exactly now). The chosen wall-clock semantics sent it one hour earlier; the absolute-UTC reading would send it at now. Both send, but the two interpretations differ. The test asserts the wall-clock behaviour.
- **3.3.2 Spring-forward edge** — `Europe/Zurich` DST starts on the last Sunday of March. In 2026 that's **2026-03-29**, at 02:00 local the clocks leap to 03:00 (UTC offset changes from +1 to +2). Fixture:
  - Business with `timezone = 'Europe/Zurich'`, `reminder_hours = [24]`.
  - Booking with `starts_at = 2026-03-30 08:00 UTC` (= 10:00 local CEST).
  - Travel to `now = 2026-03-29 08:00 UTC` (= 10:00 local CEST, just post-DST-start).
  - **Wall-clock 24h before** `10:00 local on 2026-03-30` = `10:00 local on 2026-03-29` = `08:00 UTC` (CEST). `reminderTimeUtc = 2026-03-29 08:00 UTC` = exactly now → past-due → send.
  - **Spring-forward gap sub-case**: a booking with `starts_at = 2026-03-30 00:30 UTC` (= 02:30 local CEST). `->setTimezone('Europe/Zurich')->subHours(24)` lands in the wall-clock gap of 2026-03-29 (02:00-03:00 local doesn't exist). Carbon's deterministic behaviour is to roll forward to the corresponding post-transition time. The test asserts (1) the result is not `null`, (2) it's sent when expected, and (3) no exception is thrown.
- **3.3.3 Delayed run** — fix `now = 2026-04-13 10:30 UTC`. Create a booking `starts_at = 2026-04-14 10:00 UTC`, `reminder_hours = [24]`. The reminder time was `2026-04-13 10:00 UTC` — 30 minutes past. Old code (±5min window) → no send. New code → send. Assert `BookingReminder::count() == 1` and `Notification::assertSentOnDemand`.
- **3.3.4 Delayed-run idempotent** — same fixture as 3.3.3, run the command twice. Assert `BookingReminder::count() == 1` after both runs and `Notification::assertSentOnDemand` once.
- **3.3.5 Past-appointment cutoff** — booking `starts_at = 2026-04-13 09:00 UTC` (1 hour in the past), `reminder_hours = [1]`. Travel to `now = 2026-04-13 10:00 UTC`. `starts_at < now` → NOT in candidate window → no send. Assert `Notification::assertNothingSent`.
- **3.3.6 Retained coverage** — the four pre-existing tests in `tests/Feature/Commands/SendBookingRemindersTest.php` stay (happy path, already-sent-skip, per-business-config, cancelled-skip) and are updated only where the exact time travel wants adjustment to the new semantics (the current fixtures pass unchanged — verified by mental execution).

### 3.4 Test strategy — R-11 rate-limit windows

`CACHE_STORE=array` (phpunit.xml) means rate-limiter state is per-test, reset via `setUp`. The FormRequest pattern is testable via `post()` calls end-to-end; no need to construct the FormRequest directly.

- **3.4.1 Magic-link, per-email bucket**
  - Post to `/magic-link` 5 times with the same email → last one still returns success redirect.
  - The 6th post returns 302 with validation error on `email` matching the translated throttle message.
  - Assert `Notification::assertSentTimes(MagicLinkNotification::class, 5)` or `assertSentOnDemand` shape.
- **3.4.2 Magic-link, per-IP bucket**
  - Post 20 times with rotating email addresses (`alice1@`, `alice2@`, …) from the same IP.
  - The 21st post — any email — returns throttle error.
  - Asserts the per-IP cap independently of per-email.
- **3.4.3 Magic-link, per-IP bucket is orthogonal to per-email**
  - Post 4 times with `alice@` (per-email 4/5, per-ip 4/20).
  - Post 1 time with `bob@` from same IP (per-email 1/5, per-ip 5/20) — success.
  - Assert both limiters decrement correctly.
- **3.4.4 Password-reset mirrors magic-link**
  - Same three tests against `/forgot-password`. Parametrise via dataset(`[['magic-link'], ['forgot-password']]`) or copy-paste — the prompt permits either. Preference: dataset to cut LOC.
- **3.4.5 Throttle keys are endpoint-scoped**
  - Exhaust `magic-link:email:alice@example.com` (5 posts).
  - Immediately post to `/forgot-password` with `alice@example.com` — success (different key namespace).
- **3.4.6 Decay frees the bucket**
  - Exhaust per-email; travel forward 16 minutes (`$this->travel(16)->minutes()`).
  - Post again — success (decay complete).
- **3.4.7 (Smoke) existing Magic-link tests still pass**
  - If any existing tests exist for `MagicLinkController::store` or `PasswordResetController::store`, they must continue to pass with the new FormRequest in place. Quick grep during implementation confirms whether any exist; if yes, adjust type-hints or any `$request` usage.

Time manipulation uses `Carbon::setTestNow(...)` / `$this->travel(...)`. The rate-limiter's `availableIn()` depends on the cache's TTL, which in-array is calculated from `RateLimiter::hit(..., $decayMinutes * 60)`. Traveling time works as long as we use `$this->travel()` (which also advances `Carbon::now()` + cache TTL considerations — for `array` cache, yes).

---

## 4. New decisions

- **D-071 — Reminder eligibility uses business-timezone wall-clock; delayed runs fire past-due with row-level idempotency.** Full text in §3.1. Target file: `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md`.
- **D-072 — Auth-recovery POSTs are throttled per-email AND per-IP via FormRequest, configurable in `config/auth.php`.** Full text in §3.2. Target file: `docs/decisions/DECISIONS-AUTH.md`.

No other decisions. D-071 and D-072 are independent and land in different files.

---

## 5. Implementation order

Each step leaves `php artisan test --compact` green.

1. **Append D-071 to `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md` and D-072 to `docs/decisions/DECISIONS-AUTH.md`.** Purely documentation; tests unaffected. These land first so the code changes can reference concrete decision numbers in commit messages / review comments.
2. **Rewrite `SendBookingReminders::handle()`** per §3.1.2. Run `php artisan test --compact --filter=SendBookingReminders` — existing 4 tests must still pass. No new tests yet.
3. **Add R-10 tests** per §3.3. Run filtered tests; expect 9 total (4 existing + 5 new). Fix any drift between the new semantics and the existing fixtures (expected to be zero — the existing fixtures use mid-April timestamps with no DST transition in play).
4. **Add `config/auth.php` `throttle.*` subtree** per §3.2.2. No logic changes yet; `config('auth.throttle')` returns the default shape. No test impact.
5. **Add `lang/en.json` key** `"Too many requests. Please try again in :minutes minute(s)."`. No test impact.
6. **Create `SendMagicLinkRequest` and `SendPasswordResetRequest`** per §3.2.2. New files; no wiring yet. `php artisan test --compact` still passes.
7. **Wire FormRequests into the two controllers.** Swap `Request` to the new FormRequest type-hints in `MagicLinkController::store` and `PasswordResetController::store`, call `$request->ensureIsNotRateLimited()` early in each method, drop the in-method `$request->validate(['email' => [...]])` (moved to FormRequest). Run existing auth tests (grep for `MagicLinkController\|PasswordResetController` in `tests/`) — expected to still pass; the FormRequest re-does the same validation rule, only the location changes.
8. **Add R-11 tests** per §3.4. Run `php artisan test --compact --filter='(MagicLink|PasswordReset|Throttle)'`. Expect green.
9. **Full suite green** — `php artisan test --compact`. Pint: `vendor/bin/pint --dirty --format agent`. Frontend build unaffected (no FE changes); `npm run build` as a safety check.
10. **HANDOFF rewrite** — `docs/HANDOFF.md` overwritten (not appended) to describe R-10 + R-11 state, new test count (472 + ~10 = ~482, subject to exact final count).
11. **Roadmap tick** — update `docs/reviews/ROADMAP-REVIEW.md` (if that file has a status-tracker format; current file format is prose descriptions — check and add a status line only if that's the established convention per prior R-sessions).
12. **Archive the plan** — move `docs/plans/PLAN-R-10-11-OPS-CORRECTNESS.md` to `docs/archive/plans/`.

---

## 6. Verification

### 6.1 Existing-test audit

- **Reminder tests** — `tests/Feature/Commands/SendBookingRemindersTest.php` has 4 tests. Expected state after R-10: all 4 continue to pass (fixtures use mid-April, no DST edge; the new semantics degrade gracefully to the happy path for these fixtures).
- **Notification tests** — `tests/Feature/Notifications/BookingReminderNotificationTest.php` has 1 test (subject contains business name). Unchanged — notification is untouched.
- **Auth tests** — check for any `tests/Feature/Auth/MagicLink*` or `tests/Feature/Auth/PasswordReset*` that exercise the endpoints. Grep during implementation; expected minimal changes (type-hint swap only).

### 6.2 New tests

- R-10: 5 new tests (§3.3.1–§3.3.5).
- R-11: 6–7 new tests (§3.4.1–§3.4.6, plus any smoke; dataset-reduction may collapse magic-link + password-reset to parametrized pairs).

Expected delta: **+11 to +13 tests**, taking the suite from 472 to ~483–485.

### 6.3 What IS and ISN'T automatable

**Automatable** (covered by the new tests):

- Wall-clock semantics across DST fall-back (Europe/Zurich 2026-10-25).
- Wall-clock semantics across DST spring-forward (Europe/Zurich 2026-03-29).
- Spring-forward gap-hour resolution (Carbon's roll-forward behaviour).
- Delayed-run past-due eligibility.
- Delayed-run idempotency (the same booking is reminded at most once).
- Past-appointment cutoff (no reminder for an appointment in the past).
- Per-email throttle on both auth-recovery endpoints.
- Per-IP throttle on both auth-recovery endpoints.
- Cross-endpoint key isolation.
- Decay-window refresh.

**Not automatable** (relies on infra / human verification):

- Actual scheduler outage behaviour in production (the test simulates via `travelTo`; the production `withoutOverlapping()` + `everyFiveMinutes()` interaction is not end-to-end tested).
- Real mail delivery / bounce behaviour under abuse conditions (we test that the notification is dispatched the right number of times; we don't test the mailer).
- Fall-back-overlap-hour wall-clock ambiguity in practice (the test picks one side; whether Carbon's choice is still the right one in a hypothetical customer-facing audit is a product judgement, not a test outcome).

### 6.4 Expected test count delta

Pre-R-10-11: 472 passing (per R-9 HANDOFF line 113-114).

Post-R-10-11: ~483–485 passing. Exact count depends on whether R-11 tests use a dataset (fewer test cases, same coverage) or duplicate per endpoint.

---

## 7. Files to create / modify / delete

### Create

- `app/Http/Requests/Auth/SendMagicLinkRequest.php` — new FormRequest with `ensureIsNotRateLimited()`.
- `app/Http/Requests/Auth/SendPasswordResetRequest.php` — new FormRequest with `ensureIsNotRateLimited()`.
- Optional: `tests/Feature/Auth/MagicLinkThrottleTest.php` and `tests/Feature/Auth/PasswordResetThrottleTest.php`, or a shared `tests/Feature/Auth/AuthRecoveryThrottleTest.php` with a dataset.
- Optional: `tests/Feature/Commands/SendBookingRemindersDSTTest.php` for the new DST-specific tests (or extend existing `SendBookingRemindersTest.php`; preference: one file for cohesion, adding new tests at the bottom).

### Modify

- `app/Console/Commands/SendBookingReminders.php` — rewrite `handle()` per §3.1.2.
- `app/Http/Controllers/Auth/MagicLinkController.php` — swap type-hint + move validation call (minor; ~3 lines).
- `app/Http/Controllers/Auth/PasswordResetController.php` — same.
- `config/auth.php` — add `throttle.*` subtree (§3.2.2).
- `lang/en.json` — add the throttle message key.
- `tests/Feature/Commands/SendBookingRemindersTest.php` — append new DST and delayed-run tests; existing tests unchanged.
- `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md` — append D-071.
- `docs/decisions/DECISIONS-AUTH.md` — append D-072.
- `docs/HANDOFF.md` — full rewrite (per Session Done Checklist).
- `docs/reviews/ROADMAP-REVIEW.md` — mark §R-10 and §R-11 complete if the file uses a status-marker convention; else leave prose unchanged (verify during implementation).

### Delete

- None.

---

## 8. Risks & mitigations

### 8.1 DST-boundary fixture volatility

**Risk**: The DST transition dates in Europe/Zurich for 2026 (2026-03-29 and 2026-10-25) are calendar-year-specific. A test fixture that drifts to `now()->year` without updating the dates will silently become a not-a-DST-test in 2027.

**Mitigation**: Hardcode the explicit timestamps (`'2026-10-26 09:00'`, `'2026-03-30 08:00'`) in the test fixtures. Add a comment in the test docblock naming the transition date and the EU-DST rule ("last Sunday of October"). When the calendar year rolls over, the tests still pin the same transition — the dates are stable, the logic they exercise is stable, and the test continues to prove the same property.

### 8.2 Scheduler-delayed-beyond-max-`hours_before`

**Risk**: With the past-due design, a reminder is only eligible while `starts_at > now`. If the scheduler is down for longer than `max(hours_before)`, an appointment can pass without any reminder being sent (not even a last-minute one). For a business configured with `reminder_hours = [1]` and a 2-hour scheduler outage, every appointment that started during the outage missed its reminder forever.

**Mitigation**: This is accepted (D-071 consequence bullet). The alternative — sending a "1-hour reminder" 10 minutes AFTER the appointment started — is worse UX. If ops detects an outage greater than an hour, the runbook should include a manual `php artisan bookings:send-reminders` invocation immediately on recovery; the past-due eligibility will catch everything that is still recoverable. A monitoring alert on `schedule` command lag is a post-launch ops concern, captured in §10.

### 8.3 Rate-limit lockout for legitimate users on shared IPs

**Risk**: Office NAT, mobile carrier NAT, CG-NAT can produce many legitimate users from a single IP. 20 requests per 15 minutes per IP on either endpoint may lock out a shared-space user.

**Mitigation**: The per-IP cap of 20 is deliberately 4× the per-email cap of 5, specifically to accommodate shared-IP scenarios with different email addresses. For the two auth-recovery endpoints combined, that's 40 requests per IP per 15 minutes (they're in independent namespaces). The validation-error UX gives the user a minute count and a clear indication to wait; there's no account-level lockout side-effect. If post-launch telemetry shows shared-IP lockout causing support load, the per-IP cap is env-tunable (no redeploy).

### 8.4 Test flakiness from time manipulation

**Risk**: `CarbonImmutable::now()` caching + `Carbon::setTestNow()` + the `array` cache driver's TTL calculation interacting unpredictably.

**Mitigation**: Use `$this->travelTo()` consistently (Pest/Laravel's canonical) rather than raw `Carbon::setTestNow()`. Array cache's TTL is read as `time() + $ttl` at insert time — traveling after insert does not retroactively reset the expiry. For the decay test (§3.4.6), travel AFTER all hits, then re-issue a hit; the `array` driver's `tooManyAttempts` uses `Carbon::now()`-relative checks, which travel correctly. Confirmed via the existing test patterns in `tests/Feature/Booking/*Test.php` that use `travelTo` successfully.

### 8.5 Cache driver dependency

**Risk**: Laravel's `RateLimiter` uses the default cache store. In production that's `database` (per `.env`), in tests it's `array` (per `phpunit.xml`). If a future change switches either to a misconfigured store, the rate limiter fails silently (no state persisted → limits never triggered).

**Mitigation**: No code change in R-11; the dependency exists for the already-working login throttle and for `booking-api`/`booking-create`. Add a one-line smoke test in the throttle test file that verifies `config('cache.default')` is not `null` and is a writable driver — cheap belt-and-braces against future misconfiguration. Captured in §10 as a potential broader "test env sanity" check.

### 8.6 `UniqueConstraintViolationException` behaviour under concurrent scheduler runs

**Risk**: The R-10 rewrite claims idempotency via insert + catch `UniqueConstraintViolationException`. If two runs race (possible if `withoutOverlapping()` fails or is removed), both attempt the insert; one wins, one catches. The catching run must not double-dispatch.

**Mitigation**: The sketch in §3.1.2 `continue`s on catch, before the `Notification::route(...)->notify(...)` line. Verified by reading the code position. A test to exercise this would need two concurrent processes — not practically automatable in the Pest suite; rely on the `booking_reminders` unique constraint's Postgres-level guarantee and the code's control-flow ordering. Noted as "not automatable" in §6.3.

### 8.7 Magic-link legitimate customer-register-via-first-link locked out

**Risk**: `MagicLinkController::resolveUser()` auto-creates a User for a Customer on first magic-link request. An attacker preconsuming the per-email bucket for a legitimate customer's email prevents them from auto-registering via magic link until the decay window passes.

**Mitigation**: Per-email cap of 5 means an attacker spends 5 cheap requests to delay a customer's first magic-link for 15 minutes. That's an annoyance, not a security bypass. If post-launch this becomes a real issue, a secondary channel (password-reset flow, customer-register page) remains available. The alternative — uncapped per-email bucket — leaves the attack vector wide open. Risk accepted.

### 8.8 FormRequest + generic-success enumeration guard

**Risk**: The throttle response is a validation-error on the `email` field (via `ValidationException::withMessages`). A careful attacker can infer from the 422-shape response that the endpoint was contacted and rate-limited. This is marginally more signal than the generic 200-back-with-flash-success that the non-throttled path returns.

**Mitigation**: The throttle triggers after N requests — the attacker already knows they've spammed the endpoint. The throttle response does not leak whether the email is valid or not (the same 422 fires regardless of email validity). Enumeration resistance is preserved in the non-throttled path; throttled responses don't need to match exactly because a rate-limited attacker can't use the information operationally (they're already blocked). Risk accepted.

---

## 9. What happens after R-10 + R-11

The remediation roadmap continues in parallel and sequential threads:

- **R-12 + R-13 + R-14 + R-15** — being planned in parallel as the "polish" session per ROADMAP-REVIEW's "can be one session" grouping (copy drift + customer reg + notifications + dependency cleanup). Independent of R-10 and R-11 at the file level.
- **R-16** — frontend code splitting / lazy page resolver. Standalone, last in the roadmap.
- **R-9 browser+screen-reader manual QA** — still pending from R-9 HANDOFF; unrelated to this session's scope but listed for completeness.
- **R-8 manual browser QA** — carried over from R-8 HANDOFF.

---

## 10. Carried-to-BACKLOG / deferred

All captured as one-liners in `docs/BACKLOG.md` under appropriate sections; noted here for traceability.

- **Consolidate rate-limiter definitions in `config/throttle.php`.** Today `booking-api` (60/min/ip) and `booking-create` (5/min/ip) are hardcoded in `AppServiceProvider`; `magic_link` / `password_reset` land in `config/auth.php` under R-11. A future consolidation pass can unify into one `config/throttle.php` file, but that's a cleanup not driven by any current failure.
- **Scheduler-lag alerting.** Today nothing alerts if `schedule:run` hasn't executed for an hour. Post-launch ops concern — the right home is `docs/DEPLOYMENT.md` + a Horizon/Pulse dashboard check. Not blocking; captured.
- **`X-RateLimit-Remaining` / `Retry-After` headers on throttled auth-recovery responses.** The current design uses validation-error response shape (for UX parity with login), which doesn't set those headers. A future API-first / third-party-client work stream would want them; noted.
- **A dashboard UI for reminder status / replay / manual trigger.** Useful for businesses debugging "why didn't my customer get the reminder"; post-MVP.
- **Alert on per-email-bucket lockout rate.** If abuse telemetry post-launch shows > 1% of legitimate users hitting the per-email cap, tune the value up (env variable, no redeploy) — captured as an ops observability item.
- **Reminder `hours_before` per-booking override.** `reminder_hours` is per-Business per D-019. Per-booking override is a product decision for post-MVP.
- **SMS / WhatsApp reminder channel.** SPEC §9 post-MVP. Reminder eligibility shape (wall-clock local, past-due, `booking_reminders` idempotency) composes over additional channels without further decisions — one row per (booking, hours_before, channel) would be the likely extension.
- **Emit a new `RateLimitExceeded` event for auth-recovery throttle.** Currently no event. If abuse telemetry wants it, add a listener; post-launch concern.
- **Fall-back-overlap wall-clock ambiguity** — the current choice (Carbon picks the first occurrence; reminder fires one hour "earlier" in absolute-UTC terms) is documented in D-071. If a support case surfaces customer-perceived-wrong behaviour around the autumn DST weekend, we revisit. Not currently blocking.
- **Test env cache driver sanity check.** A meta-test asserting `config('cache.default')` is non-null and that `RateLimiter::hit('___test___')` round-trips. Cheap belt-and-braces. Captured.
