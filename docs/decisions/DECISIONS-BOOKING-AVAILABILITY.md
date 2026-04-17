# Booking and Availability Decisions

This file contains live decisions about timezone handling, availability rules, booking flow behavior, booking state, and reminder logic.

---

### D-005 — Business timezone as single source of truth
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: Appointments are physical/local by default. Cross-timezone online appointments are rare and out of scope for MVP.
- **Decision**: Every `Business` has a `timezone` field (default: `Europe/Zurich`). All datetimes are stored in UTC. All slot calculation, display, and reminder scheduling uses the business's configured timezone.
- **Consequences**: Customers booking from a different timezone see the business's local times, which is correct for in-person appointments. Online appointment timezone support deferred to v2.

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

### D-027 — FK naming: collaborator_id for user-as-collaborator references
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: Several tables reference the `users` table to represent the collaborator performing a service. Using `user_id` everywhere is ambiguous — `bookings.user_id` could mean the customer's user, the business owner, or the collaborator.
- **Decision**: Tables where the FK represents a collaborator use `collaborator_id` (pointing to `users.id`): `bookings`, `availability_rules`, `availability_exceptions`, `collaborator_service`. Tables where the FK represents a generic user use `user_id`: `customers`, `calendar_integrations`.
- **Consequences**: Self-documenting schema. Relationship methods are named `collaborator()` where appropriate. Requires explicit `->constrained('users')` in migrations since Laravel can't infer the table from `collaborator_id`.

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

### D-056 — Reminder deduplication via booking_reminders table
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: The `bookings:send-reminders` command runs every 5 minutes. It needs to avoid sending duplicate reminders for the same booking and interval. Options considered: JSON column on bookings, separate table, or relying on narrow time windows.
- **Decision**: A dedicated `booking_reminders` table with columns `booking_id`, `hours_before`, `sent_at` and a unique constraint on `(booking_id, hours_before)`. The command inserts a record before dispatching the notification; the unique constraint prevents races.
- **Consequences**: Clean audit trail of sent reminders. DB-level deduplication is race-safe. Queryable for debugging. Does not bloat the bookings table.

---

### D-057 — Merged BookingReceivedNotification for staff
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: The roadmap listed "Booking confirmed (to collaborator)" and "New booking received (to business/collaborator)" as separate templates. These are near-identical in structure — both notify staff about a booking with its details.
- **Decision**: A single `BookingReceivedNotification` class with a `$context` parameter ('new' | 'confirmed') adapts the subject line and heading. Sent to business admins + assigned collaborator on booking creation (context='new') and on admin confirmation of a pending booking (context='confirmed').
- **Consequences**: One fewer notification class. Template reuse. The distinction between "new" and "confirmed" is still clear to the recipient via subject/heading.

---

### D-066 — Booking overlap invariant via EXCLUDE USING GIST on effective interval
- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: Both booking write paths perform a read-check-write sequence (availability check in application code, then `Booking::create`) with no transaction, no lock, and no DB-level invariant. Two concurrent requests can both pass the check and both insert overlapping bookings. Per D-065 the database engine is Postgres; `EXCLUDE USING GIST` with `btree_gist` expresses the invariant declaratively at the schema level.
- **Decision**: The `bookings` table carries a snapshot of the service's buffers at booking time (`buffer_before_minutes`, `buffer_after_minutes`) plus Postgres generated columns `effective_starts_at` and `effective_ends_at`. A partial exclusion constraint, `bookings_no_provider_overlap`, enforces:

    EXCLUDE USING GIST (
      provider_id WITH =,
      tsrange(effective_starts_at, effective_ends_at, '[)') WITH &&
    ) WHERE (status IN ('pending', 'confirmed'))

  Both controller write paths are wrapped in `DB::transaction(...)`. The existing `SlotGeneratorService::getAvailableSlots()` check is kept as the first-line fast-fail. A Postgres `23P01` (exclusion_violation) at `Booking::create` is caught and translated into the same 409 "slot no longer available" response the fast-fail produces.
