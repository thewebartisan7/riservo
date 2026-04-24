# PAYMENTS Session 5 — `payment_mode` Toggle Activation + UI Polish

This plan lives at `docs/PLAN.md` and follows `.claude/references/PLAN.md`. It is a living document — the sections `Progress`, `Surprises & Discoveries`, `Decision Log`, `Review`, and `Outcomes & Retrospective` are kept up to date as work proceeds. Session 5 closes the PAYMENTS roadmap.

Prerequisite sessions (1, 2a, 2b, 3, 4) have shipped on `main`. The iteration-loop baseline at session start is **954 tests / 4079 assertions** (from `docs/HANDOFF.md`, Session 4 close); PHPStan level 5 / `app/` clean; Pint clean; Vite build green; Wayfinder up to date.


## Purpose / Big Picture

For the last four sessions, Settings → Booking has shown only one `payment_mode` option (`offline`). The `online` and `customer_choice` options have been hidden from the `<Select>` list and hard-blocked by the server-side validator (D-132), even for Stripe-verified businesses. That hide was deliberate — Sessions 1–4 shipped the data layer, Stripe Connect onboarding, Checkout flow, refunds, disputes, and the Payouts surface, but it was never safe for an admin to flip `payment_mode` to a non-offline value until every path downstream was consuming it. All of that now works.

After this session:

