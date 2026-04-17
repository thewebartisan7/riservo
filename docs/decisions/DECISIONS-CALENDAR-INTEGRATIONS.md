# Calendar Integration Decisions

This file contains decisions specific to external calendar integration work. It is intentionally small today and will grow as calendar-sync implementation resumes.

---

### D-010 — Google Calendar sync scheduled late in the MVP sequence
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: Google Calendar sync (bidirectional, with webhooks) is the most complex feature in the MVP. It depends on all other features being stable first.
- **Decision**: Google Calendar sync is Session 12, deliberately scheduled near the end of the MVP sequence so it builds on stable booking and dashboard foundations. It is built behind a `CalendarProvider` interface so future providers (Outlook, Apple via CalDAV) can be added without touching booking logic.
- **Consequences**: If time or budget requires cutting a feature, this is the first candidate to defer to v2 without impacting core functionality.

---

### D-080 — Calendar integration stack: Socialite + `google/apiclient`
- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: The MVP-completion roadmap (`docs/roadmaps/ROADMAP-MVP-COMPLETION.md`) mandates Google Calendar sync. The OAuth flow and the Calendar API calls are two separable concerns. Rolling our own on top of Guzzle would mean re-implementing token refresh, typed event-object mapping, batch semantics, quota-aware retries, and the handful of Google-API edge cases the SDK already covers.
- **Decision**: Use `laravel/socialite` (v5) for the OAuth authorization-code flow (consent, callback, token exchange, refresh) and `google/apiclient:^2.15` for Calendar API calls once Session 2 starts making them. Both packages install in Session 1 (the OAuth foundation session) even though Session 1 only calls Socialite, so Session 2 lands on a green baseline with no composer step.
- **Consequences**:
  - ~7 MB dependency footprint for `google/apiclient`. One-time install cost is paid back by the Socialite + SDK gain on implementation velocity and correctness for every subsequent calendar feature.
  - Session 1's `Dashboard\Settings\CalendarIntegrationController::connect` / `callback` call `Socialite::driver('google')` directly. A `CalendarProvider` interface is deferred to Session 2, where the push / pull methods (`pushEvent`, `syncIncremental`, `startWatch`, …) motivate it. Adding an interface in Session 1 would either wrap a single `getAccountEmail` call (premature abstraction) or pre-commit to a method shape before Session 2's agent designs it.
  - No replacement for either package is easy to evaluate later without a concrete failure to motivate the swap.

---

### D-082 — `CalendarProvider` interface and factory placement
- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: MVPC-2 needs a provider abstraction so Outlook / Apple / CalDAV can be added post-MVP without changes to `Booking`, `SlotGeneratorService`, or any notification dispatch site.
- **Decision**: `app/Services/Calendar/CalendarProvider.php` interface with eight methods (`listCalendars`, `createCalendar`, `pushEvent`, `updateEvent`, `deleteEvent`, `startWatch`, `stopWatch`, `syncIncremental`). `GoogleCalendarProvider` + `GoogleClientFactory` live alongside. `CalendarProviderFactory::for($integration)` resolves by `integration.provider`; singleton-bound in `AppServiceProvider::register()`. DTOs under `app/Services/Calendar/DTOs/` (`CalendarSummary`, `WatchResult`, `ExternalEvent`, `SyncResult`).
- **Consequences**: Adding a future provider is purely additive — a new class, a new match arm in the factory, no changes to `Booking` or any notification. No SDK type (`Google\Client`, `Google\Service\Calendar`) leaks above the provider layer. Token refresh is encapsulated in `GoogleClientFactory` via `setTokenCallback` (D-080), which persists rotated access tokens back to the integration row.

---