- **Consequences**:
  - A booking and its effective interval carry the buffer state of the service at the moment of creation; editing the service's buffers later does not retroactively change the effective interval of existing bookings. This is also semantically correct — a booking is a contract for a specific occupied window.
  - Only `pending` and `confirmed` bookings participate in the constraint, matching D-031. A cancelled booking is removed from the GIST index and no longer blocks new bookings.
  - New booking write sites (future jobs, imports, admin tools, Google Calendar pull sync) inherit the guard with no extra code. They cannot produce overlapping bookings even if they forget a transaction.
  - Concurrent writes targeting different providers do not interact — the GIST index is per-provider. Deadlock surface is minimal; no `SELECT FOR UPDATE`, no advisory locks.
  - Migration rewrites the `bookings` schema; pre-launch, the seeder re-provisions all data.
- **Supersedes**: none.

---

### D-067 — `Booking::provider()` resolves the historical provider including trashed
- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: Providers are first-class and soft-deletable (D-061). A booking holds a `provider_id` snapshot of who the appointment was attached to at the moment it was created. When a provider is soft-deleted, Eloquent's default `SoftDeletingScope` hides the row — so `$booking->provider` returns `null` everywhere the relation is traversed. Every display, notification, and read-side payload that touches `booking.provider.*` silently crashes on historical bookings (dashboard today list, bookings table, customer's "My Bookings", public management page, email templates). Availability/eligibility queries must still exclude trashed providers.
- **Decision**: Override the relation on the `Booking` model:

      public function provider(): BelongsTo
      {
          return $this->belongsTo(Provider::class)->withTrashed();
      }

  Read-side payloads additionally expose `provider.is_active = ! $provider->trashed()` so the UI can render a "(deactivated)" marker where relevant. Eligibility for new work continues to flow through `Provider::query()`, `$service->providers()`, and `$business->providers()`, which keep the default scope and therefore exclude trashed providers from slot generation, `/booking/{slug}/providers`, and the `StorePublicBookingRequest` validator.
- **Consequences**:
  - Display and notification code can safely dereference `$booking->provider->user?->name` for any booking, past or present, without null-coalescing at every site.
  - Historical bookings continue to render the provider's original name with a clear visual cue that the provider is no longer bookable.
  - `Booking::provider` is the single asymmetry — everywhere else in the code a trashed provider is still hidden.
- **Supersedes**: none.

---

### D-068 — Server-side enforcement of `allow_provider_choice` via ignore-and-fall-through

- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: `allow_provider_choice` was respected by the multi-step React flow but not enforced by the four server surfaces that read or honour `provider_id` (`PublicBookingController::providers`, `availableDates`, `slots`, `store`). A crafted POST could target a specific provider; a preselected-service URL initialised the flow on the provider step regardless of the setting. The setting was effectively a client-side suggestion, not a business policy.
- **Decision**: The setting is gated **in the controller**, not in the Form Request. When `$business->allow_provider_choice === false`, every server surface treats the effective `provider_id` as if it were not submitted:
  - `providers()` returns an empty list (`200 OK`, `{ providers: [] }`).
  - `availableDates()`, `slots()`, and `store()` ignore any submitted `provider_id` and compute / assign as the "any provider" branch.

  A private helper `resolveProviderIfChoiceAllowed()` centralises the single expression `($business->allow_provider_choice && $providerId) ? $business->providers()->...->firstOrFail() : null` used across the three availability / store methods. `StorePublicBookingRequest` continues to validate that a submitted `provider_id` names a provider in the business (existence + tenant scope via its inline closure), because that check is a first-line 422 against invalid IDs regardless of the setting.
