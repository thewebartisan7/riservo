# Backlog

This file captures unscheduled follow-up work, UX ideas, and deferred engineering cleanup. Items here are notes, not committed roadmap work.

## UI Follow-up

- Revisit booking formatter ownership once the multi-language pass lands. The helpers in `resources/js/lib/booking-format.ts` currently format client-side via `Intl`; move formatting to Laravel controllers once business-locale formatting becomes product-critical.
- Reassess whether more global theme tokens should be repainted beyond the current honey/paper/ink overrides. `card`, `popover`, `sidebar`, `input`, `secondary`, `destructive`, and chart/code tokens were intentionally left closer to upstream defaults during the booking-flow UI consolidation.
- Review whether the dashboard calendar should eventually be aligned more closely with the newer booking-flow primitive usage, but keep that as a dedicated follow-up rather than bundling it into unrelated UI work.
- **Dashboard banner stack a11y polish** (deferred from PAYMENTS Hardening Round 2 / Codex G-008). `resources/js/components/dashboard/dashboard-banner.tsx` always renders `role="alert"` and has no dismiss/acknowledge path. Banner stack includes subscription-lapse, payment-mode mismatch, and unbookable-services notices; assertive announcement on every render is noisy for screen-reader users. Make `role` configurable (`alert` only for urgent destructive states, `status` for advisory) and add an optional dismiss action for non-blocking banners. Out of payments scope; UI session of its own.
- **Refund-dialog input accepts CH locale formats** (deferred from PAYMENTS Hardening Round 2 / Codex G-003 partial). The `formatMoney` helper now handles DISPLAY (Intl.NumberFormat). The amount INPUT in `resources/js/components/dashboard/refund-dialog.tsx` still uses `Number(trimmed)` which rejects the CH operator-natural `10,50` and `1'000.50`. Normalize apostrophe thousands and comma decimals into integer cents before submit. Server stays the source of truth (`StoreBookingRefundRequest` requires integer cents). Focused UX session.

## UX Ideas

- Consider Inertia polling for the dashboard home so appointment counts refresh without a manual reload once the app has real traffic.
- Evaluate link prefetching for calendar navigation and adjacent booking screens after the calendar work is complete enough to measure perceived performance.
- Revisit scroll preservation on the bookings list so returning from detail views or panels does not reset position unnecessarily.

## Calendar — ICS one-way feed (deferred from ROADMAP-CALENDAR Phase 1)

- A signed per-user `.ics` feed URL that any calendar app can subscribe to (Google, Apple, Outlook). Read-only, poll-based (1–24h refresh depending on the client), zero OAuth.
- Deferred on 2026-04-16 in favour of going straight to the bidirectional Google integration (MVPC-2, shipped). The OAuth integration covers the full set of users who would want a feed plus the bidirectional flow that ICS cannot deliver.
- Revisit only if user research surfaces a real demand from providers who refuse to grant Google OAuth scopes but still want their riservo bookings on their personal calendar.
- Implementation sketch was in the pre-collapse `ROADMAP-CALENDAR.md §Phase 1 — Session C1` — recoverable from git history if this lands later.

## Technical Debt / Deferred Engineering Cleanup

- The onboarding logo upload path still reflects the older standalone-request implementation described in D-042. When that area is touched again, evaluate migrating it to the current Inertia v3 `useHttp` pattern.

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
- Source: REVIEW-1 §9 / ROADMAP-REVIEW-1 §R-16 (historical audit files; see git history at commit `1a30413`).

## Bookability (R-17 carry-overs)

- Admin email / push notification when a service crosses into structurally-unbookable post-launch (the in-app banner is MVP; out-of-band alerting is deferred).
- Richer "provider is on vacation" UX on the public page — today a legitimately temporarily-unavailable provider just produces zero slots; a date-aware "back on X" caption is post-MVP.
- Banner per-user dismiss / ack history — current banner auto-clears on fix; a "remind me later" UX is deferred.
- Source: `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md` D-078; ROADMAP-REVIEW-2 §R-17 (historical audit file; see git history).

## Payments (PAYMENTS Session 1 carry-overs)