### D-083 — Single `PushBookingToCalendarJob` with action parameter + origin-calendar pinned per booking
- **Date**: 2026-04-17 (originally) / extended 2026-04-17 (review round 2)
- **Status**: accepted
- **Context**: Every booking-mutating code path (public booking create, manual booking create, status transitions to confirmed/cancelled/completed/no_show, customer-side cancel, dashboard cancel) must mirror the change to Google. The three actions (create, update, delete) share the retry policy, the two gate conditions, the integration lookup, and the failure handling. **Round-2 review** surfaced a correctness gap: only the Google event id was persisted on the booking. After a reconfigure that changes `destination_calendar_id`, subsequent update/delete pushes targeted the new destination instead of the calendar the event was originally created in — leaving orphaned events behind and operating on the wrong calendar.
- **Decision**: One queued job, `PushBookingToCalendarJob(int $bookingId, string $action)` where `$action ∈ {create, update, delete}`. `tries = 3`; `backoff = [60, 300, 900]`; `afterCommit()`. Dispatch sites are gated by `Booking::shouldPushToCalendar()`, which checks both the inbound-origin skip (`source !== google_calendar`) and the configured-integration gate (`$integration->isConfigured()`). **Bookings persist the destination calendar at push-time** in a dedicated column `bookings.external_event_calendar_id`. `PushBookingToCalendarJob::create` writes it alongside `external_calendar_id`; `update` / `delete` (in both the job and `GoogleCalendarProvider::updateEvent`) target `$booking->external_event_calendar_id ?? $integration->destination_calendar_id` — falling back to the current destination only for legacy rows pushed before this column existed.
- **Consequences**: Six dispatch sites read identically — `PushBookingToCalendarJob::dispatch($booking->id, $action)`. Splitting into three jobs would duplicate retry/backoff/gate/failure paths; the action-specific branch in `handle()` is ten lines, below the abstraction threshold. Session 5's reschedule endpoint re-uses the same dispatch shape. A reconfigure is safe: past bookings still update/delete the right Google event; future bookings land in the new destination.

---

### D-084 — External event visuals + dedicated `external_html_link` column
- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: External events (`source = google_calendar`) must be visually distinct from riservo bookings in the dashboard calendar, and the detail panel needs an "Open in Google Calendar" link pointing at the event's htmlLink. We audited two options for persistence: overload `bookings.internal_notes` with the URL, or add a dedicated column.
- **Decision**: Visuals: lucide-react `CalendarDays` icon with `bg-muted/70 text-muted-foreground` tone from the existing COSS UI palette — `EXTERNAL_EVENT_COLOR` in `resources/js/lib/calendar-colors.ts`. The Google event's `htmlLink` is stored in a dedicated `bookings.external_html_link` column (string, nullable) alongside the existing external-event companions `external_calendar_id` and `external_title`. `internal_notes` stays admin-notes-only; overloading it was rejected on review because it mixes concerns and would propagate to every future calendar provider.
- **Consequences**: No Google brand-asset integration required (avoids spacing / attribution rules); the neutral tone reinforces that the item is not a bookable riservo slot. The dedicated column composes cleanly if a future provider also surfaces an html link — or if we later expose admin notes on external events. Frontend branches on `booking.external` to render the variant.

---

