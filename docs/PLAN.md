# PAYMENTS Session 4 — Payout Surface + Connected Account Health

This plan lives at `docs/PLAN.md` and follows `.claude/references/PLAN.md`. It is a living document. The sections `Progress`, `Surprises & Discoveries`, `Decision Log`, `Review`, and `Outcomes & Retrospective` are kept up to date as work proceeds.


## Purpose / Big Picture

After this session, an admin of a Business that has a Stripe Connect Express account can open a new dashboard page — `Payouts` — and see, at a glance, **where the money sits**:

- The available balance on their connected account (what Stripe will pay out next).
- The pending balance (what is still in Stripe's reserve / clearing window).
- The payout schedule the connected account is on (daily by default; the admin can change it in Stripe).
- The last 10 payouts (date, amount, currency, status, expected arrival date).
- A connected-account health strip showing whether charges + payouts are enabled and whether Stripe is asking for more verification details.
- Two banners the admin needs to see:
  1. "Stripe Tax not configured" — when the connected account's `tax_settings.status !== 'active'` (locked roadmap decision #11).
  2. "Online payments in MVP support CH-located businesses only" — when the account's `country` is not in `config('payments.supported_countries')` (locked roadmap decision #43; today the supported set is `['CH']` so EU accounts see the banner).
- A "Manage payouts in Stripe" button that **mints a fresh Stripe Express dashboard login link on click** and opens it in a new tab.

This is the only riservo-side action: the page **surfaces payout status, never manages it**. No payout initiation, no schedule change, no pause, no per-payout retry — those are out of scope per locked decision #24.

Demonstrating it from a fresh dev environment will look like this:

```bash
php artisan migrate:fresh --seed
php artisan tinker --execute 'use App\Models\Business; use App\Models\StripeConnectedAccount; $b = Business::first(); StripeConnectedAccount::factory()->for($b)->active()->create();'
composer run dev
```

Then sign in as that business's admin, click **Payouts** in the sidebar, and the page renders. (In dev the page will obviously show fake balances because the dev env points at Stripe test mode — the FakeStripeClient pattern is for tests, not for dev rendering, but the page degrades gracefully when Stripe is unreachable.)


## Progress

Granular checklist; every stopping point splits into "done: X / remaining: Y" if needed. UTC timestamps so the developer can gauge pace.

- [x] (2026-04-24 17:25Z) Read `SPEC.md`, `HANDOFF.md`, the Session 4 brief in `ROADMAP.md`, `.claude/references/PLAN.md`, and decisions D-109..D-150 + D-119 + D-127 + D-138 + D-141..D-150 in `DECISIONS-PAYMENTS.md`.
- [x] (2026-04-24 17:30Z) Survey the FakeStripeClient + ConnectedAccountController + Business model + HandleInertiaRequests + sidebar + routes + Stripe SDK service signatures (BalanceService, PayoutService, AccountService, Tax\SettingsService).
- [x] (2026-04-24 17:35Z) Confirm baseline iteration-loop tests: `938 passed (3918 assertions)` in 46.6s.
- [x] (2026-04-24 17:55Z) Draft this plan and stop for developer approval (gate one).

**M1 — FakeStripeClient extensions** ✅
- [x] (2026-04-24 18:10Z) Extended `tests/Support/Billing/FakeStripeClient.php` with `mockBalanceRetrieve`, `mockPayoutsList`, `mockTaxSettingsRetrieve` (connected-account-level — header-asserted PRESENT) and `mockLoginLinkCreate` (platform-level — header-asserted ABSENT via the existing `assertPlatformLevel` helper).
- [x] (2026-04-24 18:10Z) `mockLoginLinkCreate` hangs off the `accounts` service (Stripe SDK exposes `createLoginLink` as a method on `AccountService`, not a separate sub-service). `mockTaxSettingsRetrieve` mocks the nested `tax->settings->retrieve` chain via a two-level Mockery mock.
- [x] Coverage comes from the PayoutsController feature tests in M7 — the assertion contract on each helper fails loudly via Mockery "method not expected" if the header bucket is wrong.

**M2 — `PayoutsController` happy path + Inertia page skeleton** ✅
- [x] (2026-04-24 18:15Z) Created `app/Http/Controllers/Dashboard/PayoutsController.php` with `index(Request): Response` and `loginLink(Request): JsonResponse`.
- [x] (2026-04-24 18:15Z) Registered `GET /dashboard/payouts` + `POST /dashboard/payouts/login-link` inside an inner `role:admin` group nested under the existing `billing.writable` group.
- [x] (2026-04-24 18:16Z) Ran `php artisan wayfinder:generate`; typed actions exposed at `@/actions/App/Http/Controllers/Dashboard/PayoutsController`.
- [x] (2026-04-24 18:22Z) Created `resources/js/pages/dashboard/payouts.tsx` (single file, ~500 lines) with five top-level branches (not-connected / resume-onboarding / disabled / verified-active / verified-unsupported-market).

**M3 — Caching + graceful degradation** ✅
- [x] (2026-04-24 18:40Z) **Refactored mid-exec** from the originally-planned `Cache::remember(60)` to a two-layer cache pattern: 60s freshness window (checked via `fetched_at` inside the payload) + 24h cache TTL (long-lived fallback). The original `Cache::remember` shape was wrong — it would short-circuit on still-fresh cache *before* entering the try block, so Stripe failure could never mark `stale: true` when a prior-cached value existed. See `## Surprises & Discoveries`.
- [x] (2026-04-24 18:42Z) Stripe failure with prior cache → return cached payload + `stale: true`. Empty-cache Stripe failure → return empty payload + `error: 'unreachable'`.
- [x] (2026-04-24 18:25Z) `pending` / `incomplete` / `disabled` branches skip Stripe entirely; React shows onboarding CTA / disabled panel.

**M4 — Health strip + banners** ✅
- [x] (2026-04-24 18:22Z) Three chips (charges-enabled, payouts-enabled, requirements-due) with lucide icons + text. Colour-blind friendly: icon + text carry the same signal as the colour.
- [x] (2026-04-24 18:22Z) Stripe-Tax-not-configured banner fires when `payouts.tax_status !== 'active'`.
- [x] (2026-04-24 18:22Z) Non-CH banner fires when `account.status === 'unsupported_market'` (derived server-side by `StripeConnectedAccount::verificationStatus()` per D-150). React reads the supported set from the `supportedCountries` Inertia prop — no hardcoded `'CH'`.
- [x] (2026-04-24 18:22Z) Disabled-by-Stripe panel surfaces the verbatim `requirements_disabled_reason` + mailto support CTA.
- [x] (2026-04-24 18:22Z) "Couldn't refresh" banner (stale) and "Couldn't load payout state" banner (unreachable) cover the two failure modes.

**M5 — Stripe Express login link mint endpoint** ✅
- [x] (2026-04-24 18:17Z) `POST /dashboard/payouts/login-link` calls `stripe.accounts.createLoginLink($acct)` (platform-level; the FakeStripeClient mock asserts the `stripe_account` header is ABSENT). Returns JSON `{url: '...'}` on success, `{error: '...'}` with 502 on Stripe failure.
- [x] (2026-04-24 18:22Z) React button uses `useHttp` + `window.open(response.url, '_blank', 'noopener')`. Loading state, inline error message, `aria-label` describing the new-tab behaviour.
- [x] 404 when no connected-account row exists; 422 when the account is disabled (tests cover both).

**M6 — Navigation entry** ✅
- [x] (2026-04-24 18:45Z) Added "Payouts" nav item to `authenticated-layout.tsx` between Customers and Settings, admin-only, visible only when `connectedAccount !== null` (the existing `auth.business.connected_account` shared prop; returns null when no row exists).

**M7 — Feature tests** ✅
- [x] (2026-04-24 18:58Z) `tests/Feature/Dashboard/PayoutsControllerTest.php` with **15 cases**: happy path; staff-403; cross-tenant isolation; Stripe failure + stale cache; Stripe failure + empty cache; pending-state CTA with zero Stripe calls; disabled-state panel; tax-not-configured; non-CH unsupported_market; config-flip supported-countries; login-link mint asserts header absent + URL in JSON; staff-cannot-mint (403); login-link 404 with no row; login-link 422 for disabled account; cache key isolates data per business.
- [x] (2026-04-24 19:00Z) All 15 pass; full suite baseline 938 → 953 (+15); assertions 3918 → 4070 (+152).

**M8 — Iteration-loop close** ✅
- [x] (2026-04-24 19:00Z) `php artisan test tests/Feature tests/Unit --compact` — 953 passed (4070 assertions) in 53.6s.
- [x] (2026-04-24 19:01Z) `vendor/bin/pint --dirty --format agent` — `{"result":"pass"}`.
- [x] (2026-04-24 19:01Z) `php artisan wayfinder:generate` — regenerated.
- [x] (2026-04-24 19:01Z) `./vendor/bin/phpstan` — `No errors` (150 files scanned).
- [x] (2026-04-24 19:01Z) `npm run build` — clean; main chunk 583 kB (>500 kB warning is pre-existing and unrelated).
- [ ] Stage all changes (`git add -A`); do NOT commit.
- [ ] Hand off for developer review / codex review.

After codex review (if any): apply findings under `## Review — Round N` on the same uncommitted diff, then re-run M8.


## Surprises & Discoveries

- **Observation**: `Cache::remember` cannot model the "use cached value, but retry Stripe and mark stale on failure" pattern.
  **Evidence**: M3's original plan wrapped the Stripe fetch in `Cache::remember($key, 60, fn () => fetchFromStripe())` with a sibling `Cache::get($key)` before the `try` block to grab the prior value for the stale-fallback branch. But `Cache::remember` reads from the SAME key first — if the cached value was still fresh, it returned without invoking the closure and the `try` block never ran. The "Stripe API failure falls back to cached state" test failed because the controller never attempted the fetch.
  **Consequence**: Refactored into a two-layer cache. Cache TTL is 24h (long-lived fallback); the `fetched_at` ISO timestamp inside the payload drives a 60s **freshness** check at the controller level via the new `isFresh()` helper. If cache is fresh → return it. If stale or missing → attempt Stripe → cache on success OR fall back to prior cached value with `stale: true` on failure. This is the actually-correct pattern for "60s freshness with graceful fallback" and it makes the stale-test pass cleanly. `FRESHNESS_SECONDS = 60` and `CACHE_TTL_SECONDS = 86400` constants documented in the controller.

- **Observation**: `$stripe->accounts->createLoginLink(...)` is a method on `AccountService`, not a sub-service like `$stripe->accountLinks`.
  **Evidence**: `vendor/stripe/stripe-php/lib/Service/AccountService.php:137`.
  **Consequence**: `mockLoginLinkCreate` hangs its Mockery expectation off `$this->accounts` (reusing `ensureAccounts()`), not a new `$this->loginLinks` mock as originally drafted. No new sub-service plumbing needed.

- **Observation**: `$stripe->tax->settings->retrieve(...)` is a two-level nested factory.
  **Evidence**: `Stripe\Service\Tax\TaxServiceFactory` exposes `settings`; `Stripe\Service\Tax\SettingsService::retrieve`. The `FakeStripeClient::ensureTaxSettings()` mocks both levels: `$stripe->tax = mock()` whose `settings` property is itself a mock responding to `retrieve`. Works cleanly against the SDK's magic `__get` accessor.


## Decision Log

The following decisions are taken at plan time and locked unless a Surprise & Discovery overturns them. Anything genuinely a product/policy call goes into `## Open Questions` instead.

- **Decision**: Cache **freshness window** = 60s, cache **TTL** = 24h. Business-scoped key (`payouts:business:{id}`).
  **Rationale**: Original plan said "60s Cache::remember" but see `## Surprises & Discoveries` — that pattern can't mark a prior cached value as stale when Stripe fails. Split into two concepts: (a) freshness window (60s, driven by `fetched_at` inside the payload) governs "do we re-hit Stripe?"; (b) cache TTL (24h) governs "how long do we keep a fallback around in case Stripe is briefly unreachable?". On success the fresh payload overwrites the cache; on failure the prior cached value is returned with `stale: true`. Per-business key avoids cross-tenant leaks.
  **Date / Author**: 2026-04-24 / planning agent.

- **Decision**: "Next payout ETA + amount" surfaces as **payout schedule string + current available balance**, not as a per-payout countdown.
  **Rationale**: Stripe does not expose a single "next payout date + amount" field. The closest is `account.settings.payouts.schedule` (one of `daily | weekly | monthly | manual` plus `delay_days`, `weekly_anchor`, `monthly_anchor`). The accurate, honest UI is to render the schedule in human-readable form ("Daily — every business day, 2 days after the charge") plus the available balance ("Approximately CHF 312.00 will be paid out on the next cycle"). The roadmap's intent (locked decision #24: surface status, not management) is satisfied; an exact countdown would be misleading because Stripe can delay payouts for risk review without exposing that to the API.
  **Date / Author**: 2026-04-24 / planning agent.

- **Decision**: Recent payouts list = `payouts.list` with `limit=10`. The roadmap's locked decision #24 prose says "last 3 payouts" but Session 4's brief says "last 10 payouts"; ship 10 because more context costs nothing on a read-only page.
  **Rationale**: Brief is the operational truth; the locked decision sets the semantic floor (≥3) not the ceiling. Stripe's `payouts.list` with `limit=10` returns a single API call with all needed fields (id, amount, currency, status, arrival_date, created).
  **Date / Author**: 2026-04-24 / planning agent.

- **Decision**: Stripe Express login link is **minted on click via a POST endpoint**, not pre-minted on the index render.
  **Rationale**: `accounts.createLoginLink()` returns single-use URLs that Stripe expires in seconds. Pre-minting on `index` would burn a link the admin might never click and risk handing the admin an expired URL. POST-on-click costs one Stripe call per click but guarantees a fresh, valid URL; mockLoginLinkCreate covers the test surface.
  **Date / Author**: 2026-04-24 / planning agent.

- **Decision**: The "Manage payouts in Stripe" button uses `useHttp` (POST returning JSON) + `window.open(url, '_blank', 'noopener')`, NOT Inertia `<Form>` + `Inertia::location`.
  **Rationale**: Roadmap mandates the link opens in a new tab. Inertia's `<Form>` posts via XHR and `Inertia::location` is processed by the Inertia client by full-page navigation in the SAME tab — there is no `target="_blank"` semantics on Inertia's location response. The clean way to open a server-minted URL in a new tab is to call the endpoint via JSON, read the URL out of the response body, and call `window.open` ourselves. `useHttp` is the project-standard JSON helper (per `resources/js/CLAUDE.md`'s "HTTP Requests" rule). The controller therefore returns a JSON response (`['url' => $loginLink->url]`) on the AJAX path, and the React handler opens the new tab with `noopener` for security.
  **Date / Author**: 2026-04-24 / planning agent.

- **Decision**: `PayoutsController::index` uses **`tenant()->business()` + `tenant()->business()->stripeConnectedAccount`** — never an inbound business id. Cross-tenant access is impossible by construction.
  **Rationale**: Locked roadmap decision #45 and D-147's lesson (signed-URL re-pinning is fragile). The payouts page has no signed-URL surface; the standard tenant context is sufficient. The cross-tenant denial test still asserts the negative case via a session pinned to Business A loading the page and seeing only Business A's connected-account data.
  **Date / Author**: 2026-04-24 / planning agent.

- **Decision**: A new `supportedCountries` Inertia prop is carried on the page payload, not a hardcoded `['CH']` literal in the React.
  **Rationale**: D-112 + locked decision #43. The Inertia prop is the single source of truth the React reads; the test that flips the supported set in-process and asserts the banner disappears proves the seam is genuinely config-driven.
  **Date / Author**: 2026-04-24 / planning agent.

- **Decision**: No Inertia shared-prop changes. The page-local `payouts` prop carries `supported_countries`, the connected-account snapshot, the Stripe-fetched data, and a `cached_at` timestamp.
  **Rationale**: `auth.business.connected_account` already carries the verification status the navigation entry needs. Adding a shared-prop `supported_countries` would force every Inertia response to compute it; the payouts page is the single consumer.
  **Date / Author**: 2026-04-24 / planning agent.

- **Decision**: Health-strip chip data comes from the local `stripe_connected_accounts` row (already kept fresh by the `account.updated` webhook, D-128 / D-136), NOT from a fresh `accounts.retrieve` call inside the controller.
  **Rationale**: The local row is Stripe-authoritative within the webhook's eventual-consistency window (typically <5s). Calling `accounts.retrieve` here would (a) double the Stripe API budget, (b) require the same cache TTL handling as balance/payouts, and (c) provide nothing the row doesn't already carry. Payout `schedule.interval` is the one piece NOT on the row — it lives on `account.settings.payouts.schedule` — so the controller calls `accounts.retrieve` once, scoped to the same 60s cache as balance + payouts.
  **Date / Author**: 2026-04-24 / planning agent.

- **Decision**: `D-173` (`bookings.stripe_charge_id` backfill on promotion) **stays deferred**. Session 4's payouts page does not reconcile per-charge.
  **Rationale**: The roadmap brief asked the plan to decide. Session 4's payouts surface lists payouts (`po_…` ids), not the charges that funded them. Cross-referencing a payout to its charges requires `payouts.retrieve($id, ['expand' => ['transfers.data.source_transaction']])` or `balance_transactions.list(['payout' => $id])`, neither of which the brief calls for. Backfilling `stripe_charge_id` would be useful only for an admin "what bookings funded this payout?" UI, which is out of scope (locked decision #24). Punt to a future session per the existing BACKLOG entry.
  **Date / Author**: 2026-04-24 / planning agent.

- **Decision**: No new `D-NNN` introduced at plan time. Three candidates may emerge during implementation (see "## Risks & Notes"); they will be promoted into `docs/decisions/DECISIONS-PAYMENTS.md` if they materialise.
  **Rationale**: Honest accounting — the WHAT here doesn't require new architectural decisions. If exec turns up something binding (e.g. a deviation in cache key shape, a new gate, a Stripe call returning unexpected shapes), it'll be promoted then.
  **Date / Author**: 2026-04-24 / planning agent.


## Review

### Round 1 — Self-review (Codex rate-limited; full pass on the staged diff)

**Verdict**: 3 findings applied; 0 false positives. 2 TS errors caught by the developer's editor (not by `npm run build`, which compiles without strict type-checking on the React side); 1 weak test surfaced during the audit.

- [x] **Finding 1 (P1, TS error)** — `<Tooltip delay={150}>` is invalid: `delay` is a prop of `TooltipProvider`, not `Tooltip` (Root). Base UI Root accepts no `delay`/`closeDelay` directly. `npm run build` skipped strict TS-check on the React side, so the error only surfaced in the editor.
  *Location*: `resources/js/pages/dashboard/payouts.tsx:353`.
  *Fix*: Removed the `delay={150}` prop. Default opening behaviour is fine for our usage. (A pre-existing identical bug in `resources/js/components/calendar/booking-hover-card.tsx:52` is **out of scope for Session 4** — it predates this branch and `npm run build` lets it through.)
  *Status*: done.

- [x] **Finding 2 (P2, TS error)** — `formatSchedule(payouts?.schedule, t)` passes `PayoutSchedule | null | undefined`, but the function signature accepts only `PayoutSchedule | null`.
  *Location*: `resources/js/pages/dashboard/payouts.tsx:510`.
  *Fix*: Coerce with `?? null` → `formatSchedule(payouts?.schedule ?? null, t)`. Same shape as the other `payouts?.…` reads in the file, so the pattern is now consistent.
  *Status*: done.

- [x] **Finding 3 (P2, weak test coverage on cross-tenant isolation)** — The original "cross-tenant" test only proved "admin of B sees B's data" — a positive scoping test, not the cross-tenant attack the locked decision #45 contract mandates. The actual attack vector (admin of A manually sets `current_business_id` to B's id via cookie tampering) is neutralised by `ResolveTenantContext` middleware's self-heal, but no test exercised it on this controller.
  *Location*: `tests/Feature/Dashboard/PayoutsControllerTest.php` (new test added before the existing one).
  *Fix*: Added test "admin pinned to a foreign business id is silently re-pinned to their own and sees their own data". Registers ONLY A's Stripe mocks, pins session to B, asserts the controller reads A's data — Mockery would surface a leak as "method not expected" on B's account, so the test fails loudly on regression.
  *Status*: done.

**Other things checked, no action needed**:

- `useHttp` API: confirmed against `date-time-picker.tsx` + `booking-summary.tsx` patterns — `onSuccess` receives the JSON body directly, not wrapped in `.data`. My `result.url` access is correct.
- `Button loading={busy}` prop: confirmed valid (`button.tsx:54`).
- Route placement: my routes inherit `auth` + `billing.writable` + `role:admin` from the surrounding groups; SaaS-lapsed admin can GET (read-only) but cannot POST login-link. Correct per D-090 + D-116.
- Cache key isolation: per-business key is correct; no cross-tenant cache leak possible by construction.
- All user-facing strings wrapped in `t()` / `__()`.
- Non-CH banner reads `supportedCountries` from the Inertia prop — no hardcoded `'CH'` literal anywhere in the React.
- `accounts.retrieve` from the platform side (no `stripe_account` header) is the right SDK shape for fetching a connected account's authoritative state.
- `formatAmount` divides by 100 — assumes 2-decimal currencies. CHF + EUR are both 2-decimal, locked decision #43 keeps non-CH out of online payments today, so this is fine. If/when zero-decimal currencies (JPY, KRW) come into scope, adjust then. Not worth pre-handling.
- The `unsupported_market` admin can still mint a Stripe Express login link (button is enabled). That's correct: they should be able to manage their Stripe account even if riservo blocks online payments for their country. The 422 only fires for `'disabled'` — Stripe-side suspension.

**Iteration loop after fixes**:
- `php artisan test tests/Feature tests/Unit --compact` — **954 passed (4079 assertions)** in 55.8s (was 953 / 4070; +1 test / +9 assertions for the new cross-tenant case).
- `vendor/bin/pint --dirty --format agent` — `{"result":"pass"}`.
- `./vendor/bin/phpstan` — `No errors`.
- `npm run build` — clean.

No new `D-NNN` introduced by this round. The TS issue on `Tooltip delay` is a Base UI typings mismatch (the prop genuinely doesn't exist on Root), not an architectural decision.


## Outcomes & Retrospective

**What shipped**

- New admin-only Payouts page at `/dashboard/payouts` surfacing Stripe Connect balance (available + pending), last 10 payouts, payout schedule, connected-account health strip (charges/payouts enabled + requirements due), and a one-click "Manage payouts in Stripe" button that mints a fresh single-use Stripe Express dashboard login link and opens it in a new tab (per the `useHttp` + `window.open` decision).
- Banners for Stripe Tax not configured (locked decision #11) and non-CH connected account (locked decision #43). The non-CH check reads `supportedCountries` from an Inertia prop — no hardcoded `'CH'` literal.
- Graceful degradation: 60s in-payload freshness window + 24h cache TTL fallback. Stripe failure falls back to prior cached data with a "Couldn't refresh" banner; empty cache falls back to a "Couldn't load payout state" banner.
- `FakeStripeClient` extended with 4 new mocks (`mockBalanceRetrieve`, `mockPayoutsList`, `mockTaxSettingsRetrieve`, `mockLoginLinkCreate`), each enforcing its connected-account-level / platform-level header contract via Mockery `withArgs`.
- New "Payouts" nav entry in the authenticated layout — admin-only, conditional on an existing connected-account row.
- 15 new feature tests in `tests/Feature/Dashboard/PayoutsControllerTest.php`. Iteration-loop suite grew from 938 / 3918 to **953 passed (4070 assertions)**.

**Against the original Purpose**: fully delivered. An admin with a connected account can see where their money sits, click through to the Stripe dashboard, and get honest feedback when Stripe is unreachable.

**What did NOT ship (all intentional, per the plan)**

- No `bookings.stripe_charge_id` backfill (D-173) — still deferred.
- No payout initiation, schedule change, or pause — locked out by design per decision #24.
- No per-charge reconciliation UI — locked out per decision #24.

**Lessons learned**

- **The `Cache::remember` + sibling `Cache::get` pattern for "fresh cache / stale fallback" does not work** (see Surprises & Discoveries). The right shape is separate freshness + TTL concepts. This is the first place in this codebase where an in-payload `fetched_at` freshness check plus a long-lived cache TTL are both load-bearing; a similar need in future work should lift this pattern rather than reach for `Cache::remember`.
- **Stripe SDK service shapes vary**: `createLoginLink` is a method on `AccountService`, not a standalone `loginLinks` service; `tax.settings.retrieve` is a two-level factory. The FakeStripeClient mocking strategy worked in both cases but needed per-call verification against the SDK source.
- **The `useHttp` + `window.open` pattern is clean**: the controller returns JSON, the React handler opens the URL in a new tab with `noopener`. No Inertia ceremony lost on this specific call.

**Carry-overs for BACKLOG**

- None from this session. The page is purely additive and surfaces read-only data — no new architectural debts, no new deferred items.

**New architectural decisions promoted**

- None. No `D-NNN` was introduced; all behaviour flows from existing decisions (#11, #19, #24, #43, #45, D-112, D-127, D-138, D-141, D-150). The 60s freshness / 24h cache TTL split is a mechanical implementation detail of how "brief 60s refresh cadence" is expressed over Laravel's cache API, not an architectural commitment worth a decision record. If a future session needs the same two-layer pattern for a different connected-account read, lifting it into a shared helper at that point is the right move.


## Context and Orientation

For a reader who is new to this repository:

riservo.ch is a Swiss SaaS booking platform. Businesses register, configure their hours and services, and customers book appointments at `riservo.ch/{slug}`. Riservo's revenue is a Cashier-billed monthly subscription on each `Business`; **separately**, this PAYMENTS roadmap is wiring an end-to-end customer-to-professional payment flow on top of Stripe Connect Express, where the customer pays the professional directly at booking time and riservo takes zero commission. Stripe Connect Express is Stripe's hosted-onboarding flavour of Connect: the professional walks through a Stripe-served KYC flow; riservo only stores an `acct_…` id and a few capability flags.

The PAYMENTS roadmap has six sessions (1, 2a, 2b, 3, 4, 5). 1–3 shipped. **Session 4** is this one — a read-only payouts surface and a connected-account health strip. Session 5 will lift the UI hide that keeps `payment_mode=online|customer_choice` invisible in Settings.

Key terms a novice needs:

- **Connected account** — the professional's Stripe Express account (one per `Business`, locked decision #22). Stored locally as a `stripe_connected_accounts` row keyed by `business_id`.
- **`stripe_account` per-request option** — Stripe's "act on this connected account" header. Calls to APIs that operate on the connected account's resources (Checkout sessions, refunds, balance, payouts, tax settings) MUST pass `['stripe_account' => $acct_id]`. Calls to platform-level resources (creating an account, minting a login link to Stripe Express) MUST NOT. The `FakeStripeClient` enforces both contracts via Mockery `withArgs` matchers — a wrong-bucket call fails the test with a "method not expected" diagnostic.
- **Tenant context** — every authenticated dashboard request resolves the active business via `tenant()->business()` (singleton populated by `App\Http\Middleware\ResolveTenantContext`). Cross-tenant access is impossible by construction when controllers route lookups through this helper (locked decision #45).
- **`billing.writable` middleware** — gate that lets safe HTTP verbs (GET / HEAD / OPTIONS) through unconditionally and rejects mutations on a SaaS-lapsed business with a redirect to `settings.billing` (D-090). The Payouts page sits inside this gate; the GET index works for lapsed admins, the POST login-link mint does not.
- **`role:admin` middleware** — admin-vs-staff gate. Payouts is admin-only per the roadmap brief and the consistent locked-decision-#19 / #31 / #35 pattern: money is an admin commercial concern, staff handle bookings.
- **Wayfinder** — Laravel package that auto-generates typed TypeScript functions for Laravel routes / controller actions. The frontend imports `loginLink` from `@/actions/App/Http/Controllers/Dashboard/PayoutsController` rather than hardcoding `/dashboard/payouts/login-link`. `php artisan wayfinder:generate` regenerates the bindings.
- **Inertia** — the React-on-Laravel SPA layer. `Inertia::render('dashboard/payouts', [...props])` returns a normal HTTP response that the Inertia client interprets as a SPA page navigation; `Inertia::location($url)` returns a 409 the client interprets as a full-page redirect. JSON responses from controllers (`response()->json(...)`) are consumed by `useHttp` on the React side without any page navigation.

Files this session touches (all paths repo-relative):

```
app/Http/Controllers/Dashboard/PayoutsController.php       (new)
routes/web.php                                              (add 2 routes)
resources/js/pages/dashboard/payouts.tsx                    (new)
resources/js/layouts/authenticated-layout.tsx              (add nav item)
resources/js/actions/App/Http/Controllers/Dashboard/PayoutsController.ts  (auto-generated by wayfinder)

tests/Support/Billing/FakeStripeClient.php                  (extend with 4 mocks)
tests/Feature/Dashboard/PayoutsControllerTest.php           (new)

docs/PLAN.md                                                (this file, kept current)
docs/HANDOFF.md                                             (rewritten at close — only if shipped state changes; it does, so yes)
docs/decisions/DECISIONS-PAYMENTS.md                        (only if a new D-NNN materialises during exec)
```

No new migrations. No new models. No changes to existing controllers, services, or webhook handlers. The Session 4 surface is purely additive.


## Plan of Work

### M1 — FakeStripeClient extensions

Open `tests/Support/Billing/FakeStripeClient.php` and add four new helpers, mirroring the existing `mockCheckoutSessionCreateOnAccount` / `mockAccountLinkCreate` patterns:

1. `mockBalanceRetrieve(string $expectedAccountId, array $response = []): self`
   - Connected-account-level — assert `['stripe_account' => $accountId]` is present in `$opts`.
   - SDK signature: `$stripe->balance->retrieve(?$params, ?$opts)`. The first arg is `null` (no params); `$opts` carries the header. The matcher must enforce `$params === null && $opts['stripe_account'] === $accountId`.
   - Default response: `available: [{amount: 31200, currency: 'chf'}]`, `pending: [{amount: 5400, currency: 'chf'}]`. Caller may override.
   - Add `private ?MockInterface $balance` and `ensureBalance()`.

2. `mockPayoutsList(string $expectedAccountId, array $response = []): self`
   - Connected-account-level — same header assertion.
   - SDK signature: `$stripe->payouts->all(?$params, ?$opts)`. The matcher allows any `$params` (the controller passes `['limit' => 10]`); the strict assertion is on the header.
   - Default response: a `data: [...]` array of three `Stripe\Payout::constructFrom` objects with realistic fields (`id: po_test_…`, `amount: 12500`, `currency: 'chf'`, `status: 'paid'`, `arrival_date: now+1d` as a UNIX timestamp, `created: now`). Returns `Stripe\Collection::constructFrom(['data' => […], 'has_more' => false])` (same pattern Cashier uses).
   - Add `private ?MockInterface $payouts` and `ensurePayouts()`.

3. `mockTaxSettingsRetrieve(string $expectedAccountId, array $response = []): self`
   - Connected-account-level.
   - SDK signature: `$stripe->tax->settings->retrieve(?$params, ?$opts)`. The `tax` service factory exposes `settings`, which exposes `retrieve`. Mock the chain by setting `$stripe->tax = Mockery::mock()` with a `settings` property that is itself a Mockery mock supporting `retrieve`.
   - Default response: `Stripe\Tax\Settings::constructFrom(['status' => 'active', 'defaults' => (object) ['tax_behavior' => 'inclusive']])`. Tests that exercise the not-configured banner pass `['status' => 'pending']` (Stripe's actual not-configured string is `'pending'`; verify against the API ref in the test fixture).

4. `mockLoginLinkCreate(string $expectedAccountId, array $response = []): self`
   - **Platform-level** — assert `stripe_account` is ABSENT in `$opts` (mirrors `mockAccountCreate`'s `assertPlatformLevel`).
   - SDK signature: `$stripe->accounts->createLoginLink(string $parentId, ?$params, ?$opts)`. The connected account id is the FIRST arg, not the header. The matcher must enforce `$parentId === $expectedAccountId && assertPlatformLevel($opts)`.
   - Default response: `Stripe\LoginLink::constructFrom(['url' => 'https://connect.stripe.com/express/.../login_test_'.uniqid()])`.

Add a brief docblock referring to locked decision #5, locked decision #24, and (for the Tax helper) locked decision #11.

### M2 — `PayoutsController` + Inertia page skeleton

Create `app/Http/Controllers/Dashboard/PayoutsController.php`:

```php
<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Admin-only payouts surface (PAYMENTS Session 4). Read-only by design
 * (locked roadmap decision #24): surfaces balance + last 10 payouts +
 * payout schedule + connected-account health, plus a one-click mint
 * for the Stripe Express dashboard login link.
 */
class PayoutsController extends Controller
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function index(Request $request): Response { /* … */ }

    public function loginLink(Request $request): JsonResponse { /* … */ }
}
```

`index` resolves the business via `tenant()->business()` (404 if null), reads the `stripeConnectedAccount` relation, and:

- If the row is null → Inertia render with `account: null` (the page renders the Session 1 "Enable online payments" CTA).
- If the row's `verificationStatus()` is `'disabled'` → Inertia render with the disabled panel + `requirements_disabled_reason`.
- Otherwise → fetch payout state via the cache, render the full page.

The cache layer:

```php
$cacheKey = "payouts:business:{$business->id}";
$cached = Cache::get($cacheKey);

try {
    $payload = Cache::remember($cacheKey, now()->addSeconds(60), function () use ($row) {
        $balance      = $this->stripe->balance->retrieve(null, ['stripe_account' => $row->stripe_account_id]);
        $payouts      = $this->stripe->payouts->all(['limit' => 10], ['stripe_account' => $row->stripe_account_id]);
        $account      = $this->stripe->accounts->retrieve($row->stripe_account_id);
        $taxSettings  = $this->stripe->tax->settings->retrieve(null, ['stripe_account' => $row->stripe_account_id]);

        return [
            'available'        => $this->mapBalanceArms($balance->available ?? []),
            'pending'          => $this->mapBalanceArms($balance->pending ?? []),
            'payouts'          => $this->mapPayouts($payouts->data ?? []),
            'schedule'         => $this->mapSchedule($account->settings->payouts->schedule ?? null),
            'tax_status'       => $taxSettings->status ?? null,
            'fetched_at'       => now()->toIso8601String(),
            'stale'            => false,
            'error'            => null,
        ];
    });
} catch (ApiErrorException $e) {
    report($e);
    if ($cached === null) {
        $payload = ['available' => [], 'pending' => [], 'payouts' => [], 'schedule' => null,
                    'tax_status' => null, 'fetched_at' => null, 'stale' => true, 'error' => 'unreachable'];
    } else {
        $payload = array_merge($cached, ['stale' => true]);
    }
}
```

Note the `$cached = Cache::get(...)` BEFORE the `try` so an exception path can fall back to the prior cached value with `stale: true`. The `Cache::remember` body re-fetches; on success it returns the fresh payload AND writes the cache atomically.

`loginLink` mints the URL on demand:

```php
public function loginLink(Request $request): JsonResponse
{
    $business = tenant()->business();
    abort_if($business === null, 404);

    $row = $business->stripeConnectedAccount;
    abort_if($row === null, 404);
    abort_if($row->verificationStatus() === 'disabled', 422);

    try {
        $link = $this->stripe->accounts->createLoginLink($row->stripe_account_id);
    } catch (ApiErrorException $e) {
        report($e);
        return response()->json(['error' => __('Could not open Stripe right now. Please try again.')], 502);
    }

    return response()->json(['url' => $link->url]);
}
```

Routes (add at the end of the existing admin-only block in `routes/web.php`, near `dashboard.bookings.refunds.store` to keep payment-related routes together):

```php
// PAYMENTS Session 4 — admin-only payouts surface (locked decisions
// #22 / #24 / #45). Read-only on the connected account; the only side
// effect is minting a fresh Stripe Express login link on click.
Route::get('/dashboard/payouts', [PayoutsController::class, 'index'])
    ->name('dashboard.payouts');
Route::post('/dashboard/payouts/login-link', [PayoutsController::class, 'loginLink'])
    ->name('dashboard.payouts.login-link');
```

Both routes inherit `role:admin` + `billing.writable` from the surrounding groups. Add `use App\Http\Controllers\Dashboard\PayoutsController;` to the top imports. Then run `php artisan wayfinder:generate` so `@/actions/App/Http/Controllers/Dashboard/PayoutsController` becomes importable on the React side.

### M3 — Caching + degradation

Already covered in M2's controller skeleton above. The two non-obvious bits:

1. **Cache key isolation**: every cache read goes through `payouts:business:{$business->id}` — never a key derived from the user, never a key without the business id. Two admins on different businesses must not see each other's data; one admin who switches tenants mid-session must read the new business's cache. The test `cache key isolates data per business` proves this.

2. **Cache pollution on failure**: only `Cache::remember`'s success path writes the cache. The exception branch must NOT write a partial / error payload to the cache (or the next reader sees stale failure as if it were truth). The `Cache::get(...)` BEFORE the try-catch is read-only.

### M4 — Health strip + banners

`resources/js/pages/dashboard/payouts.tsx` renders:

```
┌──────────────────────────────────────────────────────────────┐
│ Page header: "Payouts" + small description                   │
│ ─────────────────────────────────────────────────────────── │
│ Health strip:                                                │
│   [Charges enabled] [Payouts enabled] [No requirements due]  │
│ ─────────────────────────────────────────────────────────── │
│ Banners (when applicable):                                   │
│   - Stripe Tax not configured (locked decision #11)          │
│   - Online payments only for CH-located businesses (#43)     │
│   - Couldn't refresh — showing last known state (cache stale)│
│ ─────────────────────────────────────────────────────────── │
│ Cards: [Available balance] [Pending balance]                 │
│        [Payout schedule]   [Manage payouts in Stripe →]      │
│ ─────────────────────────────────────────────────────────── │
│ Recent payouts table:                                        │
│   Date | Amount | Status | Arrival date                      │
└──────────────────────────────────────────────────────────────┘
```

The chips on the health strip carry both text and icon so colour-blind users see the state clearly:
- `chargesEnabled` → green check + "Charges enabled" / red X + "Charges disabled".
- `payoutsEnabled` → same shape.
- `requirementsCurrentlyDue.length` → green check + "No requirements due" / amber warning + "{n} requirement(s) due — see Stripe".

Tooltip on the requirements chip lists the first three `requirements_currently_due` entries verbatim (Stripe's strings are user-readable enough — e.g. `tos_acceptance.date`, `external_account`).

Banners use the existing `<Alert>` primitive from `@/components/ui/alert`. The non-CH banner reads from the `supportedCountries` page prop (NOT a hardcoded `['CH']` constant) and shows `'Online payments in MVP support {supportedCountries.join(', ')}-located businesses only'` with a friendly fallback when the supported set is multi-country.

The "Manage payouts in Stripe" card holds a button:

```tsx
<Button
    onClick={openLoginLink}
    aria-label={t('Open Stripe Express dashboard in a new tab')}
    disabled={loading || disabled}
>
    {loading ? t('Opening Stripe…') : t('Manage payouts in Stripe')}
</Button>
```

`openLoginLink` calls `useHttp` → POST to `loginLink()` (Wayfinder action) → on success `window.open(response.data.url, '_blank', 'noopener')`. On 502 (Stripe unreachable): toast the error message via the existing toast surface; on 404: shouldn't happen (button is disabled when there's no row); on 422: also shouldn't happen (button is disabled when status is `'disabled'`).

The unverified-account branch shows a "Connect Stripe to see your payouts here" card that links via `<Link href={connectedAccountIndex().url}>` to the settings page (not a `<Form>` — registration has its own surface there).

### M5 — Stripe Express login link

Already covered in M2 + M4. Test surface: a feature test that mocks `mockLoginLinkCreate` with an explicit `$expectedAccountId`, posts to `/dashboard/payouts/login-link`, decodes the JSON body, and asserts `'url'` is the login-link URL. A second test posts as a staff user (no admin role) and asserts 403. A third test posts on a business with no `stripeConnectedAccount` row and asserts 404.

### M6 — Navigation entry

In `resources/js/layouts/authenticated-layout.tsx` around lines 84-94 (the `navItems` array), add a "Payouts" entry between "Customers" and "Settings", admin-only, conditional on the connected-account prop being non-null. The conditional uses `connectedAccount` (already destructured at line 76):

```tsx
...(isAdmin && connectedAccount !== null
    ? [{
        label: t('Payouts'),
        href: payoutsIndex.url(),
        active: currentPath.startsWith('/dashboard/payouts'),
        icon: WalletIcon,
    }]
    : []),
```

Import `WalletIcon` from `lucide-react` next to the existing `CalendarDaysIcon` / `ClipboardListIcon` imports. Import `index as payoutsIndex` from the auto-generated `@/actions/App/Http/Controllers/Dashboard/PayoutsController`.

Note: the existing prop sets `connectedAccount = null` when no row exists (see `HandleInertiaRequests::resolveBusinessConnectedAccount` line 236 onward). The cleanest predicate is `connectedAccount !== null` since that's what the middleware emits today. Verify the exact shape during exec — there is one wrinkle to confirm in the middleware around the "row exists but trashed" case.

### M7 — Tests

Create `tests/Feature/Dashboard/PayoutsControllerTest.php`. Each test:
- Sets up a `Business` with an admin and a `StripeConnectedAccount::factory()->active()` row.
- Acts as the admin via `actingAs($user)` + `Session::put('current_business_id', $business->id)` (or whatever the existing test pattern in `tests/Feature/Dashboard/` uses — copy from `BookingRefundsPanelTest.php`).
- Mocks the four Stripe calls via `FakeStripeClient::for($this)->mockBalanceRetrieve(...)->mockPayoutsList(...)->mockAccountRetrieve(...)->mockTaxSettingsRetrieve(...)`.

Test list (target ~13 cases):

1. `admin sees the payouts page with balance + last payouts + schedule + tax-active banner absent` — happy path.
2. `staff cannot reach the payouts page` — 403.
3. `cross-tenant admin reading a different business's payouts gets 404` — locked decision #45.
4. `Stripe API timeout renders the cached prior state with a stale banner` — seed the cache, mock subsequent retrieve to throw.
5. `Stripe API timeout with empty cache renders the unreachable-state banner` — mock retrieve to throw, cache empty.
6. `unverified connected account renders the onboarding CTA, no Stripe calls made` — pending-row, FakeStripeClient with NO mocks (any call would fail Mockery).
7. `disabled connected account renders the disabled-state panel with the verbatim Stripe reason` — mock fixtures put `requirements_disabled_reason = 'rejected.fraud'`.
8. `Stripe Tax not configured shows the warning banner` — mockTaxSettingsRetrieve with `status: 'pending'`.
9. `non-CH country shows the unsupported-market banner` — country = 'DE', config supports `['CH']`.
10. `flipping supported_countries to include DE removes the unsupported-market banner` — same row, `config(['payments.supported_countries' => ['CH', 'DE']])`, assert banner gone.
11. `clicking Manage payouts in Stripe mints a fresh login link via accounts.createLoginLink` — mockLoginLinkCreate with header asserted ABSENT, post to login-link route, assert JSON body carries `url`.
12. `staff cannot mint a Stripe login link` — 403.
13. `cache key isolates data per business` — admin of A reads, then admin of B reads; both see only their own balance.

If a fourteenth test makes sense for "Stripe API errors are reported to Laravel's reporter" (so we can assert errors are captured via the existing `report()` calls), add it.

### M8 — Iteration-loop close

Run the standard sequence:

```bash
php artisan test tests/Feature tests/Unit --compact
vendor/bin/pint --dirty --format agent
php artisan wayfinder:generate
./vendor/bin/phpstan
npm run build
```

All five must succeed. Stage everything. Stop. Report the diff summary to the developer.


## Concrete Steps

The exec milestones above are the canonical step list. The sequence is M1 → M2 → M3 → M4 → M5 → M6 → M7 → M8. Each milestone's bullets in `## Progress` get checked off as the work lands; `## Surprises & Discoveries` and `## Decision Log` get appended as discoveries / decisions surface mid-implementation.

Run the iteration-loop tests after M2 (controller skeleton) at the latest, to catch any wiring mistakes before the React work compounds them. Re-run after each subsequent milestone.


## Validation and Acceptance

End-state acceptance — what a developer can do to verify the session shipped correctly:

1. **Test acceptance**:
   ```bash
   php artisan test tests/Feature tests/Unit --compact
   ```
   Expected: `~951 passed (~3970 assertions)` (baseline 938 + ~13 new tests; assertion count grows roughly proportionally). All five iteration-loop commands succeed. PHPStan stays clean.

2. **Manual acceptance** (against a dev server with seed data):
   ```bash
   composer run dev
   ```
   - As an admin of a business WITHOUT a connected account: visit `/dashboard/payouts`. The page renders the "Connect Stripe" CTA, no API calls. The sidebar does NOT show a "Payouts" entry.
   - As an admin of a business WITH an `active` connected account (seeded via the factory): visit `/dashboard/payouts`. The page shows the four cards, the health strip with three green chips, the payouts table. Click "Manage payouts in Stripe": a new tab opens to a Stripe Express URL.
   - As staff of any business: `/dashboard/payouts` returns 403.
   - As an admin of a business with a `disabled` connected account: the page renders the disabled-state panel; the Manage button is disabled.

3. **Acceptance against Stripe failure modes** (proven by tests, not manually):
   - Stripe API down → page renders cached data with the "Couldn't refresh" banner.
   - Stripe API down + empty cache → page renders the "Couldn't load payout state" banner with a Retry button.
   - Stripe Tax never configured → tax-not-configured banner shown.

4. **Cross-tenant safety**:
   ```bash
   php artisan test tests/Feature/Dashboard/PayoutsControllerTest.php --compact --filter="cross-tenant"
   ```
   Expected: passes.

5. **Config-driven country gate**:
   ```bash
   php artisan test tests/Feature/Dashboard/PayoutsControllerTest.php --compact --filter="supported_countries"
   ```
   Expected: passes. The flip-config test proves no hardcoded literals.


## Idempotence and Recovery

The plan introduces only additive changes. Re-running it from scratch on a fresh worktree produces the same result. No migrations, no data backfills, no state mutations to existing tables.

If any milestone breaks the iteration loop, revert the milestone's diff (`git checkout -- {paths}`), debug from the failing test's output, and re-apply.

The cache layer uses `Cache::remember` which is idempotent under retry (a second concurrent request either hits the populated cache or runs its own retrieve and overwrites with the same data). The 60s TTL means even a caching bug that polluted a key would self-correct within a minute.


## Artifacts and Notes

Expected new files at session close (paths repo-relative):

```
app/Http/Controllers/Dashboard/PayoutsController.php
resources/js/pages/dashboard/payouts.tsx
resources/js/actions/App/Http/Controllers/Dashboard/PayoutsController.ts  (auto-generated)
tests/Feature/Dashboard/PayoutsControllerTest.php
```

Expected modifications:

```
routes/web.php                                              (+ 2 routes + 1 import)
resources/js/layouts/authenticated-layout.tsx              (+ 1 nav entry)
tests/Support/Billing/FakeStripeClient.php                  (+ 4 mock helpers)
docs/PLAN.md                                                (this file, kept current)
docs/HANDOFF.md                                             (rewritten at close)
```

No expected new files in `app/Models/`, `app/Services/`, `database/migrations/`, `app/Enums/`, or `app/Exceptions/`.


## Interfaces and Dependencies

### Controller signatures

In `app/Http/Controllers/Dashboard/PayoutsController.php`:

```php
final class PayoutsController extends Controller
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function index(Request $request): \Inertia\Response;

    public function loginLink(Request $request): \Illuminate\Http\JsonResponse;
}
```

### Inertia page props

`resources/js/pages/dashboard/payouts.tsx` consumes:

```ts
interface PayoutsPageProps {
    account: AccountState | null;          // same shape as connected-account.tsx
    payouts: PayoutsPayload | null;        // null when account is null OR disabled
    supportedCountries: string[];          // from config('payments.supported_countries')
}

interface PayoutsPayload {
    available: BalanceArm[];               // [{amount: 31200, currency: 'chf'}, ...]
    pending: BalanceArm[];
    payouts: PayoutRow[];
    schedule: PayoutSchedule | null;
    taxStatus: 'active' | 'pending' | 'unrecognized' | null;
    fetchedAt: string | null;              // ISO 8601
    stale: boolean;                        // true when cache fell back due to Stripe error
    error: 'unreachable' | null;
}

interface BalanceArm {
    amount: number;                        // cents
    currency: string;                      // ISO-4217 lower-cased
}

interface PayoutRow {
    id: string;                            // 'po_…'
    amount: number;                        // cents
    currency: string;
    status: string;                        // Stripe's verbatim status
    arrivalDate: number | null;            // UNIX timestamp; render via Intl.DateTimeFormat
    createdAt: number;                     // UNIX timestamp
}

interface PayoutSchedule {
    interval: 'manual' | 'daily' | 'weekly' | 'monthly';
    delayDays: number | null;
    weeklyAnchor: string | null;
    monthlyAnchor: number | null;
}
```

### Wayfinder routes

After `php artisan wayfinder:generate` regenerates them, the React imports from `@/actions/App/Http/Controllers/Dashboard/PayoutsController`:

```ts
import { index, loginLink } from '@/actions/App/Http/Controllers/Dashboard/PayoutsController';

// usage:
const indexUrl = index.url();             // '/dashboard/payouts'
const loginLinkAction = loginLink();      // { method: 'post', url: '/dashboard/payouts/login-link' }
```

### FakeStripeClient additions

```php
public function mockBalanceRetrieve(string $expectedAccountId, array $response = []): self;
public function mockPayoutsList(string $expectedAccountId, array $response = []): self;
public function mockTaxSettingsRetrieve(string $expectedAccountId, array $response = []): self;
public function mockLoginLinkCreate(string $expectedAccountId, array $response = []): self;
```

All four assert their per-request-options bucket (connected-account-level for the first three, platform-level for the fourth) via `withArgs`.


## Open Questions

These need a developer answer **before** exec opens, or at the latest mid-M4 if they surface late:

1. **"Manage payouts in Stripe" — `useHttp` POST + `window.open` (new tab) versus `<Form>` POST + `Inertia::location` (same tab)?**

   The roadmap says "opens in a new tab". The decision-log entry above commits to `useHttp` + `window.open` because Inertia's `location` response cannot target `_blank`. I want to confirm this matches the developer's intent — the trade-off is "lose Inertia ceremony to gain new-tab semantics". My recommendation is **keep `useHttp` + `window.open`** because Stripe Express's dashboard is a workspace the admin will spend time in, not a one-shot redirect.

2. **Tax status banner copy when `tax_settings.status` is something other than `'active'` or `'pending'`** — Stripe's API ref lists possible values; the roadmap copy assumes anything non-`active` triggers the banner. Confirm: any non-`active` value → banner? (My plan defaults to yes — that's the safest reading of locked decision #11.)

3. **Health strip "requirements due" tooltip — show the first 3 entries verbatim, or first 1?** Stripe's `requirements_currently_due` strings are technical (e.g. `tos_acceptance.date`); 3 may be too noisy in a tooltip. My default is 3 with a "+N more" suffix; happy to drop to 1 + "and N more — see Stripe" if the developer prefers minimalism.


## Risks & Notes

- **Stripe SDK shape verification needed in M1**: I'm reading the SDK service signatures from `vendor/stripe/stripe-php/lib/Service/*` (PayoutService, BalanceService, AccountService, Tax/SettingsService) — these match what M1 plans, but the Tax service factory in particular is namespaced (`$stripe->tax->settings->retrieve(...)`) and requires the FakeStripeClient to mock the chain correctly. If the chain mocking doesn't quite match the SDK's internal `__get` magic, expect a small fix-up in M1.

- **`Stripe\Collection::constructFrom` shape for `payouts->all`**: The mock helper for `mockPayoutsList` returns `Stripe\Collection::constructFrom(['data' => [...], 'has_more' => false])`. The controller iterates `$collection->data` (which the SDK's `Collection` exposes as a public property). Verified via grep against existing Cashier code that uses the same pattern.

- **Inertia `useHttp` JSON response shape**: The `loginLink` controller returns `response()->json(['url' => $link->url])`. Inertia's `useHttp` parses the body as JSON and exposes it as `response.data`. Confirm the React handler reads `response.data.url`, not `response.url`.

- **Cache key TTL race**: a Stripe-side payout commit immediately after the cache populates means the admin won't see the new payout for up to 60s. Acceptable for an MVP read-only page; mention in the in-app help text if "data may be up to 1 minute behind" is felt necessary.

- **`payouts.list` pagination**: the controller passes `limit=10` and reads only the first page. Stripe returns `has_more: true` if more exist; we ignore it (locked decision #24 says "last 3" / brief says "last 10" — both bounded). No risk; just naming the choice.

- **What happens if a Business onboards Stripe Connect AFTER the navigation entry's `connectedAccount !== null` predicate is computed?** Inertia recomputes shared props on every request, so a fresh page navigation surfaces the nav entry within one round-trip. No caching concern.

- **PHPStan level-5 generic inference on `Cache::remember`**: the closure's return type infers from the body. The body returns an `array{available: …, pending: …, …}` shape; PHPStan sometimes struggles with the nested `Stripe\Balance` → array conversion. If the static analyser complains, add an inline `@var` hint or extract the body into a typed private method. Mirror D-148's by-reference workaround if needed.

---

That's the plan. Stopping for developer approval before any code edit (gate one).
