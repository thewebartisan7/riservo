# Backlog

This file captures unscheduled follow-up work, UX ideas, and deferred engineering cleanup. Items here are notes, not committed roadmap work.

## UI Follow-up

- Revisit booking formatter ownership once the multi-language pass lands. The helpers in `resources/js/lib/booking-format.ts` currently format client-side via `Intl`; move formatting to Laravel controllers once business-locale formatting becomes product-critical.
- Reassess whether more global theme tokens should be repainted beyond the current honey/paper/ink overrides. `card`, `popover`, `sidebar`, `input`, `secondary`, `destructive`, and chart/code tokens were intentionally left closer to upstream defaults during the booking-flow UI consolidation.
- Review whether the dashboard calendar should eventually be aligned more closely with the newer booking-flow primitive usage, but keep that as a dedicated follow-up rather than bundling it into unrelated UI work.

## UX Ideas

- Consider Inertia polling for the dashboard home so appointment counts refresh without a manual reload once the app has real traffic.
- Evaluate link prefetching for calendar navigation and adjacent booking screens after the calendar work is complete enough to measure perceived performance.
- Revisit scroll preservation on the bookings list so returning from detail views or panels does not reset position unnecessarily.

## Calendar — ICS one-way feed (deferred from ROADMAP-CALENDAR Phase 1)

- A signed per-user `.ics` feed URL that any calendar app can subscribe to (Google, Apple, Outlook). Read-only, poll-based (1–24h refresh depending on the client), zero OAuth.
- Deferred on 2026-04-16 in favour of going straight to the bidirectional Google integration (Session 2 of `ROADMAP-MVP-COMPLETION.md`). The OAuth integration covers the full set of users who would want a feed plus the bidirectional flow that ICS cannot deliver.
- Revisit only if user research surfaces a real demand from providers who refuse to grant Google OAuth scopes but still want their riservo bookings on their personal calendar.
- Implementation sketch lives in `docs/archive/roadmaps/ROADMAP-CALENDAR.md §Phase 1 — Session C1` — keep that file as the recoverable reference if this lands later.

## Technical Debt / Deferred Engineering Cleanup

- The onboarding logo upload path still reflects the older standalone-request implementation described in D-042. When that area is touched again, evaluate migrating it to the current Inertia v3 `useHttp` pattern.
- If `docs/design/ui.pen` stops being useful as a repo-local reference, move it out of the repository and update `docs/README.md` accordingly.

## Resend payment link for `unpaid` customer_choice bookings (deferred from ROADMAP-PAYMENTS Session 2)

- ROADMAP-PAYMENTS decision #14 promotes a `customer_choice` booking with a failed/abandoned online Checkout to `confirmed + payment_status = unpaid`. Today the customer falls back to paying at the appointment.
- Future enhancement: a "Resend payment link" affordance — either on the customer's booking management page (so the customer can retry online prepayment) or on the dashboard booking detail page (so the Business can email a fresh Checkout link).
- Implementation sketch: a new endpoint that mints a fresh `Stripe\Checkout\Session::create` against the existing `unpaid` booking row, reusing the existing slot reservation and the same `metadata.riservo_booking_id`; on `checkout.session.completed`, the booking transitions `unpaid → paid` (no `confirmed` flip needed, the booking is already confirmed).
- Not scoped for MVP; revisit if professionals request "give the customer one more chance to prepay" UX.

## Tighten billing freeload envelope (deferred from MVPC-3)

- Per D-089's consequences, `past_due` subscriptions are write-allowed during Stripe's dunning window so legitimate salons aren't locked out mid-payment-retry. Default Stripe retry policy is ~7 days / 4 retries, but account configuration can stretch to ~3 weeks. Worst case: a salon with a permanently invalid card keeps creating bookings for ~3 weeks before Stripe flips them to `canceled` and our webhook transitions them to `read_only`.
- Future refinement: introduce a "past_due for more than N days → read_only" rule. Likely shape: a helper on `Business` that subtracts `now()` from a stored first-payment-failed-at timestamp and short-circuits `canWrite()` when the gap exceeds a configurable threshold (default 7 days). Requires a new column or event-listener on `invoice.payment_failed` since Cashier doesn't track the first-failure timestamp natively.
- Not scoped for MVP. Revisit only if abuse telemetry shows the envelope is too generous post-launch.