### D-085 — `CalendarIntegration.business_id` anchors the sync target business
- **Date**: 2026-04-17 (originally) / extended 2026-04-17 (review round 2)
- **Status**: accepted
- **Context**: `CalendarIntegration` is user-scoped (D-080). But every riservo booking requires a `provider_id`, which is business-scoped. Inbound external events must resolve deterministically to one specific `providers` row, which in turn requires the integration to know which business it targets.
- **Decision**: Add `business_id` (FK nullable → businesses, `cascadeOnDelete`) to `calendar_integrations`. Populated during the configure step from the user's currently-active tenant. `null` means "MVPC-1 state, not yet configured". `PullCalendarEventsJob` resolves the provider via `$integration->business->providers()->where('user_id', $integration->user_id)->first()`. A user with memberships in multiple businesses who wants to sync to both is explicitly deferred (post-MVP, tracked with the R-2B business-switcher carry-over).
- **Repin semantics (round-2 extension)**: `saveConfiguration` detects when the integration's existing `business_id` differs from the acting tenant's business. On a repin, it performs a teardown before updating the row: stops every watch in Google (best-effort), deletes every `calendar_watches` row (which also purges per-watch `sync_token`s), deletes every `calendar_pending_actions` row attached to the integration, and clears `last_synced_at`, `last_pushed_at`, `sync_error`, `sync_error_at`, `push_error`, `push_error_at`. The OAuth tokens (`access_token`, `refresh_token`, `token_expires_at`, `google_account_email`) are preserved — the user does NOT re-consent on Google. `StartCalendarSyncJob` then runs under the new `business_id`, creating fresh watches + cursors from scratch. Without this teardown, old watches would continue delivering webhooks that import events under the new business's provider, old per-watch sync tokens would be reused across business contexts, and old pending actions would orphan out of view of both tenants (they were scoped to business A but now attached to an integration pinned to business B).
- **Consequences**: One Google integration per user, one business scope. The settings page shows "Syncing to {business name}" and a muted cross-tenant notice (no "Disconnect to reconfigure" CTA per review — the existing Disconnect button is sufficient). Provider-row resolution is deterministic and testable. Repinning is explicit and lossy by design: the user's previous sync history + pending actions do not follow them. The UX tradeoff is deliberate — carrying stateful sync bookkeeping across business boundaries is more error-prone than rebuilding it fresh.

---

### D-086 — `calendar_watches` sub-table for per-calendar webhook registrations + per-calendar sync token
- **Date**: 2026-04-17 (originally) / extended 2026-04-17 (review round 2)
- **Status**: accepted
- **Context**: A configured integration watches one destination calendar plus zero-or-more conflict calendars. Each watch has its own Google channel id, resource id, per-channel secret token, and expiry (Google caps subscriptions at ~30 days). Two options were considered for persistence: a JSON column on `calendar_integrations`, or a dedicated sub-table. **Round-2 review** surfaced a correctness extension: Google's `syncToken` is also per-calendar; a token from calendar A, replayed against calendar B, is rejected with 410 and forces the pull back to forward-only sync, silently dropping changes between runs in any multi-calendar setup (i.e., every configured integration, since destination + at least primary-as-conflict is the default).
- **Decision**: Dedicated `calendar_watches` table: `(id, integration_id FK cascade, calendar_id, channel_id unique, resource_id, channel_token, sync_token, expires_at, timestamps)`. Index on `(integration_id, calendar_id)` and on `expires_at`. The webhook handler's hot path looks up by `channel_id` (unique index). Renewal iterates `expires_at < now + 24h`. `sync_token` is read/written on this row (`GoogleCalendarProvider::syncIncremental` + `PullCalendarEventsJob`), **not** on `calendar_integrations`. The integration-level `sync_token` column is no longer written. The MVPC-1 columns `calendar_integrations.webhook_channel_id` / `webhook_expiry` / `webhook_resource_id` / `webhook_channel_token` remain in the schema but are unused — dropping them would be zero-value churn on the same table we just extended.
- **Consequences**: Faster webhook hot path than a JSON lookup; per-watch rotation is a row update; channel cleanup is `DELETE FROM calendar_watches WHERE ...`. Sync tokens never cross-contaminate across calendars. Adding a future "I want to watch 12 calendars" workflow is purely additive.
- **Reconfigure semantics (round-2 extension)**: `StartCalendarSyncJob` now reconciles the desired watch set (`{destination_calendar_id} ∪ conflict_calendar_ids`) against the existing `calendar_watches` rows: watches for calendars removed from the config are stopped in Google (best-effort) and deleted locally; new calendars get a fresh watch + initial forward-only pull; unchanged calendars are left alone. Without this, unchecking a calendar in the configure form would leave the old channel delivering webhooks indefinitely.

---

