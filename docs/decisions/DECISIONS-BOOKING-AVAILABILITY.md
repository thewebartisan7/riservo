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