## R-16 — Frontend code splitting (deferred from ROADMAP-REVIEW-1)

- The Inertia page resolver uses `import.meta.glob(..., { eager: true })`, producing a single ~958 kB main JS asset. Every user — including those on the lean public booking page — downloads all dashboard, settings, and calendar code upfront.
- Not a launch blocker: the bundle is cached after first load, and public booking / auth are the only surfaces where first-paint latency materially matters.
- Revisit if post-launch real-user metrics show meaningful FCP regression on those surfaces, or as a follow-up optimisation pass.
- Implementation sketch: switch to `import.meta.glob(..., { eager: false })` and let Vite split per page boundary; verify no Inertia `resolveComponent` adjustment is required; re-measure bundle output.
- Source: `docs/archive/reviews/REVIEW-1.md` §9 / `docs/archive/reviews/ROADMAP-REVIEW-1.md` §R-16.

## Bookability (R-17 carry-overs)

- Admin email / push notification when a service crosses into structurally-unbookable post-launch (the in-app banner is MVP; out-of-band alerting is deferred).
- Richer "provider is on vacation" UX on the public page — today a legitimately temporarily-unavailable provider just produces zero slots; a date-aware "back on X" caption is post-MVP.
- Banner per-user dismiss / ack history — current banner auto-clears on fix; a "remind me later" UX is deferred.
- Source: `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md` D-078, `docs/archive/reviews/ROADMAP-REVIEW-2.md` §R-17.

## Tenancy (R-19 carry-overs)

- **R-2B — Business-switcher UI in the dashboard header**. Multi-business membership is a data-model capability (D-063) and, post-R-19 (D-079), is now reachable through the invite flow. A user who belongs to more than one business today defaults to the oldest active membership with no in-app way to switch. Add a header dropdown that writes the chosen business id into `current_business_id`; `ResolveTenantContext` already handles the rest. Source: `docs/archive/reviews/ROADMAP-REVIEW-1.md` §R-2 carry-over + `docs/archive/reviews/ROADMAP-REVIEW-2.md` §R-19.
- **Admin-driven member deactivation + re-invite flow**. D-079 lands the restore-or-create helper and the new uniqueness index that allow re-entry after soft-delete, but no UI path soft-deletes a `business_members` row today. Post-MVP admin UX for "deactivate this member" + "re-invite this email" (which would naturally hit the restored row) is deferred.
- **"Leave business" member UX**. Today a staff member has no self-serve way to remove themselves from a business — only admins can do it (and only once the deactivation UX above ships). Deferred.

## Embed & Share (R-9 carry-overs)

- Popup widget i18n — load translations into `public/embed.js` (decide: per-script `data-locale`? server-rendered `/embed-{locale}.js`? `window.riservoLocale` global?). Today `iframe.title = 'Book appointment'` and the close button's `aria-label='Close'` are English-only.
- Popup analytics events — `onOpen`, `onBooked`, `onClose` callbacks for host-page telemetry.
- Custom theming for the popup overlay — CSS variables for container colors, border-radius, close-button style.
- Multi-service picker embed — a "Book with us" trigger that opens the popup on the service picker rather than a single service flow.
- CSP / `X-Frame-Options` hardening — cross-cutting, not embed-specific; the app currently sets neither.
- Safari ITP session-cookie behaviour inside iframes — investigate once real traffic surfaces an issue (D-070 Risk 8.4).
- Slug-alias history — old embed URLs should keep working when a business renames its slug or a service's slug (D-070 names the problem; does not solve it).
- Browser test infrastructure — Pest Browser plugin or Playwright, sized as its own session. Would unlock automated modal-robustness coverage for R-9.
- Deployed-snippet telemetry — instrument `embed.js` load to count "popup snippets in the wild," informing future contract changes.
- Dashboard embed-settings copy UX polish — toggles, multi-service snippet view, inline copy-with-comments.
- SRI hashes on the `<script>` tag — revisit if/when `embed.js` ever moves to a CDN rather than the app origin.