### D-087 — "Keep riservo booking (ignore external)" replaces the ambiguous "Keep both"
- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: When a pulled Google event overlaps a confirmed riservo booking on the same provider, the GIST exclusion constraint (D-065 / D-066) rejects the insert. We surface this as a pending action. The original roadmap wording offered "Keep both" as one resolution choice, but the invariant does not actually permit keeping both — a conflicting external event cannot materialise as a riservo booking row.
- **Decision**: The `external_booking_conflict` pending action has exactly three resolution choices:
  1. **Keep riservo booking (ignore external)** — the pending action is marked `dismissed`. The external event remains in Google but does NOT materialise in riservo. No DB mutation, no notification.
  2. **Cancel external event** — the resolver calls `$provider->deleteEvent(...)` to delete the Google event; action marked `resolved`.
  3. **Cancel riservo booking** — the resolver cancels the riservo booking, dispatches `BookingCancelledNotification(cancelledBy='business')` to the customer (the riservo booking's source is `riservo`, so the suppression guard allows it), marks the action `resolved`, **and immediately dispatches `PullCalendarEventsJob` for the calendar that produced the conflict**. The re-pull is part of the resolver, not a downstream concern — without it, the external event only appears in riservo when the next webhook fires for that calendar (potentially many minutes later).
- **Consequences**: Button labels are honest about what happens. The D-065 / D-066 invariant is never bypassed. The third choice is deterministic: the external event materialises as an external Booking row before the resolver returns (subject to queue worker latency on `PullCalendarEventsJob`, typically seconds). Tests assert the job is dispatched with the source calendar id taken from the action payload.
- **Failure semantics (round-2 extension)**: `cancel_external` calls `deleteEvent` on the provider. If Google rejects or times out, the exception is reported but the pending action **stays pending** and the resolver flashes an error — the user can retry. Marking the action resolved on a provider failure would leave the external event in place (still blocking availability) while hiding it from the pending-actions queue. 404/410 on the Google side are absorbed inside `GoogleCalendarProvider::deleteEvent` as "event is already gone = success" and do NOT trip the failure path.

---

### D-088 — Notification suppression at dispatch sites with a model helper
- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Locked roadmap decision #7 requires that bookings with `source = google_calendar` never trigger customer-facing notifications. We audited every Booking-related Notification dispatch site (`PublicBookingController::store`, `Dashboard\BookingController::store`, `Dashboard\BookingController::updateStatus` on confirmed/cancelled, `Customer\BookingController::cancel`, `BookingManagementController::cancel`, `SendBookingReminders`, and every `notifyStaff` helper). The guard must live at the dispatch site, not inside the Notification classes.
- **Decision**: Two helpers on the `Booking` model encapsulate the rule:
  - `Booking::shouldSuppressCustomerNotifications(): bool` returns `true` iff `source === BookingSource::GoogleCalendar`. Every customer and staff notification dispatch site calls `if (! $booking->shouldSuppressCustomerNotifications())` before firing.
  - `Booking::shouldPushToCalendar(): bool` returns `true` iff `source !== google_calendar` AND the provider's user has a configured integration. Every dispatch of `PushBookingToCalendarJob` is gated by this helper.
  - `SendBookingReminders::handle()` additionally excludes `source = google_calendar` at query time (cheaper than per-row guard; the dispatch-site guard still runs as defence-in-depth).
  - Pending-action resolution extends the rule: the resolver's cancel-riservo-booking path is scoped to "owner-of-integration OR admin of the business" (`abort_unless(tenant()->role() === Admin || $action->integration->user_id === $user->id, 403)`) so staff X cannot cancel customer-facing bookings tied to staff Y's integration. Tenant scoping is enforced separately (404 on `action.business_id !== tenant()->business()->id`).
- **Consequences**: A future source with suppression requirements adds a case to the helper; all dispatch sites inherit it. Tests assert zero notifications fire for every dispatch site when `source = google_calendar`, and pending-action resolution tests cover the owner-or-admin matrix.