1. A Swiss business whose Stripe-verified connected account is "active" can open Settings → Booking, pick **"Customers pay when booking"** or **"Customers choose at checkout"**, and save — no more hidden option, no more 422. Their public booking page (`/{slug}`) starts behaving as Session 2a/2b designed: online bookings redirect to Stripe Checkout, customer_choice bookings show a pay-now / pay-on-site pill, failure branching lands sensibly, refunds fire on admin rejection of paid-pending bookings (locked decision #29).
2. A business that has **not** connected Stripe, or that has connected Stripe in a country outside `config('payments.supported_countries')` (`['CH']` in MVP), sees the non-offline options rendered disabled with a tooltip explaining exactly why. The disable is a UX convenience — the server-side validator rejects the same with a 422, so a direct PUT cannot bypass it.
3. Public-booking copy around the payment step reads cleanly (Stripe attribution, the exact CHF amount the card will be charged, a race banner when the business's connected account becomes disabled between page load and form submit).
4. The dashboard banner stack (subscription, payment-mode mismatch, unbookable services) has one consistent shape — no three competing variants.
5. Every user-facing string introduced across Sessions 1–5 goes through `__()` / `t()`.

Acceptance is verifiable by walking Settings → Booking as four distinct admins (no connected account; pending connected account; CH-active; DE-active after a mid-test `config()` flip), running `php artisan test tests/Feature tests/Unit --compact`, and watching the baseline grow without regressions.


## Progress

Checkbox list of granular steps. Every stopping point is documented here. Always reflects the true current state.

### Milestone 1 — Server-side gate lift
- [x] (2026-04-24 11:45Z) Rewrote `paymentModeRolloutRule()` in `app/Http/Requests/Dashboard/Settings/UpdateBookingSettingsRequest.php` to accept non-offline iff `canAcceptOnlinePayments()` returns true; idempotent passthrough preserved. Updated the docblock to describe the post-Session-5 state (D-132 transitional block retired).
- [x] (2026-04-24 11:45Z) Rewrote the D-132 rejection test to its positive shape; added three new tests: `admin can PUT online when verified CH`, `admin can PUT customer_choice when verified CH`, `non-CH rejected server-side even when UI would hide`, `config flip opens the seam for non-CH`. Idempotent-passthrough test kept unchanged.

### Milestone 2 — Settings → Booking UI unhide + gate
- [x] (2026-04-24 12:00Z) Extended `BookingSettingsController::edit()` with a `paymentEligibility` Inertia prop block (five fields). Takes care of tooltip copy selection and final enable/disable bit in one reader.
- [x] (2026-04-24 12:10Z) Rewrote `paymentModeItems` in `resources/js/pages/dashboard/settings/booking.tsx` to expose all three options with per-item `disabled` + `reason`. Each disabled item shows a small muted sub-text inline (more discoverable than a hover tooltip inside a select popup) AND carries a `title=""` attribute as a backup. Copy matches locked decision #29 and the priority-ordered tooltip copy.
- [x] (2026-04-24 12:10Z) Added the `confirmation_mode = manual × online/customer_choice` inline hint. Uses `useState` bindings on both controls so the hint re-renders as the admin toggles either.

### Milestone 3 — Public-booking UI polish + race banner
- [x] (2026-04-24 12:20Z) Added "Secured by Stripe" + amount microcopy under the "Continue to payment" CTA in `booking-summary.tsx`. Uses `formatPrice(service.price, t)` for the amount — currency-aware.
- [x] (2026-04-24 12:20Z) Server-computed `twint_available` boolean added to the `business` prop in `PublicBookingController::show`; reads from `config('payments.twint_countries')`. TS type `PublicBusiness` updated.
- [x] (2026-04-24 12:25Z) Race banner: added structured `reason` codes to every 4xx in `PublicBookingController` (`slot_taken`, `no_provider`, `online_payments_unavailable`, `checkout_failed`). The `UnsupportedCountryForCheckout` copy also updated to the roadmap's race-banner wording.
- [x] (2026-04-24 12:30Z) NEW: `store()` now returns 422 with `reason=online_payments_unavailable` BEFORE the transaction when the customer intended online but `canAcceptOnlinePayments()` is false (previously it silently downgraded to offline). This closes a UX gap that the roadmap's race-banner bullet implies but that Session 2a did not wire. See `## Decision Log`.
- [x] (2026-04-24 12:30Z) `booking-summary.tsx` switches on `http.errors.reason` to pick the right banner copy; helper `errorCopyForReason` at the bottom of the file.

### Milestone 4 — Banner stack consolidation + i18n audit
- [x] (2026-04-24 12:40Z) Created `resources/js/components/dashboard/dashboard-banner.tsx` (chose `components/dashboard/` over `components/layout/` for consistency with existing project structure). Rewrote all three banner call sites in `authenticated-layout.tsx`. Removed the now-unused `Alert*`/`AlertTriangleIcon` imports from the layout.
- [x] (2026-04-24 12:45Z) i18n audit: grepped the Session 1–4 diff for unwrapped user-facing strings. Only hit was `placeholder="0.00"` on the refund dialog (numeric format, not a translatable string) — left as-is. PHP side has only webhook server-to-server response bodies (`'Unknown account — retry.'`) and internal-only failure-reason strings (not user-facing). Clean.
- [x] (2026-04-24 12:45Z) Verified `paymentModeMismatch` banner copy — initially left unchanged ("falls back to offline") on the assumption the Connect-webhook demotion path was the right mental model. Round 2 of the codex adversarial review (Finding 2) flagged that copy as false under D-176's 422 behaviour: the backend no longer falls back to offline for online-intended bookings. Copy rewritten to "Customers trying to pay online will be refused at checkout until this is resolved."

### Milestone 5 — payment_mode branching audit + cleanup
- [x] (2026-04-24 12:50Z) Rewrote `PublicBookingController.php:383`'s `expires_at` ternary to an explicit `match()` on PaymentMode. Locked decision #13's "only online sets expires_at" intent is now visible at the call site.
- [x] (2026-04-24 12:50Z) Grepped remaining `payment_mode` dereferences in `app/`. All other sites use enum comparisons and are correct. The single `!== 'offline'` raw-string match in `Booking::isOnlinePayment()` reads the `payment_mode_at_creation` SNAPSHOT column (raw string by design per locked decision #14), not the enum; correct as-is.
- [x] (2026-04-24 12:50Z) Reserved-slug list confirmed: `SlugService::RESERVED_SLUGS` does not need changes — `/dashboard/*` sits outside the public `/{slug}` namespace. No collision risk with Session 4's `/dashboard/payouts` or any Session 5 addition.

### Milestone 6 — Tests
- [x] (2026-04-24 13:00Z) Settings matrix: +5 tests in `tests/Feature/Settings/BookingSettingsTest.php` (D-132 rewrite + 4 new including config-flip); +2 eligibility-prop Inertia assertions (active CH, active DE).
- [x] (2026-04-24 13:00Z) `customer_choice` end-to-end paths already covered by Session 2a's `OnlinePaymentCheckoutTest.php` tests at lines 144 and 159; no new file needed.
- [x] (2026-04-24 13:05Z) Race test + new "customer_choice + pay-on-site still works when ineligible" test added to `OnlinePaymentCheckoutTest.php`. The prior silent-downgrade test rewritten to the new 422 shape.
- [x] (2026-04-24 13:05Z) Locked-decision-#29 variants both already proven by Session 3's `PaidCancellationRefundTest.php:190` (pending+paid rejection → refund) and `:218` (pending+unpaid rejection → no refund). Tests already kept them in sync with the `$refundIssued` template flag (D-175). No new tests needed.
- [x] (2026-04-24 13:10Z) Updated browser test `tests/Browser/Settings/BookingSettingsTest.php` to seed an active connected account before the `updates payment_mode to online` HTTP put (otherwise the new validator rejects it).

### Milestone 7 — Close
- [x] (2026-04-24 13:15Z) Full iteration loop green:
    - `php artisan test tests/Feature tests/Unit --compact` → 961 / 4146 (baseline was 954 / 4079; +7 tests, +67 assertions).
    - `vendor/bin/pint --dirty --format agent` → pass.
    - `php artisan wayfinder:generate` → regenerated idempotently.
    - `./vendor/bin/phpstan` → No errors.
    - `npm run build` → clean (pre-existing >500 kB chunk warning unchanged).
- [x] (2026-04-24 13:20Z) Promoted **D-176** into `docs/decisions/DECISIONS-PAYMENTS.md` (race-banner 422 contract: customer-intended-online × ineligible account returns 422 with `reason=online_payments_unavailable` instead of silently downgrading to offline — supersedes Session 2a's silent-fallback behaviour).
- [x] (2026-04-24 13:25Z) Rewrote `docs/HANDOFF.md` — PAYMENTS roadmap closed. Next free decision ID → D-177.
- [ ] Run `/codex:review` against the staged diff; apply findings under `## Review — Round N`.
- [ ] Stage final artifacts; developer commits.


## Surprises & Discoveries

- **Observation**: Session 2a intentionally chose to **silently downgrade** a `payment_mode = online` booking to the offline path when `canAcceptOnlinePayments()` returns false mid-session (KYC failure, country drift, capability flip). The pre-Session-5 test `can_accept_online_payments false drops the online branch even when payment_mode = online` codified that behaviour with `assertCreated()`.
  **Evidence**: `tests/Feature/Booking/OnlinePaymentCheckoutTest.php:251-267` before this session; `app/Http/Controllers/Booking/PublicBookingController.php::store()` — the pre-Session-5 `$needsOnlinePayment` branching falls through to the offline `$attrs` block when `$canAcceptOnline === false`, so the booking was created at `confirmed + not_applicable`.
  **Consequence**: the roadmap's race-banner requirement cannot be satisfied without changing this branch. Session 5 now returns 422 with `reason=online_payments_unavailable` BEFORE the transaction when the customer intended online but the server can't fulfil. The old test is rewritten to expect 422 + `Booking::count() === 0`. A new test proves the soft path: `customer_choice` + customer-picked-offline still lands offline even if `canAcceptOnlinePayments()` is false — the offline fallback is correct only when offline was the customer's explicit choice. Documented as **D-176**.

- **Observation**: for a DE-active connected account, `verificationStatus()` returns `'unsupported_market'` (not `'active'`, per D-150). If the Settings `paymentEligibility` prop used `verificationStatus() === 'active'` as its `has_verified_account` signal, a DE-active admin would see the "Connect Stripe and finish onboarding" tooltip instead of the "non-CH" tooltip — wrong priority order.
  **Evidence**: initial test failure `Settings → Booking eligibility prop reports a DE active account as ineligible` failed on `has_verified_account=true` vs expected `false` with the naive mapping.
  **Consequence**: `has_verified_account` now reads the raw Stripe capability booleans (`charges_enabled && payouts_enabled && details_submitted && requirements_disabled_reason === null`) independent of country. Country is checked via the separate `country_supported` flag. The final `can_accept_online_payments` aggregate is authoritative for enable/disable; the other two select the correct tooltip copy. Matches the roadmap's priority order ("not connected" → "non-supported country" → reserved).

- **Observation**: the tooltip-on-disabled-item pattern inside a Base UI `<Select>` popup is awkward in practice — Base UI sets `pointer-events:none` on disabled items (via `data-disabled:pointer-events-none`), so hover tooltips don't fire on the disabled target. Mobile users never hover at all.
  **Evidence**: verified by reading `resources/js/components/ui/select.tsx:179` (SelectItem's cva class).
  **Consequence**: rather than fight the primitive, Session 5 renders the tooltip copy inline as a small muted sub-text under each disabled option's label. Keeps the `title=""` attribute as a hover backup for pointer devices, but the primary signal is the visible sub-text — accessible on mobile, keyboard, and screen readers without any extra wiring.


## Decision Log

Session-5-specific technical decisions made during planning. Product / policy decisions are locked in the roadmap header (`## Cross-cutting decisions locked in this roadmap`) and `docs/decisions/DECISIONS-PAYMENTS.md`.

- **Decision** (2026-04-24): the server-side gate in `UpdateBookingSettingsRequest::paymentModeRolloutRule()` calls `Business::canAcceptOnlinePayments()` directly, rather than re-reading the country config and the capability booleans inline. The helper already folds country + capability + `requirements_disabled_reason` (D-127 / D-138 / D-150). Inlining would duplicate the chain and drift from the single source of truth. The client-side disable logic in `booking.tsx` reads the same aggregate via the new Inertia `paymentEligibility` prop rather than reconstructing it in JS.
  **Rationale**: D-127's whole point was "one reader, one chain of truth". Re-implementing it at the validator call site would let the two readers drift.

- **Decision** (2026-04-24): the Settings React page takes three distinct flags (`has_verified_account`, `country_supported`, `can_accept_online_payments`) rather than a single boolean, so the tooltip copy can pick the most-specific reason. Priority order per the roadmap: not connected ("Connect Stripe and finish onboarding to enable online payments.") → country mismatch ("Online payments in MVP support CH-located businesses only.") → future gates (currently no-op). `can_accept_online_payments` is the authoritative bit for enabling/disabling; the other two are for picking the right tooltip string.
  **Rationale**: the roadmap explicitly names the priority order; three flags let the UI pick the right message without a ternary chain that silently drops the middle case.

- **Decision** (2026-04-24): the race banner on `booking-summary.tsx` reads a structured `reason` field returned by the `PublicBookingController::store` 422 payload, not a substring match on the human-readable `message`. Today the controller only returns `{message}` for both the country mismatch (`UnsupportedCountryForCheckout`) and the generic Stripe failure (`ApiErrorException`). A `reason` field (`online_payments_unavailable` | `checkout_failed` | `slot_taken`) makes the client branch explicit and unbreakable by i18n/copy edits.
  **Rationale**: string-matching error copy is a brittle anti-pattern. One field, three stable values, three localized banners. Consistent with the D-161 pattern (explicit server-computed `external_redirect` flag) already used by Session 2a.

- **Decision** (2026-04-24): the dashboard banner consolidation is a presentational wrapper (one `<DashboardBanner>` component in `resources/js/components/dashboard/` — chosen for consistency with existing project structure; there is no `components/layout/` directory) — NOT a new priority / stacking system. All three existing banners already render in the same wrapper div inside `AuthenticatedLayout`; extracting the wrapper removes the three-way div duplication and centralises padding / spacing tokens, but keeps each banner's copy, variant, testId, and action wiring at the callsite.
  **Rationale**: the roadmap says "one banner stacking / styling system, not three" — today's issue is duplicated wrapper markup, not a stacking bug. A priority system (which banner wins when two apply) is a post-MVP concern if banner fatigue becomes a real problem. YAGNI — don't build an abstraction for problems we don't have.

- **Decision** (2026-04-24): no new `D-NNN` is expected for Session 5 *unless* the race-banner reason-field change reshapes the `PublicBookingController::store` response contract in a way a future implementer needs to know about. If so, that's the single new decision and it lands at D-176. The gate-lift, UI unhide, copy work, branching audit, and banner wrapper all flow cleanly from locked roadmap decisions #27, #29, #43 and existing D-127 / D-132 / D-138 / D-150. D-132 is not "superseded" — it was a transitional constraint ("hard-block until Session 5 ships"), and Session 5 shipping satisfies it.
  **Rationale**: not every implementation gets a decision ID; only the ones a future reader could not derive from the current codebase + existing decisions. The gate-lift is the most literal expected outcome of D-132's "Session 5 will swap this for an end-to-end check" note.

- **Decision** (2026-04-24): promoted the race-banner behaviour change to **D-176** — `PublicBookingController::store` returns 422 with `reason=online_payments_unavailable` when the customer intended online payment but `canAcceptOnlinePayments()` returns false at submit time, replacing Session 2a's silent offline downgrade. The promotion is warranted because (a) it's a behaviour-visible contract change that flips one existing test's expectation (201 → 422), (b) it introduces a new JSON response schema (the `reason` discriminator on 4xx), and (c) a future implementer who reads only the decision files needs to see why the silent-downgrade test was replaced. Recorded in `docs/decisions/DECISIONS-PAYMENTS.md`.
  **Rationale**: satisfies the "future reader cannot derive this from the current code" bar. The silent-downgrade was deliberate in Session 2a and documented in its test; flipping it without a decision record would leave the test history confusing.

- **Decision** (2026-04-24): tooltip-on-disabled-item strategy is **inline muted sub-text under the option label**, with a `title=""` attribute as a hover backup, rather than a Base UI `<Tooltip>` wrapping each `<SelectItem>`. Motivation: Base UI sets `pointer-events:none` on disabled items, which blocks hover tooltips from firing; mobile users never hover at all. The inline sub-text is immediately visible to every input modality (pointer, touch, keyboard, screen reader).
  **Rationale**: don't fight the primitive for a cosmetic improvement. The priority-ordered reason copy is information the admin needs at a glance — inline wins over a hover-only surface that half the users can't see.

- **Decision** (2026-04-24): `paymentEligibility.has_verified_account` reads the raw Stripe capability booleans (`charges_enabled && payouts_enabled && details_submitted && requirements_disabled_reason === null`), NOT `verificationStatus() === 'active'`. Rationale: a DE-active account returns `verificationStatus() === 'unsupported_market'` (D-150), which would make the React UI show the "Connect Stripe and finish onboarding" tooltip instead of the "non-CH" tooltip — wrong priority order. Separating the two concerns (Stripe verified vs country supported) into two flags lets the UI pick the correct reason copy. The aggregate `can_accept_online_payments` field stays the authoritative enable/disable bit.
  **Rationale**: surfaced during test failure. Keeping the two signals independent is cheaper than documenting a clever inversion, and matches the roadmap's explicit priority order ("not connected" → "non-CH" → reserved).


## Review

### Round 1

**Codex verdict**: four consistency issues in `docs/PLAN.md` itself — the narrative sections were written pre-implementation and never updated to match the reshaped reality captured in `## Progress` / `## Surprises & Discoveries` / `## Decision Log`.

- [x] **Finding 1** (high) — the pre-implementation code snippet for `BookingSettingsController::edit()` still uses `$row->verificationStatus() === 'active'` for `has_verified_account`, contradicting the later decision to use raw Stripe capability booleans (DE-active accounts would otherwise pick the wrong priority-ordered tooltip).
  *Location*: `docs/PLAN.md` — `## Plan of Work` § 2.
  *Fix*: replace the snippet to match the actually-shipped implementation (raw capability booleans + separate country check).
  *Status*: done.

- [x] **Finding 2** (medium) — the `## Plan of Work` / `## Context` / `## Validation and Acceptance` sections still describe disabled `<SelectItem>`s as hover tooltips (including an explicit `<Tooltip>` wrapping instruction), contradicting the Surprises & Discoveries entry that documents why the shipped implementation uses inline muted sub-text instead (Base UI sets `pointer-events:none` on disabled items).
  *Location*: `docs/PLAN.md` — `## Plan of Work` § 3, `## Validation and Acceptance` Scenarios A/B/D.
  *Fix*: rewrite those sections to describe the inline-subtext pattern as the shipped approach, with a note about why the tooltip approach was rejected.
  *Status*: done.

- [x] **Finding 3** (medium) — the `## Plan of Work` § 9 test-plan bullets still prescribe adding a new `CustomerChoiceEndToEndTest.php` and extra refund tests, and the `## Concrete Steps` target still reads "baseline 954 + ~12 tests / ~60 assertions". Reality: customer_choice coverage already existed in Session 2a's file (no new file), decision-#29 variants already existed in Session 3's file (no new tests), final loop landed at 961 / 4146 (+7 / +67). `## Progress` already records the actual outcomes.
  *Location*: `docs/PLAN.md` — `## Plan of Work` § 9 test-plan bullets + `## Concrete Steps` iteration-loop target.
  *Fix*: rewrite to reflect the reused existing tests and the actual delta; keep the one new file callout (`OnlinePaymentCheckoutTest` extension) and the two eligibility-prop Inertia assertions on the Settings test file.
  *Status*: done.

- [x] **Finding 4** (low) — the banner-wrapper decision names `resources/js/components/layout/` as the intended location, but the shipped file lives at `resources/js/components/dashboard/dashboard-banner.tsx` (chosen for consistency with the existing project structure — there is no `components/layout/` dir).
  *Location*: `docs/PLAN.md` — `## Decision Log` banner consolidation entry + `## Plan of Work` § 6 + `## Interfaces and Dependencies`.
  *Fix*: align the three references to the actually-used path.
  *Status*: done.

### Round 2 (codex adversarial review)

**Codex verdict**: `needs-attention` — no-ship. Two findings flagged the D-176 race-banner implementation as brittle on the customer_choice edge and noted that the dashboard mismatch banner now misdescribes runtime behaviour.

- [x] **Finding 1** (high) — `store()` treats an omitted `payment_choice` as `'online'` via `($paymentChoice ?? 'online') === 'online'`. For a `customer_choice` Business whose connected account is already ineligible at page load, `booking-summary.tsx` never renders the pay-now / pay-on-site picker (the `isCustomerChoiceMode` gate requires `onlinePaymentAvailable === true` at load time), and the `useHttp` payload sends `payment_choice: null`. The null-default escalated that degraded Stripe state into a hard 422 for customers whose offline-only UI had already shown them the "Confirm booking" (offline) CTA — a Stripe/KYC outage becomes a full booking outage.
  *Location*: `app/Http/Controllers/Booking/PublicBookingController.php:296-315`.
  *Fix*: the `customer_choice` arm of `$customerIntendedOnline` now requires an **explicit** `$paymentChoice === 'online'`. Null / absent → fall to offline path. Updated D-176 to document the tightened contract. Added a regression test (`customer_choice + degraded account + omitted payment_choice lands offline without 422`).
  *Status*: done.

- [x] **Finding 2** (medium) — the `paymentModeMismatch` banner still read "New bookings will fall back to offline until this is resolved", but D-176 now 422s online-intended bookings instead of falling back. The banner falsely reassured operators during a Stripe/KYC outage that customers could still book.
  *Location*: `resources/js/layouts/authenticated-layout.tsx:233-243`.
  *Fix*: copy rewritten to "Customers trying to pay online will be refused at checkout until this is resolved." Accurate for both `payment_mode = online` (all customers blocked) and `payment_mode = customer_choice` (online customers blocked, pay-on-site customers still work — the banner doesn't claim the inverse).
  *Status*: done.

### Round 3 (codex review)

**Codex verdict**: P1 findings on the Round-2 race banner contract — the server returned a hand-rolled `{reason, message}` JSON body that Inertia v3's `useHttp` does not consume into `http.errors`. The race banner was dead in the browser (HTTP-only tests didn't catch it because they asserted on the raw response). Plus a P3 on a misleading validator message for verified-but-non-CH accounts.

- [x] **Finding 1** (P1) — `useHttp` only populates `http.errors` / `http.hasErrors` from Laravel's standard 422 validation envelope (`{errors: {field: [messages]}}`). The Round-2 `response()->json(['reason' => 'online_payments_unavailable', 'message' => ...], 422)` shape is ignored by the hook. In the browser, the banner never rendered — `{http.hasErrors && ...}` stayed false and the switch on `http.errors.reason` never saw a value. The entire Session 5 race banner UX was silently broken.
  *Location*: every 4xx branch in `PublicBookingController::store` + `mintCheckoutOrRollback`, and the error handler in `resources/js/components/booking/booking-summary.tsx:222-229`.
  *Fix*: every error branch rewritten to `throw ValidationException::withMessages([$discriminator => __($message)])`. The discriminator vocabulary (`slot_taken | no_provider | online_payments_unavailable | checkout_failed | provider_id | booking`) is carried as the error KEY, not a separate `reason` field. `booking-summary.tsx` now renders `Object.values(http.errors)[0]` — the server-localized message is self-explanatory. `errorCopyForReason` helper deleted. Tests rewritten from `assertJsonFragment(['reason' => ...])` to `assertJsonValidationErrors([...])`. Three unrelated pre-existing 409 assertions in `BookingCreationTest`, `BookingRaceSimulationTest`, `BookingBufferGuardTest` updated to the new 422 shape (the slot-gone path is the same `SlotNoLongerAvailableException` catch block).
  *Status*: done.

- [x] **Finding 2** (P3) — `UpdateBookingSettingsRequest::paymentModeRolloutRule()` emitted "Connect Stripe and complete verification before enabling online payments." for EVERY non-offline rejection. For a verified-Stripe business whose account country is outside `supported_countries`, this is misleading — the account IS connected and verified; the blocker is the country.
  *Location*: `app/Http/Requests/Dashboard/Settings/UpdateBookingSettingsRequest.php:83-87`.
  *Fix*: the validator now branches on the actual blocker. Verified-Stripe + non-CH → "Online payments in MVP support CH-located businesses only." Everything else → the original "Connect Stripe..." message. Priority order mirrors the React Settings → Booking tooltip (not connected → non-CH → other). Added an assertion on the rendered error message in the existing non-CH test.
  *Status*: done.

### Round 4 (codex review)

**Codex verdict**: two findings post-Round-3. P1 on a real client/server mismatch for online-mode Businesses with a degraded connected account; P2 on CH-hardcoded copy in the Round-3 validator-message fix.

- [x] **Finding 1** (P1) — `booking-summary.tsx` gated `isOnlineMode` on `onlinePaymentAvailable` (= `business.can_accept_online_payments && servicePriceEligible`), which flipped the summary into the offline CTA (`Confirm booking`) + email-confirmation caption when a `payment_mode = 'online'` Business's account was degraded. The server-side D-176 refusal then returned 422 on submit, leaving the customer with an offline-promising UI that hard-failed. Misleading UX on the exact race Session 5's race banner was supposed to cover.
  *Location*: `resources/js/components/booking/booking-summary.tsx:47-62` and the render block around `{http.hasErrors && ...}` / the caption.
  *Fix*: `isOnlineMode` now reflects policy only (`business.payment_mode === 'online' && servicePriceEligible`). Added `onlineModeUnavailable = isOnlineMode && !business.can_accept_online_payments`. When true, the UI renders a pre-submit "unavailable" banner (same copy as the server's 422 message), disables the CTA (`disabled` prop on `<Button>`), and suppresses the email-confirmation caption. `isCustomerChoiceMode` stays gated on `onlinePaymentAvailable` (the soft-fallback to pay-on-site is still legitimate for customer_choice per D-176). `isOffline` unchanged. Feature test added in `tests/Feature/Booking/PublicBookingPageTest.php` proving the Inertia props expose both `business.payment_mode === 'online'` AND `business.can_accept_online_payments === false` for a business with a disabled connected account.
  *Status*: done.

- [x] **Finding 2** (P2) — the Round-3 fix wrote `"Online payments in MVP support CH-located businesses only."` as a hardcoded validator message, and the React tooltip mirrors the same string. Codex flagged this as stale against the config-driven country gate.
  *Location*: `app/Http/Requests/Dashboard/Settings/UpdateBookingSettingsRequest.php:100-101` and `resources/js/pages/dashboard/settings/booking.tsx` (the `disabledReason()` helper).
  *Rationale for REJECTION (no code change)*: locked roadmap decision #43 explicitly distinguishes STATE from COPY — the `supported_countries` config gate stays the single reader for the state (tests prove the seam opens via `config(['payments.supported_countries' => ['CH', 'DE']])` mid-test), but *"this roadmap's copy, UX, and tax assumptions are all CH-centric"*. The fast-follow roadmap extending to IT / DE / FR / AT / LI is defined as *"config change + TWINT fallback verification + locale-list audit"* — the copy is updated WHEN the config is extended, not before. Rendering the supported list dynamically here is YAGNI for MVP (config is `['CH']`) and contradicts D-43's locale-audit contract. Added inline rationale comments at both call sites pointing at D-43 so future review rounds don't refile.
  *Status*: rejected with rationale documented in-code.


## Outcomes & Retrospective

Populated at session close.


## Context and Orientation

This section is written for a fresh agent handed only `SPEC.md + HANDOFF.md + ROADMAP.md + PLAN.md`. It should be enough to implement Session 5 end-to-end without additional spelunking.

### What exists before Session 5 starts

- **`app/Models/Business.php`**. Carries the `payment_mode` enum column (`offline` | `online` | `customer_choice`, cast to `App\Enums\PaymentMode`), the `stripeConnectedAccount` HasOne relation, and the `canAcceptOnlinePayments(): bool` aggregate helper. The helper returns `true` iff (a) a `stripe_connected_accounts` row exists, (b) its `requirements_disabled_reason IS NULL`, (c) all three of `charges_enabled` / `payouts_enabled` / `details_submitted` are true, and (d) the row's `country` is in `config('payments.supported_countries')` (`['CH']` today). This is the single gate every Session-5 caller uses.

- **`app/Models/StripeConnectedAccount.php`**. Carries `country`, capability booleans, `requirements_disabled_reason`, and `verificationStatus(): string` which buckets the state as `disabled | unsupported_market | active | incomplete | pending`. Session 5 does not need to branch on this directly — the Inertia shared prop already surfaces what's needed for UI decisions.

- **`app/Http/Controllers/Dashboard/Settings/BookingSettingsController.php`**. Two-method controller (`edit` + `update`). `edit` renders `dashboard/settings/booking.tsx` with a `settings` prop block. `update` delegates to `UpdateBookingSettingsRequest` for validation and then `$business->update($validated)`. Session 5 extends `edit` to pass one additional prop block describing online-payment eligibility so the React page can disable options with the correct tooltip.

- **`app/Http/Requests/Dashboard/Settings/UpdateBookingSettingsRequest.php`**. The validator's `paymentModeRolloutRule()` closure currently implements D-132's hard block: any non-offline value is rejected, with one carve-out for idempotent passthrough (a PUT that re-sends the currently-persisted value succeeds, so the form's hidden-input round-trip doesn't break when the admin edits other fields and a DB-seeded non-offline value is already persisted). Session 5 replaces the hard block with a `canAcceptOnlinePayments()` check; the idempotent passthrough stays.

- **`resources/js/pages/dashboard/settings/booking.tsx`**. The Inertia form view. Currently `paymentModeItems` lists only `{ value: 'offline', label: t('Pay on-site') }`. Session 5 rebuilds this array to carry all three options with their new canonical labels and per-option `disabled` + `tooltip` state. The page already uses COSS UI's `<Select>` + `<SelectItem>` so disabling an item is a one-prop change on the item.

- **`resources/js/components/ui/tooltip.tsx`**. COSS UI wrapping Base UI's Tooltip. `<Tooltip>`, `<TooltipTrigger>`, `<TooltipContent>`. Session 5 wraps the disabled `<SelectItem>` contents in this for the priority-ordered hover copy.

- **`resources/js/components/booking/booking-summary.tsx`**. The final summary + payment step of the public booking flow. Renders the "Continue to payment" CTA for online bookings and the pay-now/pay-on-site pill for customer_choice. Already has `http.hasErrors` handling for slot-gone 409/422 responses but lumps every 422 into one generic "slot no longer available" copy — Session 5 splits the race case (connected account became disabled between load and submit) into its own inline banner.

- **`app/Http/Controllers/Booking/PublicBookingController.php`**. `store()` decides `$needsOnlinePayment` from `canAcceptOnlinePayments()` + price eligibility + `payment_mode`, creates the booking row with the locked-decision-#14 snapshot, then hands off to `mintCheckoutOrRollback()` if online is needed. `mintCheckoutOrRollback` catches two distinct 422 paths: `UnsupportedCountryForCheckout` (our own pre-flight assertion — country drifted out of supported set between page load and form submit) and `ApiErrorException` (Stripe-side failure). Session 5 extends the JSON response on both to include a stable `reason` string so the client can branch.

- **`resources/js/layouts/authenticated-layout.tsx`**. Already renders three banners: `subscriptionBanner` (MVPC-3), `paymentModeMismatch` (Session 1's dashboard-wide alert when `payment_mode` is non-offline but `canAcceptOnlinePayments()` is false), and `unbookableServices` (bookability). All three share an identical wrapper `<div className={fullBleed ? ... : 'mx-auto w-full max-w-6xl px-5 pt-5 sm:px-8 sm:pt-8'}>` with the same `<Alert variant="warning">` markup. Session 5 extracts the wrapper into one component.

- **`app/Http/Middleware/HandleInertiaRequests.php`**. `resolveConnectedAccountPayload` exposes `{ status, country, can_accept_online_payments, payment_mode_mismatch }` under `auth.business.connected_account`. Session 5's React payment_mode gate could in principle reuse this shared prop, but the Settings page already receives a dedicated `settings` block — adding one more prop block (`paymentEligibility`) on that page keeps Session 5's call sites isolated and easier to test in the Inertia assertion helpers.

- **`app/Services/Payments/RefundService.php`** and **`app/Http/Controllers/Dashboard/BookingController.php::updateStatus`**. Session 3 shipped the automatic `business-rejected-pending` refund dispatch for manual-confirmation rejections of paid pending bookings (locked decision #29). Session 5's integration tests verify this path survives the UI unhide; no code change needed in this area.

- **`config/payments.php`**. Exposes `supported_countries` (env `PAYMENTS_SUPPORTED_COUNTRIES`, default `['CH']`) and `twint_countries` (default `['CH']`) — D-112 / D-154 single-sources for country gating.

### Glossary

- **`payment_mode`**: the Business's policy bit. `offline` = customer always pays on site. `online` = customer always pays at booking via Stripe Checkout. `customer_choice` = customer picks at the checkout step.

- **`payment_mode_at_creation`**: the immutable snapshot on the booking row (locked decision #14). Mirrors `Business.payment_mode` at booking creation time (exception: `source=manual` / `source=google_calendar` always snapshot `'offline'`). Not touched by Session 5.

- **`canAcceptOnlinePayments()`**: the aggregate eligibility helper on `Business`. Returns `true` iff an active Stripe Connect connected account is attached AND the account's country is in `config('payments.supported_countries')` AND no `requirements_disabled_reason` is set.

- **`confirmation_mode`**: how bookings transition. `auto` = booking goes to `confirmed` on creation (or on Checkout success for online); `manual` = booking stays `pending` until admin approves or rejects. The `manual × online` combination is the one that surfaces locked-decision-#29's "admin rejection triggers automatic full refund" path.

- **Idempotent passthrough** (Settings): a PUT that re-sends the currently-persisted `payment_mode` value is accepted by the validator even if the normal gate would reject it. This keeps the form's hidden-input round-trip working when the admin edits other fields and the persisted `payment_mode` is a legacy non-offline value from earlier dogfooding.

- **Locked roadmap decision #27**: from Session 1 through Session 4 the `online` and `customer_choice` options are hidden from Settings → Booking. Session 5 lifts the ban.

- **Locked roadmap decision #29**: under `confirmation_mode = manual` × online payment, the `checkout.session.completed` webhook sets `payment_status = paid` but leaves `status = pending`. Admin "Confirm" promotes; admin "Reject" cancels AND dispatches an automatic full refund via `RefundService::refund($booking, null, 'business-rejected-pending')`. The customer email branches on `$refundIssued` (D-175).

- **Locked roadmap decision #43**: non-CH connected accounts may not enable `online` or `customer_choice`. Enforced via `config('payments.supported_countries')` — zero hardcoded `'CH'` literals in application code. The config flip seam is already coded open; Session 5 tests prove this still holds.


## Plan of Work

The sequence below mirrors `## Progress` and describes each edit concretely.

### 1. Lift the server-side gate

Edit `app/Http/Requests/Dashboard/Settings/UpdateBookingSettingsRequest.php::paymentModeRolloutRule()`. Today the closure hard-fails every non-offline value. Replace with:

```php
private function paymentModeRolloutRule(): Closure
{
    return function (string $attribute, mixed $value, Closure $fail): void {
        if ($value === PaymentMode::Offline->value) {
            return;
        }

        $business = tenant()->business();

        // Idempotent passthrough: a PUT that re-sends the
        // currently-persisted value is always accepted so the hidden-input
        // round-trip doesn't break when other fields are edited on a
        // Business that already carries a non-offline value.
        if ($business !== null && $business->payment_mode->value === $value) {
            return;
        }

        // Session 5 gate lift (locked roadmap decisions #27 / #43):
        // non-offline is accepted iff the business is genuinely eligible.
        // `canAcceptOnlinePayments()` folds in Stripe capability booleans
        // (D-127), `requirements_disabled_reason` (D-138), and the
        // supported-country gate (D-141 / D-150) via one reader.
        if ($business !== null && $business->canAcceptOnlinePayments()) {
            return;
        }

        $fail(__('Connect Stripe and complete verification before enabling online payments.'));
    };
}
```

Rewrite the docblock to describe the post-Session-5 state (D-132's transitional hard-block has been replaced by the `canAcceptOnlinePayments()` check; the idempotent passthrough carve-out is unchanged).

### 2. Surface eligibility to the Settings React page

Extend `BookingSettingsController::edit()`:

```php
public function edit(Request $request): Response
{
    $business = tenant()->business();
    $business->loadMissing('stripeConnectedAccount');
    $row = $business->stripeConnectedAccount;
    $supported = (array) config('payments.supported_countries');

    return Inertia::render('dashboard/settings/booking', [
        'settings' => [
            // ...unchanged keys...
            'payment_mode' => $business->payment_mode->value,
        ],
        // Priority-ordered tooltip requires TWO independent flags — see
        // the Surprises & Discoveries entry for why `has_verified_account`
        // reads raw capability booleans rather than
        // `verificationStatus() === 'active'` (a DE-active account
        // returns `unsupported_market` from verificationStatus, which
        // would flip the tooltip priority order).
        'paymentEligibility' => [
            'has_verified_account' => $row !== null
                && $row->charges_enabled
                && $row->payouts_enabled
                && $row->details_submitted
                && $row->requirements_disabled_reason === null,
            'country_supported' => $row !== null
                && in_array($row->country, $supported, true),
            'can_accept_online_payments' => $business->canAcceptOnlinePayments(),
            'connected_account_country' => $row?->country,
            'supported_countries' => $supported,
        ],
    ]);
}
```

### 3. Rebuild the Settings → Booking payment section

Edit `resources/js/pages/dashboard/settings/booking.tsx`:

```tsx
interface PaymentEligibility {
    has_verified_account: boolean;
    country_supported: boolean;
    can_accept_online_payments: boolean;
    connected_account_country: string | null;
    supported_countries: string[];
}

interface Props {
    settings: { /* as today */ };
    paymentEligibility: PaymentEligibility;
}

// inside component:
const disabledReason = (eligibility: PaymentEligibility): string | null => {
    if (!eligibility.has_verified_account) {
        return t('Connect Stripe and finish onboarding to enable online payments.');
    }
    if (!eligibility.country_supported) {
        return t('Online payments in MVP support CH-located businesses only.');
    }
    return null; // (reserved for future policy gates)
};

const paymentModeItems = [
    { value: 'offline', label: t('Customers pay on-site'), disabled: false, reason: null as string | null },
    {
        value: 'online',
        label: t('Customers pay when booking'),
        disabled: !paymentEligibility.can_accept_online_payments,
        reason: disabledReason(paymentEligibility),
    },
    {
        value: 'customer_choice',
        label: t('Customers choose at checkout'),
        disabled: !paymentEligibility.can_accept_online_payments,
        reason: disabledReason(paymentEligibility),
    },
];
```

Render each disabled `<SelectItem>` with the `disabled` prop and an inline muted sub-text under the label carrying the priority-ordered reason. The COSS UI `<SelectItem>` passes `...props` through to Base UI, which sets `pointer-events:none` on disabled items — so a hover tooltip would never fire and would be inaccessible on mobile/touch/keyboard. The inline sub-text is the primary signal; `title={reason ?? undefined}` on the item provides a pointer-device hover backup. See `## Surprises & Discoveries` for why the earlier tooltip-wrapper approach was rejected.

```tsx
<SelectItem
    key={item.value}
    value={item.value}
    disabled={item.disabled}
    title={item.reason ?? undefined}
>
    <span className="flex flex-col gap-0.5">
        <span>{item.label}</span>
        {item.disabled && item.reason && (
            <span className="text-xs text-muted-foreground">{item.reason}</span>
        )}
    </span>
</SelectItem>
```

For the idempotent-passthrough case (persisted non-offline value but currently ineligible): the Select's uncontrolled `defaultValue={settings.payment_mode}` still carries the persisted value on submit regardless of item disabled state. The disabled item with its inline reason tells the admin why they cannot switch back to it. The validator's idempotent-passthrough branch accepts the round-tripped value.

Also add the inline confirmation-mode hint below the select. Use `useState` bindings for both `confirmation_mode` and `payment_mode` so the hint re-renders as the admin toggles either:

```tsx
const [currentConfirmationMode, setCurrentConfirmationMode] = useState(settings.confirmation_mode);
const [currentPaymentMode, setCurrentPaymentMode] = useState(settings.payment_mode);

const showManualHint = currentConfirmationMode === 'manual'
    && (currentPaymentMode === 'online' || currentPaymentMode === 'customer_choice');

{showManualHint && (
    <FieldDescription>
        {t("Customers will be charged at booking; if you reject a booking, they'll receive an automatic full refund.")}
    </FieldDescription>
)}
```

Wire the Selects' `onValueChange` to the setters (keep `name=` + `defaultValue=` so native form submit still carries the value).

### 4. Public-booking UI copy + race banner

In `resources/js/components/booking/booking-summary.tsx`:

- Add "Secured by Stripe" microcopy under the CTA caption when `willRedirectToStripe`. Show the exact amount, e.g. "Your card will be charged CHF 65.00 on the next step." Use the existing `formatPrice` helper with the business's currency.
- TWINT badge: read a new `business.twint_available: boolean` from the Inertia prop and render a small styled pill when true and `business.payment_mode !== 'offline'`. Prefer a server-computed boolean over a client-side country check (zero hardcoded 'CH'; parallels Session 2a's D-154 `twint_countries` config read).
- Split the 422 error handling. Currently `http.hasErrors` renders a single copy. Replace with a switch on `http.errors.reason`:

```tsx
{http.hasErrors && (
    <div className="rounded-lg border border-primary bg-honey-soft px-4 py-3 text-sm text-primary-foreground">
        {(() => {
            switch ((http.errors as { reason?: string }).reason) {
                case 'online_payments_unavailable':
                    return t('This business is no longer accepting online payments right now — try again later or contact them directly.');
                case 'checkout_failed':
                    return t("Couldn't start payment. Please try again in a moment.");
                case 'slot_taken':
                default:
                    return t('This time slot is no longer available. Please select another time.');
            }
        })()}
    </div>
)}
```

### 5. PublicBookingController response contract extension

In `app/Http/Controllers/Booking/PublicBookingController.php`:

- `show()` prop: expand the `business` block with `'twint_available' => in_array($business->stripeConnectedAccount?->country, (array) config('payments.twint_countries'), true)`. Update the TS type `PublicBusiness` in `resources/js/types/index.d.ts`.
- `mintCheckoutOrRollback()`: extend both 422 branches to include a `reason` key:

```php
catch (UnsupportedCountryForCheckout $e) {
    // ...existing log...
    $this->releaseSlotFor($booking);
    return response()->json([
        'reason' => 'online_payments_unavailable',
        'message' => __("This business is no longer accepting online payments right now — try again later or contact them directly."),
    ], 422);
} catch (ApiErrorException $e) {
    report($e);
    $this->releaseSlotFor($booking);
    return response()->json([
        'reason' => 'checkout_failed',
        'message' => __("Couldn't start payment. Please try again in a moment."),
    ], 422);
}
```

- `store()` slot-gone 409 branches: add `'reason' => 'slot_taken'` alongside the existing `message`.

### 6. Banner wrapper consolidation

Create `resources/js/components/dashboard/dashboard-banner.tsx` (kept next to the existing `dashboard/` components; project has no `components/layout/` directory):

```tsx
import type { ReactNode } from 'react';
import { Alert, AlertTitle, AlertDescription, AlertAction } from '@/components/ui/alert';
import { AlertTriangleIcon } from 'lucide-react';

interface Props {
    variant: 'error' | 'warning' | 'info';
    title: string;
    description: ReactNode;
    action?: ReactNode;
    testId: string;
    fullBleed: boolean;
}

export function DashboardBanner({
    variant, title, description, action, testId, fullBleed,
}: Props) {
    return (
        <div
            className={fullBleed
                ? 'border-b border-border/60 px-5 pb-3 pt-3 sm:px-8'
                : 'mx-auto w-full max-w-6xl px-5 pt-5 sm:px-8 sm:pt-8'}
            data-testid={testId}
        >
            <Alert variant={variant} role="alert">
                <AlertTriangleIcon aria-hidden="true" />
                <AlertTitle>{title}</AlertTitle>
                <AlertDescription>{description}</AlertDescription>
                {action && <AlertAction>{action}</AlertAction>}
            </Alert>
        </div>
    );
}
```

Rewrite the three banner call sites in `authenticated-layout.tsx` to use `<DashboardBanner>` with their existing copy + variant + testId. Keep the order: subscription → payment-mode-mismatch → unbookable-services (unchanged). Pure refactor — no visual diff, no test changes beyond the existing data-testid-based assertions remaining green.

### 7. i18n audit

From session root: `git log --oneline main..HEAD` (should be empty — we're on `main`) and `git log --oneline --since="2026-04-22" -- app/ resources/` to enumerate Session 1–4 commits. For each, `git show --stat` and eyeball the new strings. Any bare PHP string that is user-facing should be wrapped in `__()`; any bare TSX string likewise in `t()`. If `__()` is newly added to PHP, re-add the key to `lang/en/*.json` via whichever translation key scanner the project uses (or manually — the file is a flat JSON map).

### 8. payment_mode branching audit

One required code change:

- `app/Http/Controllers/Booking/PublicBookingController.php:383`:

```php
'expires_at' => $business->payment_mode === PaymentMode::Online
    ? now()->addMinutes(90)
    : null,
```

Rewrite as `match`:

```php
'expires_at' => match ($business->payment_mode) {
    PaymentMode::Online => now()->addMinutes(90),
    PaymentMode::CustomerChoice, PaymentMode::Offline => null,
},
```

The `Offline` arm is defensively unreachable inside the `$needsOnlinePayment` branch (that branch requires non-offline), but `match` forces exhaustiveness and makes the "CustomerChoice gets null on purpose" intent explicit (locked decision #13 — only `online` mode's booking rows get an `expires_at`; customer_choice relies on Checkout-session expiry + failure branching).

No other branching fixes needed — the rest of the `payment_mode` dereferences in `app/` already use enum equality correctly (audited inline in Milestone 5).

### 9. Tests (final tally — see `## Progress` for per-milestone timeline)

Final delta: **+7 tests / +67 assertions** (baseline 954 / 4079 → 961 / 4146). Three files touched; no new files created. The pre-plan estimate of "+~12 tests" over-anticipated new coverage — Sessions 2a / 3 already carried the `customer_choice` end-to-end + locked-decision-#29 variants, so Session 5 only had to add the net-new paths.

- `tests/Feature/Settings/BookingSettingsTest.php` (+5 tests, plus 2 eligibility-prop Inertia assertions tacked onto existing tests):
    - REPLACED the D-132 rejection test with its positive shape: `admin can PUT payment_mode=online when the business has a verified CH connected account (Session 5 gate-lift)`. Seed `StripeConnectedAccount::factory()->active()->for($business)`; assert `assertSessionDoesntHaveErrors()` and the DB state flipped to Online.
    - KEPT the "no connected account → 422" test (existing, still valid under new rule; renamed for clarity).
    - ADDED `admin can PUT payment_mode=customer_choice when the business has a verified CH connected account`.
    - ADDED `non-CH active account is rejected server-side (Session 5, locked decision #43)`: seed an active DE connected account, assert 422.
    - ADDED `config flip opens the seam for non-CH accounts (Session 5 proves the gate is config-driven, no hardcoded CH)`: `config(['payments.supported_countries' => ['CH', 'DE']])` mid-test, seed active DE account, assert PUT succeeds.
    - ADDED `Settings → Booking eligibility prop reports an active CH account as eligible (Session 5)` and `… reports a DE active account as ineligible (Session 5, locked decision #43)` Inertia prop assertions.
    - KEPT the idempotent-passthrough test as-is.

- `tests/Feature/Booking/OnlinePaymentCheckoutTest.php` (+2 tests, +1 rewrite):
    - ADDED `race: connected account loses capabilities between load and submit → 422 with reason=online_payments_unavailable (Session 5)` — `requirements_disabled_reason` flipped mid-test; asserts `assertJsonFragment(['reason' => 'online_payments_unavailable'])` and `Booking::count() === 0` (the 422 fires before the transaction).
    - ADDED `customer_choice + pay-on-site still lands offline when connected account is ineligible (Session 5 — explicit offline choice, no race banner)` — proves the D-176 soft path.
    - REWROTE the pre-Session-5 test `can_accept_online_payments false drops the online branch even when payment_mode = online` (previously asserted `assertCreated()` + offline-path booking) into `payment_mode=online + can_accept_online_payments=false surfaces the race banner instead of silent offline downgrade (Session 5)` — now asserts 422 + zero booking rows per D-176.
    - ADDED a `reason=checkout_failed` assertion to the existing `Stripe API failure on Checkout create cancels the booking and returns 422` test.

- Locked-decision-#29 variants: **NO new tests needed** — Session 3's `tests/Feature/Booking/PaidCancellationRefundTest.php:190` (admin reject of Pending+Paid dispatches business-rejected-pending refund) and `:218` (admin reject of Pending+Unpaid cancels without refund) already cover both branches with `$refundIssued` assertions (D-175). Confirmed green under Session 5's changes.

- `customer_choice` end-to-end variants: **NO new file needed** — Session 2a's `tests/Feature/Booking/OnlinePaymentCheckoutTest.php:144,159` already cover `customer_choice + pay-now` and `customer_choice + pay-on-site`. Confirmed green.

- `tests/Browser/Settings/BookingSettingsTest.php` (+1 test edit):
    - UPDATED `it updates payment_mode to online` to seed an active connected account before the HTTP PUT, so the new validator accepts the change. (Browser-suite test; HTTP-only, no `visit()` call.)


## Concrete Steps

Run from the repo root. Each command has an expected outcome noted below it.

```bash
# Milestone 1 — server-side gate lift
# After editing UpdateBookingSettingsRequest.php + BookingSettingsController.php:
php artisan test tests/Feature/Settings/BookingSettingsTest.php --compact
# Expect: all passing. New Session 5 tests added in Milestone 6 will start
# passing after they're written and the validator is lifted.

# Milestone 2/3 — UI edits in booking.tsx and booking-summary.tsx:
npm run build
# Expect: Vite build succeeds; the bundle grows by ~2 kB (new eligibility
# flags + three Select options + inline reason sub-text).

# Milestone 5 — payment_mode branching audit after the match() rewrite:
./vendor/bin/phpstan
# Expect: level 5, app/ clean.

# Milestone 6 — full iteration loop at end:
php artisan test tests/Feature tests/Unit --compact
# Expect: baseline 954 → 961 (+7 tests / +67 assertions). Final ran green
# at session close.

vendor/bin/pint --dirty --format agent
# Expect: any auto-formatted files printed; no substantive changes.

php artisan wayfinder:generate
# Expect: idempotent — no TS file changes unless a new route was added
# (Session 5 does not add routes).

npm run build
# Expect: green, no new warnings beyond the pre-existing >500 kB chunk notice.
```


## Validation and Acceptance

Acceptance is behavior a human can verify. Walk the six scenarios below in the dev app to confirm the feature works end-to-end.

### Scenario A — No connected account

1. Seed a Business with no `stripe_connected_accounts` row.
2. Log in as admin. Navigate to Settings → Booking.
3. Observe: `payment_mode` select shows all three options. `offline` is selectable; `online` and `customer_choice` are rendered disabled with inline muted sub-text "Connect Stripe and finish onboarding to enable online payments." visible directly under each label.
4. Open dev tools and submit a direct PUT with `payment_mode=online`. Observe: 422 with `errors.payment_mode` pointing back to the same eligibility gate.

### Scenario B — Pending connected account

1. Seed a Business with `StripeConnectedAccount::factory()->pending()->for($business)`.
2. Navigate to Settings → Booking. Observe: same as Scenario A — connected-account row exists but not verified, so `canAcceptOnlinePayments()` returns false; inline reason under each disabled option reads "Connect Stripe and finish onboarding".

### Scenario C — Active CH connected account

1. Seed a Business with `StripeConnectedAccount::factory()->active()->for($business)` (country = CH, all caps on, no disabled_reason).
2. Navigate to Settings → Booking. Observe: all three options enabled. Select "Customers pay when booking" and submit. Observe: redirects back with a success flash.
3. Toggle confirmation mode to "Manual confirmation". Observe: the inline hint "Customers will be charged at booking; if you reject a booking, they'll receive an automatic full refund." appears below the payment-mode select.
4. Navigate to `/{slug}` as an anonymous customer. Walk the booking flow. Observe: the summary step shows "Continue to payment →", a "Secured by Stripe" line with the exact price (e.g. "Your card will be charged CHF X.XX on the next step."), and a TWINT badge. Complete → redirects to Stripe Checkout.

### Scenario D — Active DE connected account + config flip

1. Seed a Business with an active connected account whose country is `DE`.
2. Navigate to Settings → Booking. Observe: online / customer_choice options rendered disabled with inline muted sub-text "Online payments in MVP support CH-located businesses only." under each label.
3. Set `PAYMENTS_SUPPORTED_COUNTRIES=CH,DE` in `.env` (or via tinker) and `php artisan config:clear`.
4. Reload. Observe: online / customer_choice now enabled. Submitting online succeeds.

### Scenario E — Race: connected account disabled (two sub-scenarios after Round 4)

**E.1 — already disabled at page-load** (most common vector):

1. Seed a Business with `payment_mode = online` + a connected account carrying `requirements_disabled_reason = 'rejected.fraud'` (or any degraded state that makes `canAcceptOnlinePayments()` return false).
2. Load `/{slug}` in the browser as a customer.
3. Observe: walk through service → date → customer info → summary. At the summary step, the inline banner already renders ("This business is no longer accepting online payments right now — try again later or contact them directly.") and the primary CTA is disabled. The email-confirmation caption is suppressed. No submit is possible. Introduced in Round 4 Finding 1 to avoid the pre-Round-4 behaviour where the UI masked as an offline flow ("Confirm booking" + "You will receive a confirmation by email") and then 422'd on click.

**E.2 — disabled mid-session between page-load and submit** (race):

1. Seed an active CH connected account on a Business with `payment_mode = online`.
2. Load `/{slug}` in the browser; advance to the summary step but do not submit yet. At this moment the CTA still reads "Continue to payment" because the snapshot prop still says `can_accept_online_payments: true`.
3. Flip the connected account row to `requirements_disabled_reason = 'rejected.fraud'` in the DB.
4. Submit the booking form. Observe: server returns 422 via `ValidationException::withMessages(['online_payments_unavailable' => ...])`; the inline banner renders the same copy as E.1. No booking row is persisted (the 422 fires before the transaction).

Both variants produce the same user-visible outcome; E.1 avoids the "promise then fail" UX hazard by anticipating the state at page-load.

### Scenario F — Manual confirmation × online rejection refund

1. Admin seeds `confirmation_mode=manual`, `payment_mode=online`, active CH account.
2. Customer completes a booking → pays via Stripe → booking lands at `pending + paid` (locked decision #29).
3. Admin opens booking detail, clicks Reject.
4. Observe: booking transitions to `cancelled`; a `booking_refunds` row lands with status `succeeded`, reason `business-rejected-pending`; the customer receives `BookingCancelledNotification` with the refund clause rendered.

### Test acceptance

Run `php artisan test tests/Feature tests/Unit --compact` and expect the baseline (954) to grow by roughly +12 tests with no regressions. PHPStan remains clean. Pint remains clean. `npm run build` remains green.


## Idempotence and Recovery

Every step in this plan is re-runnable:

- The validator rewrite is a pure code change; no migrations.
- The React prop addition is additive (extra prop block in `Inertia::render`). Re-running the iteration loop after a partial edit is safe.
- The `match()` rewrite on `PublicBookingController.php:383` is behaviour-preserving (both branches yield the same runtime value). If the match fails type-checking, revert to the ternary and open an issue.
- Test additions are isolated to their own files or clearly-scoped additions. Failures surface locally before session close.

No destructive operations, no migrations, no data backfills. The only potentially visible change for a pre-existing non-offline business is that they can now legitimately keep their setting — the idempotent-passthrough test proves this stays stable.


## Artifacts and Notes

Expected `tests/Feature/Settings/BookingSettingsTest.php` excerpt after Milestone 6:

```php
test('admin can PUT payment_mode=online when the business has a verified CH connected account (Session 5)', function () {
    StripeConnectedAccount::factory()->active()->for($this->business)->create();

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'auto',
            'allow_provider_choice' => true,
            'cancellation_window_hours' => 24,
            'payment_mode' => 'online',
            'assignment_strategy' => 'first_available',
            'reminder_hours' => [],
        ])
        ->assertSessionDoesntHaveErrors();

    expect($this->business->fresh()->payment_mode)->toBe(PaymentMode::Online);
});

test('non-CH active account is rejected server-side (Session 5, locked decision #43)', function () {
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['country' => 'DE']);

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/booking', [
            /* ...minimum fields... */
            'payment_mode' => 'online',
        ])
        ->assertSessionHasErrors('payment_mode');
});

test('config flip opens the seam for non-CH accounts (Session 5)', function () {
    config(['payments.supported_countries' => ['CH', 'DE']]);

    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['country' => 'DE']);

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/booking', [
            /* ...minimum fields... */
            'payment_mode' => 'online',
        ])
        ->assertSessionDoesntHaveErrors();

    expect($this->business->fresh()->payment_mode)->toBe(PaymentMode::Online);
});
```

Expected `resources/js/components/booking/booking-summary.tsx` race-banner excerpt (simplified):

```tsx
{http.hasErrors && (
    <div className="rounded-lg border border-primary bg-honey-soft px-4 py-3 text-sm text-primary-foreground">
        {errorCopyForReason((http.errors as { reason?: string }).reason, t)}
    </div>
)}
```

Where `errorCopyForReason` is a small top-of-file helper matching the switch above.


## Interfaces and Dependencies

### PHP

- `App\Http\Requests\Dashboard\Settings\UpdateBookingSettingsRequest::paymentModeRolloutRule(): Closure` — new shape documented above. Signature unchanged.
- `App\Http\Controllers\Dashboard\Settings\BookingSettingsController::edit(Request): Response` — signature unchanged; adds a second Inertia prop block `paymentEligibility`.
- `App\Http\Controllers\Booking\PublicBookingController::show(...)` — the `business` prop gains `twint_available: bool`.
- `App\Http\Controllers\Booking\PublicBookingController::store(...)`, `mintCheckoutOrRollback(...)` — signatures unchanged; JSON response schema extended to include `reason: 'online_payments_unavailable' | 'checkout_failed' | 'slot_taken'`.

### React / Inertia

- `resources/js/pages/dashboard/settings/booking.tsx` — accepts a new `paymentEligibility` prop.
- `resources/js/components/booking/booking-summary.tsx` — reads the new `reason` field off `http.errors`; reads `business.twint_available`.
- `resources/js/types/index.d.ts` — `PublicBusiness` gains `twint_available: boolean`.
- `resources/js/components/dashboard/dashboard-banner.tsx` — new component; three callers in `authenticated-layout.tsx`.

### Config + docs

- `config/payments.php` — no changes.
- `lang/en/*.json` — may grow by ~8 entries for Session 5's new strings.
- `docs/decisions/DECISIONS-PAYMENTS.md` — receives D-176 IFF the race-banner reason field is promoted as a decision. Decided during implementation.
- `docs/HANDOFF.md` — rewritten at session close.


## Open Questions

None blocking. Two minor judgment calls captured in the Decision Log:

1. Whether the race-banner `reason` field merits a `D-NNN` promotion or stays an in-file comment. Default: promote as D-176 if it survives codex review; else keep as inline documentation.
2. Whether the TWINT badge should be a reusable `<TwintBadge>` component or inline in `booking-summary.tsx`. Default: inline for Session 5 (one usage site); extract if a second consumer appears later.


## Risks & Notes

- **Risk**: lifting the validator accepts non-offline values from admins who have dogfood-seeded a non-CH connected account. Mitigation: `canAcceptOnlinePayments()` already blocks this — non-CH accounts return false via D-150's `unsupported_market` check and the country-in-supported-set clause. Test coverage proves this.

- **Risk**: the race-banner `reason` field is a response-shape change. Existing consumers of `PublicBookingController::store` read `token` / `redirect_url` / `external_redirect` / `status`; `reason` is only present on 422. Mitigation: 422 already renders generic error copy; adding a nested discriminator key is additive and backward-compatible.

- **Risk**: the Settings page idempotent-passthrough behaviour under the new gate. An admin who has `payment_mode = online` persisted from dogfooding but whose connected account is now disabled (KYC failure mid-cycle) must still be able to edit other fields without getting a 422 on the round-tripped hidden input. Mitigation: the idempotent-passthrough branch is preserved verbatim. Test coverage exists.

- **Risk**: banner extraction breaks the `data-testid` assertions used by existing browser tests. Mitigation: `<DashboardBanner>` forwards `testId` to the wrapper div's `data-testid`, so every existing selector survives the refactor.

- **Note**: Session 4's Payouts page is explicitly NOT touched by this session beyond the i18n audit (HANDOFF: "Session 5 may polish copy but should not change the controller, the cache layer, or the Stripe call shape"). If the i18n audit surfaces a Payouts-page string that should wrap `t()` and doesn't, that's the only acceptable Payouts edit.

- **Note**: `tests/Browser/Settings/BookingSettingsTest.php` exists (Pest 4 browser APIs). Session 5 should verify it still passes under the unhidden UI and update its assertions to match (the test likely asserts the old single-option Select). Keep the browser edits minimal — Feature tests carry the integration weight.