- **Formal support-contact surface (D-118)**. The Connected Account settings page's disabled-state CTA links to a `mailto:support@riservo.ch` placeholder. There is no support-flow surface in the codebase today (grep for `support@riservo` returns nothing under `app/` or `resources/`). Pre-launch: replace with a real flow (help page, in-app contact form, or similar). The same placeholder will likely be reused from any other "talk to riservo" surface in later PAYMENTS sessions.
- **Per-Business country selector for Stripe Express onboarding (D-121 → superseded by D-141)**. Stripe Express country is permanent. D-141 made `businesses.country` the canonical source; D-143 seeds it at signup from `config('payments.default_onboarding_country')` (MVP = `'CH'`). When `supported_countries` expands, we need a UX that lets an admin PICK their country at signup (or in the business-onboarding wizard) instead of silently inheriting the config default. Post-MVP UX work — data model + gate are already in place.
- **Collect country during business onboarding (D-141, D-143 follow-up)**. The current default-seed approach (`config('payments.default_onboarding_country')`) works for CH-only MVP. Once riservo expands to multiple markets, the onboarding wizard must explicitly ask for the business's country before a Stripe account can be created. Until then the default-seed is a reasonable fallback.
- **Pre-role-middleware signed-URL session pinner (D-147 follow-up)**. D-147 derives authorisation from the signed Connect return URL's business, re-pinning session + `TenantContext` inside `resolveSignedAccountRow()`. But the outer `role:admin` middleware still runs BEFORE the controller against the pre-re-pin session tenant. A user who is STAFF-ONLY on their session-pinned tenant and ADMIN of the signed row's tenant 403s at middleware instead of getting re-pinned. Post-MVP fix: a middleware that inspects the signed `account` query param and re-pins `current_business_id` BEFORE `role:admin` runs. Acceptable false-negative for MVP (single-tenant-per-admin is the common case).

## Tenancy (R-19 carry-overs)

- **R-2B — Business-switcher UI in the dashboard header**. Multi-business membership is a data-model capability (D-063) and, post-R-19 (D-079), is now reachable through the invite flow. A user who belongs to more than one business today defaults to the oldest active membership with no in-app way to switch. Add a header dropdown that writes the chosen business id into `current_business_id`; `ResolveTenantContext` already handles the rest. Source: ROADMAP-REVIEW-1 §R-2 carry-over + ROADMAP-REVIEW-2 §R-19 (historical audit files; see git history).
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

## PAYMENTS — "Accept offline bookings when Stripe is temporarily unavailable" opt-out (deferred from PAYMENTS Session 5)

**Context.** PAYMENTS Session 5 (2026-04-24) shipped a behaviour change for `payment_mode = 'online'` businesses whose connected account is currently ineligible (KYC failure, disconnect, country drift, capability flip). Before: the public booking controller silently downgraded to the offline path — slot reserved, customer not charged, business's "require online payment" commercial contract silently violated. After: the controller returns a 422 with `online_payments_unavailable` BEFORE the transaction; the public booking page renders a pre-submit banner ("This business is no longer accepting online payments right now — try again later or contact them directly.") and disables the CTA when the business is online-only and Stripe is degraded at page-load.