- **Consequences**:
  - Honest clients that respect the setting see no behaviour change — they never submit `provider_id` when choice is off.
  - Crafted requests that submit `provider_id` are silently downgraded to the "any provider" branch. No 422, no 403 — the policy is enforced without leaking a diagnostic that lets a probe distinguish "my provider_id was rejected" from "my ID was never considered".
  - A honest-client race (admin toggles off mid-session) does not produce a hard error for the customer: their flow degrades to auto-assignment.
  - The pattern is reusable — future "business setting enforced server-side" gaps (none currently exist; audited in PLAN-R-7 §1.4) can follow the same template: gate in the controller, treat the setting as a silent modifier of the request, record as a decision.
- **Rejected alternatives**:
  - *Gate in `StorePublicBookingRequest`.* The Form Request knows about the schema, not the business policy. Moving the gate there would couple validation to a business-model read that varies at runtime. Also: the same policy needs to apply to three read endpoints that have no Form Request — the gate would have to live in two places.
  - *Reject with 422 when `provider_id` is submitted but choice is off.* Cleaner signal but turns a crafted probe into a diagnostic (`provider_id is rejected` vs `provider_id is ignored`). Also surprises an honest client in a race condition. The product intent ("customers can't pick") is equally well served by silent fall-through.
  - *Return 403 on `providers()` when setting is off.* Same diagnostic concern. Empty list is semantically correct — the set of *customer-choosable* providers is empty.
  - *New middleware `EnforceProviderChoice`.* Overkill for one setting and one controller. Hides the policy away from the reading flow.

---

### D-071 — Reminder eligibility uses business-timezone wall-clock; delayed runs fire past-due with row-level idempotency

- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: `SendBookingReminders` pre-R-10 computed eligibility as `now()+hoursBefore ± 5 minutes`, with `now()` in UTC. Two silent-failure modes followed. First, across DST transitions, "24 hours before `starts_at`" in absolute UTC is not the same as "24 hours before the local-wall-clock appointment time"; for a 10:00 Europe/Zurich appointment the day after DST ends, the two interpretations differ by up to an hour, and for late-night appointments near a DST transition the difference can reach two hours (spring-forward gap). Second, the tight ±5-minute window dropped every eligible reminder whose window passed during any scheduler outage longer than five minutes (deploy, restart, `withoutOverlapping()` holding behind a slow prior run). The `booking_reminders` unique `(booking_id, hours_before)` constraint (D-056) prevents *duplicates* but cannot recover *misses* — once the window passes, the existing query never selects that booking for that interval again. REVIEW-1 §#11 flagged both modes as REGRESSION-PRONE.
- **Decision**:
  1. **Eligibility semantics — business-timezone wall-clock.** "N hours before" is computed as `starts_at` projected into the business's timezone, shifted by `N` wall-clock hours, then reprojected to UTC for the eligibility check. In code: `$reminderTimeUtc = $booking->starts_at->setTimezone($business->timezone)->subHours($hoursBefore)->utc()`. This matches the customer's mental model for an in-person appointment product (D-005 says the business timezone is the single source of truth; D-030 says slot calculation operates in business-local time). Across DST, the absolute UTC interval between the reminder and the appointment drifts by ±1 hour, and this is accepted explicitly — a customer with a 10 AM Monday appointment receiving a reminder "around 10 AM on Sunday" is correct regardless of whether the intervening Saturday crossed a DST boundary.
  2. **Delayed-run strategy — past-due eligibility with row-level idempotency.** A reminder is eligible when `reminderTimeUtc <= now && starts_at > now && no booking_reminders row exists for (booking, hoursBefore)`. This is an *open* look-back window: no `N` to tune. A scheduler outage of any duration cannot drop an eligible reminder as long as the appointment itself has not yet started. The `starts_at > now` upper bound prevents sending a reminder *after* the appointment. Idempotency continues to flow through the `booking_reminders` unique `(booking_id, hours_before)` constraint (D-056), enforced at insert time before dispatching the notification — catching `UniqueConstraintViolationException` (Postgres `23505`) as a race loss.
  3. **No watermark / no state beyond `booking_reminders`.** The watermark-based alternative was considered and rejected. `booking_reminders` already provides row-level idempotency at the correct granularity; a watermark would double-book the correctness invariant and re-introduce a tuning knob ("last-processed `starts_at` / `booking_id`") that drifts under concurrency.
  4. **DST-gap and DST-overlap handling is deferred to Carbon's deterministic behaviour.** For the spring-forward gap (a wall-clock time that doesn't exist locally), Carbon rolls forward into the existing hour; for the fall-back overlap (a wall-clock time that occurs twice), Carbon picks the first occurrence (the earlier UTC instant). Both are documented, both are tested (§6.1.1 / §6.1.2), and both are explicitly judged acceptable — sending the reminder slightly earlier rather than slightly later matches the "better too early than missed" product bias.
  5. **Per-booking cap for not-yet-configured reminder hours.** The inner loop still re-checks `in_array($hoursBefore, $business->reminder_hours ?? [])` to handle the outer hours-list being a union across all businesses — identical to the pre-R-10 code path, preserved for correctness.
  6. **Scheduler cadence unchanged** — `everyFiveMinutes()->withoutOverlapping()` stays. Correctness lives in the eligibility math, not the cadence. The cadence controls *how often* catch-up opportunity arrives; correctness is independent.