The hard-block default is correct: a business that sets `payment_mode = 'online'` has made a commercial choice to require payment at booking (see roadmap locked decision #6 and #14). Silently creating a confirmed offline booking violates that.

**But there is a legitimate edge case the current baseline doesn't serve.** Some businesses choose `payment_mode = 'online'` primarily because they want every transaction recorded on Stripe for bookkeeping / reconciliation, not because the pre-payment itself is non-negotiable. For those businesses, a Stripe outage that blocks ALL bookings is a worse outcome than a temporary offline fallback — they'd rather accept bookings with on-site payment during the outage and reconcile manually, than lose customers entirely while Stripe recovers.

**Proposed UX.** A new Settings → Booking checkbox, visible only when `payment_mode = 'online'` (not for `offline` or `customer_choice`):

> **"Still accept bookings if my Stripe account is temporarily unavailable"**
>
> Description: "When this is on, customers can still book during a Stripe outage — they'll pay on-site instead of online, and you can reconcile manually. When off (default), bookings are blocked until Stripe is working again."

Default: **off** (hard-block) — matches the current post-Session-5 baseline and the commercial-contract reading of `payment_mode = 'online'`.

**Implementation sketch (when scheduled).**
- New column on `businesses` table: `accept_offline_fallback_on_stripe_outage` (boolean, default `false`). Migration additive; no data backfill needed (all existing rows get default `false` = current behaviour preserved).
- `Business::canAcceptOnlinePayments()` unchanged — it's the authoritative eligibility reader.
- `PublicBookingController::store` branches: when `$customerIntendedOnline && !$canAcceptOnline`, check the new flag. If `true`: fall to offline path (silent downgrade, as pre-Session-5); if `false`: throw `ValidationException::withMessages(['online_payments_unavailable' => ...])` (as post-Session-5).
- Same flag read on `booking-summary.tsx` so the UI mirrors the server: with flag on, show the normal "Confirm booking" flow; with flag off, show the "unavailable" banner.
- Settings → Booking UI: the checkbox renders below the `payment_mode` select only when the select's value is `'online'`. Wrap with `__()` / `t()`.
- A `paymentModeMismatch` banner in `authenticated-layout.tsx` can gain a secondary sentence for the flag-on case: "Customers can still book with on-site payment until Stripe is working again."
- Tests: at minimum, two new Feature tests on the public booking path (flag on + degraded → offline booking succeeds; flag off + degraded → 422); one Settings test that the flag is persisted; one Inertia-prop assertion on the shape.
- Documentation: promote a new `D-NNN` describing the opt-out contract + rationale. Update Session-5 D-176 with a "superseded-by" note once this ships.

**Decision on when to schedule.** To be evaluated in the next session or later. Not a blocker — the post-Session-5 baseline is defensible as a standalone product stance. The opt-out is a refinement that lands when a real-world request from an online-only business surfaces, or when product validation suggests enough online-only businesses would appreciate the flexibility during Stripe outages.

**Origin.** This entry exists because the behaviour change was not in the Session 5 plan approved by the developer — the implementing agent added it during execution without consultation. The developer course-corrected the process (explicit prompt-product-decisions rule) AND endorsed the hard-block default but asked for this opt-out to be documented as the right long-term surface. Keep the baseline; add the opt-out when prioritised.

## PAYMENTS — Post-hoc online payment link for manual bookings (deferred from locked decision #30)

- Locked decision #30: manual bookings (phone / walk-in, `source = manual`) are always created with `payment_mode_at_creation = 'offline'` and `payment_status = not_applicable`, irrespective of the Business's current `payment_mode`. Rationale: the customer is not in front of the staff member to authorise a charge at creation time.
- Future enhancement: allow the admin to send a payment link to the customer out-of-band (email or SMS). Likely shape: a button on the manual-booking detail page that mints a fresh `Stripe\Checkout\Session::create` against the existing booking row, stores the session URL, and emits an email to the customer with the link; on `checkout.session.completed`, the booking transitions to `paid`.
- Requires: a fresh Checkout-session-per-booking endpoint, an out-of-band customer contact UX (copy / email template), and possibly a "link expired, resend" flow.
- Not scoped for MVP. Revisit when businesses surface the request ("I took a phone booking, I want to charge them now").

## PAYMENTS — Online payments for non-CH connected accounts (deferred from locked decision #43)

- MVP supports CH-located connected accounts only. The gate is `config('payments.supported_countries')` — default `['CH']` — read at every call site. Zero hardcoded `'CH'` literal in application code, tests, or Inertia props (verified by the Session 5 config-flip test that sets `supported_countries` to `['CH', 'DE']` mid-test and proves a DE-account passes the gate).
- The seams are already open: (a) the config is the single switch; (b) server-side country assertion in `PublicBookingController::store` + `CheckoutSessionFactory::assertSupportedCountry` fires on every Checkout-creation path; (c) the `payment_method_types` branching in Session 2a's Checkout configuration already hedges `card`-only for non-CH entries in the supported set (TWINT is CH-only via `config('payments.twint_countries')`).
- Fast-follow roadmap to extend to IT / DE / FR / AT / LI becomes:
  1. Flip `config('payments.supported_countries')` (env `PAYMENTS_SUPPORTED_COUNTRIES`) to the target list.
  2. Audit tax assumptions per locked decision #11 for each target country's VAT regime (Stripe Tax setup is the professional's responsibility; the riservo warning banner text may need per-country copy).
  3. Confirm Stripe Checkout supports the language matrix for each new country (locked decision #39 / D-008 locales are IT / DE / FR / EN).
  4. Re-verify the `card`-only-fallback UX against a real test account in each target country.
  5. Locale-list audit (D-43): update all CH-centric copy — the "Online payments in MVP support CH-located businesses only." messages in Settings validator and React tooltip need rewrite; the Session 4 payouts non-CH banner needs rewrite.
- No application refactor expected — the infrastructure work is done. The roadmap becomes mostly config + copy + Stripe test-account verification.

## PAYMENTS — Payment conversion analytics

- Capture funnel metrics for booking attempts → Checkout initiated → Checkout completed → refunded. Gives the product team insight into the online-payment feature's impact post-launch: how many customers abandon at the payment step, how many prepay vs pay-on-site when offered the choice, refund rates by reason.
- Likely events to instrument (telemetry provider TBD):
  - `booking.created` with `payment_mode_at_creation`, `payment_choice` (if customer_choice), `booking.status`, `booking.payment_status`
  - `checkout.session.created` (server-side when minting)
  - `checkout.session.completed` / `checkout.session.expired` / `payment_intent.payment_failed` (webhook-driven)
  - `refund.initiated` with `reason`, `amount_cents`, `initiator` (customer / admin / system)
  - `refund.succeeded` / `refund.failed` (webhook-driven)
- Not scheduled in this roadmap to keep the shipping surface tight. Revisit once the feature has real traffic and the product team has a clear analytics stack in mind.

## PAYMENTS — Consolidated Connect UX for multi-business admins (deferred from locked decision #22)

- Locked decision #22 keeps connected accounts per-Business for MVP — a user who administers two businesses must onboard each one independently with a distinct Stripe Express account. Data model: `stripe_connected_accounts.business_id` is unique, not user-scoped.
- This is the correct architectural stance (commercial entities differ per business; tax obligations differ; Stripe account ownership differs) but creates UX friction for users admining multiple businesses: two separate onboarding flows, two separate dashboards, two separate refresh URLs.
- A post-MVP polish pass can introduce a single onboarding flow that walks through each Business's Stripe connection in sequence, a unified "Connected Accounts" overview (switchable via the existing tenant switcher), and batched health banners ("One of your businesses' Stripe accounts needs attention").
- Not scheduled. Revisit when real multi-business admin feedback surfaces the friction.

## PAYMENTS — In-dashboard dispute evidence flow (deferred from locked decision #25)

- Locked decision #25 punts dispute resolution to the Stripe Express dashboard. riservo only surfaces awareness (a Pending Action + admin email on `charge.dispute.created`, deep-links to the dispute in the Stripe dashboard, resolution summary on `charge.dispute.closed`). The actual evidence upload, deadline tracking, and win/lose outcome all happen in Stripe's UI.
- A future session can surface:
  - Evidence upload inline in the dashboard (attach receipts, communication transcripts, service delivery proof)
  - Dispute deadline countdown with proactive reminder emails
  - Dispute history / win rate per business
- Requires: comfort with Stripe's dispute API surface (`Stripe\Dispute::update` with `evidence` param), file upload UX, and enough dispute volume in production to justify the investment. Until then, the Stripe dashboard deep-link is sufficient.
- Revisit once dispute volume surfaces a real need.

## PAYMENTS — Deposit-based bookings (deferred from locked decision #7)

- Locked decision #7: "full charge upfront, not a deposit. Deposit-plus-balance is deferred — it introduces a second charge flow and an offline reconciliation surface we do not want in this roadmap." MVP charges the full `Service.price` at booking; there is no notion of "pay 20% now, 80% at the appointment".
- Relevant for high-ticket verticals (coaching, consulting, weddings, destination services, equipment rental) where a full upfront charge creates psychological friction but a deposit meaningfully reduces no-show risk.
- Implementation sketch (when scheduled):
  - New column on `services` table: `deposit_percentage` (nullable integer 1..100) or `deposit_amount_cents` (nullable integer). Mutually exclusive.
  - Booking creation path branches: when `deposit_*` is set AND `payment_mode = online`, Stripe Checkout charges `deposit_amount_cents` only; `paid_amount_cents` on the booking reflects the deposit; `balance_due_cents` column tracks the remainder.
  - Balance-due flow: (a) collected offline at appointment and marked via admin button, OR (b) a second Checkout session minted before/at appointment and paid online. Both must be supported per business preference.
  - Refund logic (locked decision #37): `remainingRefundableCents()` is clamped to `paid_amount_cents` (deposit already charged); the balance is not refundable because it was never taken.
  - Customer-facing UI: the booking summary must clearly distinguish "Pay CHF X.XX deposit now. Balance CHF Y.YY due at appointment." — not "Your card will be charged CHF Z on the next step." (today's single-charge copy).
  - Post-hoc via admin — out of scope for the deposit roadmap (already BACKLOG as "Post-hoc online payment link for manual bookings").
- Not scoped for MVP. Revisit when a professional verticals survey shows ≥20% of onboarded businesses would enable deposits.

## PAYMENTS — Per-business refund-policy override (deferred from locked decision #15)

- Locked decision #15: "Refund on customer cancel INSIDE the cancellation window = full automatic refund. No business-level toggle in this roadmap; the cancellation window is already the business's stated policy (D-016), so honouring it fully is the only consistent stance. A per-business 'no-refund' override is a v2 request if it materialises."
- Some businesses (e.g. event-based, equipment already prepared) want a "no-refund even inside the cancellation window" policy, or a "partial-refund inside window" policy (70% back, 30% retained as cancellation fee). Today the only knob is the cancellation window itself.
- Implementation sketch (when scheduled):
  - New column on `businesses` table: `customer_cancel_refund_policy` enum (`full` | `partial_{percentage}` | `none`). Default `full` preserves current behaviour.
  - `CustomerBookingController::cancel` branches: the existing automatic-refund dispatch reads the policy and either passes a partial amount or skips the refund entirely.
  - Settings → Booking gains a new control below the cancellation window. Copy: "What happens when a customer cancels inside the window? Full refund (default) / Partial refund (keep X% as a cancellation fee) / No refund."
  - Customer-facing: the cancellation page copy must branch accordingly ("You will receive a full refund" / "You will receive CHF X.XX (70% of CHF Y.YY)" / "Cancellation confirmed — no refund per the business's policy").
  - Email template (`BookingCancelledNotification`) branches similarly; the D-175 `$refundIssued` flag is extended to an enum (`full` | `partial` | `none`) or a new `$refundAmountCents` field.
- Not scoped for MVP. Revisit when ≥3 onboarded businesses request the ability to retain a fee on in-window cancels.

## PAYMENTS — No-show automatic refund policy (deferred from Session 3 "out of scope")

- Session 3 "Out of scope" line: "refund-on-no-show automatic policy (we default to no automatic refund on no-show — admin manual only)". Today the admin marks a booking as No-Show via the status transition; the charge stands and no refund is dispatched automatically. The admin can still issue a manual refund from the booking detail.
- A future policy layer could let a business configure automatic partial refunds on no-show (e.g. keep 50% as a no-show fee, refund the rest), or automatic full refunds for a "no questions asked" customer-friendly stance.
- Implementation sketch: parallel to the refund-policy override above — a new `no_show_refund_policy` column on `businesses` (or a combined policy object). The No-Show status transition dispatches the policy outcome via `RefundService::refund` with a new reason `no-show-auto` (additions to the 5-reason vocabulary). Requires decision #37's clamp logic plus UI copy in the admin booking detail and the customer email.
- Not scoped for MVP. Revisit when customer-facing feedback on post-launch no-show UX surfaces a policy gap.

## PAYMENTS — Failed payout notification / banner (deferred from locked decision #18)

- Locked decision #18: "Stripe handles this via its platform-wide debit-on-file default: if the connected account balance is insufficient, Stripe debits the account's attached bank. Riservo does not block the refund and does not surface the debit mechanics in the UI — the professional owns that relationship with Stripe."
- The same reasoning applies to failed **payouts** (Stripe → business's bank): if a payout is rejected by the business's bank (closed account, insufficient-details return, bank holiday edge case), Stripe emits `payout.failed` — but riservo does not subscribe to this event and surfaces nothing. The professional discovers it only by logging into the Stripe Express dashboard and noticing an unpaid balance.
- Pre-launch concern: some businesses will miss the Stripe-only notification and lose days of income before realising. A riservo-side surface is modest effort and meaningfully improves trust.
- Implementation sketch:
  - Subscribe `payout.failed` + `payout.paid` on the Connect webhook endpoint (one-line addition to the subscription list).
  - Handler writes a `payment.payout_failed` Pending Action (admin-only per decisions #19 / #31 / #35 / #36) with the verbatim Stripe failure reason and a deep-link to the Stripe Express dashboard payouts page.
  - Dashboard payouts page (Session 4's surface) surfaces a banner when `payout.failed` is unresolved, in addition to the existing health strip.
  - Admin-visible email dispatched once per failed payout (rate-limited to avoid spam if Stripe retries).
  - `payout.paid` resolves the Pending Action silently.
- Not scoped for MVP. Revisit pre-launch if operational feedback suggests the risk of missing this surface is non-trivial.

## PAYMENTS — Additional payment methods beyond card + TWINT (post-MVP)

- Locked decision #4: "Hosted Stripe Checkout, not embedded Elements — less PCI surface, TWINT + card out of the box, 3DS / SCA handled automatically." Session 2a's `payment_method_types = [card, twint]` is the complete PM set in MVP.
- Stripe Checkout natively supports many more PMs: SEPA direct debit, Apple Pay / Google Pay (via wallet inference — already surfaced if browser supports it), PostFinance Pay (CH), Klarna, iDEAL (NL), Bancontact (BE), SOFORT. Most are enabled by toggling the Checkout session config.
- Relevance: post-launch feedback may surface demand — e.g. Swiss customers expecting PostFinance Pay; fast-follow non-CH roadmap extending to DE / AT bringing SEPA expectations.
- Implementation sketch:
  - New config key `payments.allowed_payment_methods` (env `PAYMENTS_ALLOWED_PAYMENT_METHODS`, comma-separated) — additive set that gets filtered per connected-account country.
  - Per-country allow-list branching (similar to the existing `twint_countries` gate): a helper `resolveCheckoutPaymentMethodTypes($account): array` that returns the intersection.
  - Optional Settings toggle per business to opt into specific PMs beyond the default set (e.g. "Also accept Klarna — customers pay over time").
  - Testing: the `TESTING-STRIPE-END-TO-END.md` Part 0 note about TWINT not being completable in test mode applies to most of these — manual verification in Stripe's test account.
- Not scoped for MVP. Revisit when (a) fast-follow non-CH ships, or (b) explicit customer feedback points at a missing PM.

## PAYMENTS — Customer-side receipt / invoice retrieval in riservo (post-MVP, deferred from locked decision #9)

- Locked decision #9: "Invoice generation is Stripe-side. The connected account's Stripe dashboard surfaces PDF invoices automatically; riservo does not generate a parallel invoice document. ... A riservo-native invoicing engine is a v2 product consideration, not an MVP requirement for this roadmap."
- Customers receive a plain receipt email from Stripe at payment time. There is no riservo surface (customer-side booking management page, email, etc.) that lets a customer retrieve their receipt later, nor any surface to print / download an invoice if they need one for accounting.
- This is genuinely a v2 product consideration — CH customers are generally OK with a Stripe-origin receipt for small transactions, but B2B-flavoured bookings (workshops, professional services) will surface the gap.
- Implementation sketch:
  - Option A (light): `/my-bookings/{token}` surfaces a "View receipt" deep-link that calls `Stripe\Charge::retrieve` + `receipt_url` from the charge and opens it in a new tab. Leverages Stripe's hosted receipt. Minimal code, no riservo-side document.
  - Option B (heavy): riservo generates a PDF invoice from a template with the Business's branding, the booking details, VAT (if the Business has Stripe Tax configured — else a disclaimer), and the paid amount. Requires a PDF rendering pipeline (dompdf or browsershot) and a per-business branding config.
  - Option A is 80% of the value for 20% of the effort; option B is the "full invoicing engine" that locked decision #9 punted.
- Not scoped for MVP. Start with Option A pre-launch if customer feedback surfaces the gap; Option B only if a B2B vertical picks up.

## PAYMENTS — Bulk refund / batch operations (post-MVP)

- Today refunds are per-booking: an admin clicks the Refund button on the booking detail. There is no "refund all bookings for next Tuesday because I'm sick" batch action.
- Relevant when a business has to cancel an entire time window (illness, strike, venue closure, equipment failure, etc.). Manually refunding 30 bookings one-by-one is painful.
- Implementation sketch:
  - Bookings list view gains a multi-select checkbox column + a "Refund selected" action button.
  - Action opens a dialog: "You are about to refund N bookings (total CHF X.XX). Reason: __________". Dispatch is sequential via `RefundService::refund` per booking — the row-UUID idempotency key (locked decision #36) is per-booking so there is no collision.
  - A background job (not inline) to avoid long admin-blocking requests. Progress surfaced via a Pending Action-style UI strip ("Refunding 14 of 30...").
  - Partial-success handling: if 5 of 30 fail (disconnected Stripe, etc.), the Pending Action surface lists the failures so the admin can retry or resolve offline.
- Not scoped for MVP. Revisit when a post-launch incident retrospective shows an admin needing this capability.

## PAYMENTS — Financial reports / monthly statements in dashboard (post-MVP)

- Stripe generates comprehensive financial reports (gross revenue, fees, net payouts, refunds, disputes, tax) on a rolling basis in the Express dashboard. riservo's Session 4 payouts page surfaces real-time balance + recent payouts but does not summarise over a period ("revenue this month", "average ticket size", "refund rate").
- Post-launch, professionals running their books will want this visible in riservo (one surface for operations + finance) rather than jumping between dashboards.
- Implementation sketch:
  - Extend `Dashboard\PayoutsController` (or add a new `Dashboard\FinancialsController`) with a date-range filter, rolling revenue / refunds / fees aggregates fetched from Stripe (`balanceTransactions.list` with `created[gte]` / `created[lte]`), and a downloadable CSV for external accounting tools.
  - Caching strategy mirrors Session 4's two-layer cache (60s freshness + 24h fallback); lift to a shared helper per the Session 4 carry-over note in HANDOFF.
  - UI: a simple "Financials" tab or sub-page with a date picker, summary cards, and a per-month bar chart.
  - Could also include CSV export of all bookings + their payment status over a period for per-business VAT reconciliation.
- Not scoped for MVP. Revisit 3–6 months post-launch when real traffic produces enough data to make the surface useful.

## PAYMENTS — Stripe Tax pre-launch walkthrough / configuration guide (pre-launch consideration)

- Locked decision #11: "Stripe Tax not configured = warning banner, not hard block. Many small Swiss businesses have never enabled Stripe Tax. ... Soft enforcement; the professional decides." Today the Connected Account page + the Payouts page surface a warning banner when `tax_settings.status !== 'active'` deep-linking to the Stripe Tax setup page.
- What's missing: a riservo-side walkthrough or help article that explains (a) what Stripe Tax is, (b) why it matters for CH VAT on customer receipts, (c) the step-by-step to enable it in the Stripe dashboard, (d) the thresholds (CH VAT registration obligation kicks in at CHF 100k turnover — below that threshold many small businesses legitimately don't need it). Without this, professionals will either dismiss the warning or disable it without understanding.
- Implementation sketch:
  - A `/help/stripe-tax` page or a modal that opens from the Connected Account + Payouts banner CTA.
  - Content is essentially a structured FAQ — 4 sections, CH-specific content (future-proof for non-CH via the locale-audit process when `supported_countries` expands).
  - Links to Stripe's official Stripe Tax docs + a direct deep-link to the CH Stripe Tax setup in the Express dashboard.
- Not a code-engineering concern — more a content / docs task. Pre-launch consideration.

## PAYMENTS — Subscription / package bookings (post-MVP, big feature)

- MVP charges a single `Service.price` per booking. There is no notion of "buy a pack of 8 pilates sessions upfront for CHF 240 and book them one at a time". This is a significant vertical-specific feature (fitness, beauty, coaching, language tutoring) that a large fraction of service businesses offer.
- Requires substantial new data model: `service_packages` table, `customer_package_balances`, redemption logic on booking creation, expiry tracking, admin-side balance management. Also impacts refund logic (package-wide refund vs single-session refund clamp).
- Implementation sketch (rough — not a scoped session):
  - New table `service_packages`: `id`, `business_id`, `name`, `service_id` (or polymorphic `services_included`), `session_count`, `price_cents`, `validity_days`, `is_active`.
  - New table `customer_package_purchases`: `id`, `customer_id`, `package_id`, `purchased_at`, `expires_at`, `stripe_payment_intent_id`, `sessions_remaining`.
  - Booking creation path branches: if the customer has an active package covering the selected service, redeem one session (decrement `sessions_remaining`) and create a booking at `payment_status = redeemed_from_package` (new enum value) — no Stripe Checkout. Else normal flow.
  - Dashboard surfaces: per-customer package balance on the customer detail page; per-business package sales report; expiring-soon alerts.
  - Refund logic: partial refunds mid-package are complex (refund at prorated session price vs full-minus-used); pick one stance and document.
- Not scoped for MVP. A dedicated roadmap when prioritised — likely a 4–6 session effort comparable to the PAYMENTS roadmap itself.