- **Consequences**:
  - A scheduler outage of up to `hoursBefore` hours can still recover every eligible reminder on the next successful run. A 24-hour reminder that would have fired during a two-hour outage fires 23h58m before the appointment instead. A 1-hour reminder that would have fired during a 40-minute outage fires 20 minutes before.
  - A reminder is never sent after the appointment has started, even if the scheduler was down for longer than `hoursBefore`. The booking simply doesn't get that reminder — accepted as the safer failure mode than "reminder arrives in the middle of the appointment."
  - The unique constraint does double duty: deduplication across concurrent runs, and the race-safe-slot-claim for past-due eligibility.
  - Display in `BookingReminderNotification::toMail()` is already wall-clock-local via `->setTimezone($business->timezone)` (`BookingReminderNotification.php:33`). Post-R-10, eligibility + display share the same timezone semantics. No change to the notification.
  - Future extensions (SMS, per-booking override, different `reminder_hours` granularity) inherit the same eligibility shape.
  - The performance horizon `whereBetween('starts_at', [$now, $now + max(reminder_hours) + buffer])` keeps the candidate set bounded — a business with `reminder_hours = [168]` (a week) does mean a week's worth of upcoming bookings per run, but that is bounded in practice and still much smaller than the full bookings table.
- **Rejected alternatives**:
  - *Absolute-UTC semantics.* Simpler in code, but wrong for the product: customer expectation in an in-person-appointment product is wall-clock. Also inconsistent with D-005 and D-030, which already treat business timezone as authoritative.
  - *Look-back window with a tuning knob (`N` minutes back + `M` minutes forward).* Trades one arbitrary value (±5 min) for another. Any `N` silently drops reminders past an outage of `N` minutes; the past-due design has no knob to tune.
  - *Watermark (last-processed `(hours_before, starts_at)` cursor).* Extra state, per-hours-before, with concurrency implications. The `booking_reminders` table already gives row-level idempotency; a watermark is strictly more complicated for no added correctness.
  - *Moving eligibility check to the queue worker instead of the scheduled command.* Would re-centre the correctness problem on queue delay; queue delay is a different distribution than scheduler delay. Keeping the eligibility check in the command preserves clear ownership.

---

### D-104 — Dashboard drag is scoped to same-provider same-business

- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Session 5's locked-decision #14 limits drag to week + day views. The scope question that remained: can an admin drag a booking from Alice's column to Bob's, triggering a cross-provider reschedule? Cross-provider drag requires re-validating service eligibility against the target provider, re-resolving the target provider's calendar integration for the `PushBookingToCalendarJob` dispatch, and re-acquiring the GIST-per-provider lock under the new provider.
- **Decision**: Drag is scoped to "move this booking to a different time (and possibly a different day) on the SAME provider in the SAME business". The `DashboardBookingController::reschedule` endpoint accepts `starts_at` + `duration_minutes` only — no `provider_id`. `service_id` is also immutable through the reschedule endpoint. Changing provider or service goes through the existing cancel-and-rebook flow in the booking-detail sheet.
- **Consequences**:
  - Reschedule is a narrow contract: "move in time". The GIST constraint continues to match the race model (same provider, same booking ID excluded from the conflict check).
  - Cross-provider drag when per-provider columns land post-MVP writes a new decision.
  - Customers can always cancel and rebook if their time is wrong — the reschedule flow is optimised for staff correcting a time, not for service-switching.
- **Supersedes**: none.

---

### D-105 — Reschedule request shape is `{ starts_at, duration_minutes }`

- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Drag and resize both need to update the booking's time. Three shapes considered: `{ starts_at, ends_at }`, `{ starts_at, duration_minutes }`, or two separate endpoints for drag vs resize.
- **Decision**: One endpoint, one shape: `PATCH /dashboard/bookings/{booking}/reschedule` with `{ starts_at: ISO-8601 UTC, duration_minutes: int }`. The server recomputes `ends_at = starts_at + duration_minutes`. For a drag (move-only) the client sends the booking's current duration. For a resize the client sends the new duration it drew. Shape chosen so the client cannot send an inconsistent `(starts_at, ends_at)` pair; every request maps to a single unambiguous window.
- **Consequences**:
  - Single backend validation path for both gestures. No duplicated availability / GIST / notification plumbing.
  - The `SlotGeneratorService::getAvailableSlots()` check uses the new `excluding: $booking` parameter (added in MVPC-5) so the booking does not block its own move — matches D-066's "the booking being rescheduled is the one we're freeing up".
  - `ends_at` stays an invariant of `starts_at + duration`. Existing constraints (GIST on effective interval, buffers in `buffer_before_minutes` / `buffer_after_minutes`) are unchanged.
  - Staff cannot rename a service or change a provider through reschedule.
- **Rejected alternatives**:
  - *`{ starts_at, ends_at }`*: client and server can drift on the ends value; controller would have to reject mismatched pairs.
  - *Two endpoints*: duplicates availability check, transaction, GIST-backstop, notification dispatch, and push-calendar gating. All three actions (drag, resize, cross-day drag) are the same write from the server's point of view — one endpoint is enough.

---

### D-106 — Reschedule rejects non-grid `starts_at` with 422, not silent snap

- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Session 5 locked-decision #15 mandates that resize granularity equals `service.slot_interval_minutes` and that the server is authoritative. The enforcement choice was: 422 with a friendly error when the request doesn't snap to the grid, or silent server-side snap to the nearest grid point.
- **Decision**: The reschedule endpoint returns 422 `{ message: "Start time must align with the :minutes-minute grid.", … }` when `starts_at.minute % slot_interval_minutes !== 0` (and the analogous check for `duration_minutes`). No silent snap. The client already snaps its drag to a 15-minute superset of all supported intervals (15 / 30 / 60) in `DndCalendarShell`, so the 422 path fires only when (a) the client is out of date, or (b) the service's interval changed mid-session, or (c) a crafted request reached the endpoint.
- **Consequences**:
  - A 422 surfaces a fixable condition rather than hiding a disagreement between what the user drew and what the server accepted.
  - Client snap (15 min) + server enforcement (actual service interval) keeps the UX smooth on the common path while the server remains honest about drift.
  - Tests cover the off-grid rejection path directly (`RescheduleBookingTest::reschedule that does not snap to slot interval is refused`).
- **Rejected alternatives**:
  - *Silent server-side snap*: the user drew 10:17 but the server stored 10:15. The card visibly jumps after the optimistic move, producing a "did my drag do anything?" feeling. Also hides client bugs.
  - *Validation layer pre-controller*: needs the service to compute `slot_interval`, which lives on the booking. Controller already has both. No benefit to moving.
