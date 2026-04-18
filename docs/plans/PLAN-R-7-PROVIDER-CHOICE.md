---
name: PLAN-R-7-PROVIDER-CHOICE
description: "R-7: server-side enforcement of allow_provider_choice"
type: plan
status: shipped
created: 2026-04-16
updated: 2026-04-16
---

# PLAN-R-7 — Server-side enforcement of `allow_provider_choice`

**Session**: R-7 — provider-choice policy enforcement
**Source**: `docs/reviews/ROADMAP-REVIEW.md` §R-7, `docs/reviews/REVIEW-1.md` issue #7
**Status**: proposed (plan-only)
**Date**: 2026-04-16
**Depends on**: R-1B (Provider first-class + `allow_provider_choice` rename — D-061), R-2 (TenantContext — D-063), R-3 (`BelongsToCurrentBusiness` rule — D-064), R-5/R-6 (no direct dependency; this session touches different files)

---

## 1. Context

### 1.1 The finding

`allow_provider_choice` is a Business setting (boolean) governing whether customers may pick a specific provider in the public booking flow. The setting is respected by the happy-path UI but **not enforced server-side**. REVIEW-1 §#7 flagged two concrete gaps:

1. **React step-init** — `resources/js/pages/booking/show.tsx:36` unconditionally initialises the flow to the `'provider'` step whenever a service is pre-selected via URL, regardless of the business setting. A customer visiting `riservo.ch/salon-mario/haircut` on a business with `allow_provider_choice = false` is dropped into a provider picker the business has explicitly opted out of.
2. **POST honours `provider_id`** — `PublicBookingController::store()` validates `provider_id` only against service/business membership via `StorePublicBookingRequest`'s inline closure (`app/Http/Requests/Booking/StorePublicBookingRequest.php:28-50`) and a second re-check in the controller (`PublicBookingController.php:222-231`). Neither layer consults `allow_provider_choice`, so a crafted POST can force-select a specific provider even when the business has disabled provider choice.

The R-7 investigation during this planning session confirms the adjacent **read** endpoints have the same gap:

3. `PublicBookingController::providers()` (`PublicBookingController.php:93-119`) returns the full list of providers for a service, never consults `allow_provider_choice`. An honest frontend respecting the setting never calls it; a crafted client can harvest provider IDs.
4. `PublicBookingController::availableDates()` (`PublicBookingController.php:121-162`) accepts `provider_id`, looks it up via `$business->providers()->where('id', ...)->firstOrFail()`, and returns provider-scoped availability. Never consults the setting.
5. `PublicBookingController::slots()` (`PublicBookingController.php:164-196`) accepts `provider_id` with the same shape, same behaviour, same gap.

Five server surfaces (one listing endpoint + two availability endpoints + one POST + the request validator) know about `provider_id` and none of them check `allow_provider_choice`. The setting today is a **client-side suggestion**, not a server-enforced policy.

### 1.2 What actually works today

- `PublicBookingController::show()` (`PublicBookingController.php:37-91`) correctly sends `allow_provider_choice` to the Inertia page (line 76). The frontend receives the flag; it's the step-init that ignores it.
- `resources/js/pages/booking/show.tsx`:
  - Line 51-55 — `handleServiceSelect()` respects the setting (jumps to `'datetime'` when off).
  - Line 94 — `goBack()` respects the setting.
  - Line 105-108 — `totalSteps` / `stepOrder` respects the setting.
  - **Line 36 — the `useState` initial value does NOT respect the setting.** Single bug in the React flow.
- `DateTimePicker` (`resources/js/components/booking/date-time-picker.tsx:67-68, 86`) only sends `provider_id` when `providerId` is truthy. Since the provider step is skipped when choice is off, `selectedProvider` stays `null` and no `provider_id` is sent. So the read endpoints currently receive `provider_id` only when the (broken) step-init incorrectly routes the customer through the provider step. The happy-path honest frontend doesn't stress the server gap — only crafted requests or a post-fix race would.
- `ProviderPicker` (`resources/js/components/booking/provider-picker.tsx`) does not consult `allow_provider_choice` — it just renders whatever `providers()` returns. It is only mounted when `step === 'provider'`, so today it is only reachable via the step-init bug. After the fix it becomes unreachable when the setting is off.

### 1.3 Scope decision — R-7 alone

The session brief invites bundling with R-8 (calendar hydration + mobile view-switcher) or R-9 (popup embed service prefilter + modal robustness). The argument for bundling is "both are medium-priority frontend correctness items". The argument against is scope.

R-7's work breakdown:
- Five server surfaces that each need a policy decision (ignore vs reject vs fall-through), a code change, and a test. The decision matrix is small but non-trivial — see §3.2.
- One React initial-step bug fix (one-line change, but requires an Inertia-prop contract assertion).
- A new architectural decision **D-068** recording where the gate lives and what the gate does, because this is the first "business setting enforced server-side" pattern in the codebase and future settings (`confirmation_mode`, `cancellation_window_hours`, `payment_mode`) will want to follow the same template.

That is a focused, reviewable increment. Bundling it with R-8 (calendar DOM + mobile view switcher across `week-view.tsx`, `current-time-indicator.tsx`, `calendar-header.tsx`, plus a mobile view replacement) or R-9 (embed.js focus-trap / scroll-lock / duplicate-overlay guard + a canonical service-prefilter contract that itself warrants a decision) doubles the surface with no shared files and no shared concepts. R-8 and R-9 touch the calendar / embed respectively — zero overlap with R-7's public booking flow and settings enforcement.

**Recommendation: plan R-7 alone. Filename `PLAN-R-7-PROVIDER-CHOICE.md`.** R-8 and R-9 are independently planned in follow-up sessions.

### 1.4 The broader pattern (noted, not expanded)

`allow_provider_choice` is one of five business-booking settings read by the public flow. Today only it has a documented server-enforcement gap:

| Setting | Server-enforced? | Where |
| --- | --- | --- |
| `confirmation_mode` | **Yes** | `PublicBookingController::store():280-283` derives the booking status from `$business->confirmation_mode` — customer cannot influence. |
| `allow_provider_choice` | **No** | This plan. |
| `cancellation_window_hours` | **Yes** | `BookingManagementController::cancel()` / `CustomerBookingController::cancel()` enforce the window before cancelling. |
| `payment_mode` | **N/A for MVP** | Online payment deferred per D-007; only `offline` is fully wired. |
| `assignment_strategy` | **Yes** | `SlotGeneratorService::assignProvider()` reads the strategy. |
| `reminder_hours` | **Yes** | `SendBookingReminders` command reads per-business. |

So `allow_provider_choice` is the only gap among live settings. This plan fixes it. It does not propose expanding scope to other settings — they already do the right thing. If a future session discovers another gap, it can follow D-068's pattern.

---

## 2. Goal and scope

### Goal

Make `allow_provider_choice = false` binding on the server for every public booking surface that knows about `provider_id`, and fix the React step-init so the honest customer flow matches the server contract. Record the design as **D-068** so future business-setting enforcements have a pattern.

### In scope

- **Backend — `PublicBookingController`:**
  - `providers()` returns an empty list when the setting is off.
  - `availableDates()`, `slots()`, `store()` **ignore** a submitted `provider_id` when the setting is off and behave exactly as if the field were not sent.
  - A private helper on the controller centralises the "treat as null when setting is off" logic — one expression, three call sites.
- **Backend — keep `StorePublicBookingRequest` unchanged.** It still validates that `provider_id` (when non-null) names a real provider in the business; that existence check remains useful as a first-line 422 against genuine fat-finger IDs. The policy gate lives in the controller, not the request. See §3.3 for the rationale.
- **Frontend — `resources/js/pages/booking/show.tsx:36`**: initial step respects `allow_provider_choice`. When a service is preselected and choice is off, step starts at `'datetime'` instead of `'provider'`.
- **Decision — D-068** in `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md`: gate location + "ignore, don't reject" UX + why.
- **Tests — 6 new feature tests** (matrix of "setting = off" × each endpoint + one Inertia prop contract assertion). See §5.

### Out of scope

- **Manual booking (`Dashboard\BookingController::store`).** The setting is explicitly customer-only per SPEC §7.6 ("Allow **customer** to choose collaborator"). Manual bookings are staff-initiated; staff always pick the provider (or auto-assign). This matches D-051's analogous treatment of `confirmation_mode` ("does not affect manual bookings"). The plan records this explicitly so the test matrix does not drift into manual booking paths.
- **The `ProviderPicker` component's internal gate.** The step-init fix makes the picker unreachable when the setting is off; adding a redundant in-component guard would hide the gate from the reading flow. If a future deep-link to `?step=provider` is ever added, the component can gain its own guard then.
- **UI copy for the "choice disabled" state.** When the setting is off, the flow is 4 steps (service → datetime → details → summary → confirmation). Existing copy already handles this path (the `stepLabels` map covers all four).
- **Other business settings' server enforcement.** §1.4 audited them. They're already correct.
- **Broader refactor of `PublicBookingController`.** The controller is getting thick (354 lines) but the R-5/R-6 session deliberately left it alone to keep reviewability. This session adds one private helper and touches four methods surgically; it does not extract or reshape.

---

## 3. Approach

### 3.1 D-068 (new) — server-side enforcement of `allow_provider_choice`

**File**: `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md` (public-flow booking policy).

Proposed text:

> ### D-068 — Server-side enforcement of `allow_provider_choice` via ignore-and-fall-through
>
> - **Date**: 2026-04-16
> - **Status**: accepted
> - **Context**: `allow_provider_choice` was respected by the multi-step React flow but not enforced by the four server surfaces that read or honour `provider_id` (`PublicBookingController::providers`, `availableDates`, `slots`, `store`). A crafted POST could target a specific provider; a preselected-service URL initialised the flow on the provider step regardless of the setting. The setting was effectively a client-side suggestion, not a business policy.
> - **Decision**: The setting is gated **in the controller**, not in the Form Request. When `$business->allow_provider_choice === false`, every server surface treats the effective `provider_id` as if it were not submitted:
>   - `providers()` returns an empty list (`200 OK`, `{ providers: [] }`).
>   - `availableDates()`, `slots()`, and `store()` ignore any submitted `provider_id` and compute / assign as the "any provider" branch.
>   A private helper `resolveProviderIfChoiceAllowed()` centralises the single expression `($business->allow_provider_choice && $providerId) ? $business->providers()->...->firstOrFail() : null` used across the three availability / store methods. `StorePublicBookingRequest` continues to validate that a submitted `provider_id` names a provider in the business (existence + tenant scope via its inline closure), because that check is a first-line 422 against invalid IDs regardless of the setting.
> - **Consequences**:
>   - Honest clients that respect the setting see no behaviour change — they never submit `provider_id` when choice is off.
>   - Crafted requests that submit `provider_id` are silently downgraded to the "any provider" branch. No 422, no 403 — the policy is enforced without leaking a diagnostic that lets a probe distinguish "my provider_id was rejected" from "my ID was never considered".
>   - A honest-client race (admin toggles off mid-session) does not produce a hard error for the customer: their flow degrades to auto-assignment.
>   - The pattern is reusable — future "business setting enforced server-side" gaps (none currently exist; audited in §1.4) can follow the same template: gate in the controller, treat the setting as a silent modifier of the request, record as a decision.
> - **Rejected alternatives**:
>   - *Gate in `StorePublicBookingRequest`.* The Form Request knows about the schema, not the business policy. Moving the gate there would couple validation to a business-model read that varies at runtime. Also: the same policy needs to apply to three read endpoints that have no Form Request — the gate would have to live in two places.
>   - *Reject with 422 when `provider_id` is submitted but choice is off.* Cleaner signal but turns a crafted probe into a diagnostic (`provider_id is rejected` vs `provider_id is ignored`). Also surprises an honest client in a race condition. The product intent ("customers can't pick") is equally well served by silent fall-through.
>   - *Return 403 on `providers()` when setting is off.* Same diagnostic concern. Empty list is semantically correct — the set of *customer-choosable* providers is empty.
>   - *New middleware `EnforceProviderChoice`.* Overkill for one setting and one controller. Hides the policy away from the reading flow.

### 3.2 Decision matrix per server surface

| Surface | Behaviour when `allow_provider_choice = false` | Rationale |
| --- | --- | --- |
| `providers()` | Return `200 { providers: [] }` — short-circuit before the service lookup. | Graceful: honest frontend won't call it; crafted requests get no provider data; honest-client race gets an empty UI (weird but not broken). Alternative 403/404 is more diagnostic than necessary. |
| `availableDates()` | Ignore submitted `provider_id`; compute as "any provider". | Fall-through is the read-endpoint analog of auto-assignment. A crafted client asking "when is provider X free?" gets "when is *anyone* free?" — same answer a customer using the honest flow would get. |
| `slots()` | Ignore submitted `provider_id`; compute as "any provider". | Same rationale as `availableDates()`. |
| `store()` | Ignore submitted `provider_id`; run `SlotGeneratorService::assignProvider()` as if `provider_id` were null. | Matches ROADMAP-REVIEW §R-7's explicit directive: "fall through to automatic assignment instead." |
| React step-init | If `preSelectedService && !allow_provider_choice`, start on `'datetime'` instead of `'provider'`. | The happy-path fix. The rest of the React flow already respects the setting; this is the one escaped branch. |

### 3.3 Why the gate is in the controller, not the Form Request

Two constraints:

1. **The same policy applies to three GET endpoints** (`providers`, `availableDates`, `slots`) that have no Form Request — they validate inline on `$request->validate([...])`. Putting the gate in a Form Request fixes only `store()`; the GET endpoints would need a duplicated inline gate anyway.
2. **`StorePublicBookingRequest` already does the right validation.** It checks the submitted `provider_id` is a real provider in the business. That's still useful — an honest client that submits `provider_id: 999999` gets a 422 back regardless of the setting. Layering a policy gate on top (silently unsetting the field) would make the request's behaviour depend on a business-model read, which is atypical for this codebase (Form Requests today are pure-validation, no model queries aside from `BelongsToCurrentBusiness` which is itself tenant-scoped).

The gate is **a one-line controller guard** applied consistently across the four methods:

```php
private function resolveProviderIfChoiceAllowed(Business $business, ?int $providerId): ?Provider
{
    if (! $business->allow_provider_choice || ! $providerId) {
        return null;
    }

    return $business->providers()->where('id', $providerId)->firstOrFail();
}
```

Call sites (all three reduce to one line):
- `availableDates`: `$provider = $this->resolveProviderIfChoiceAllowed($business, $request->integer('provider_id') ?: null);`
- `slots`: same call.
- `store`: `$selectedProvider = $this->resolveProviderIfChoiceAllowed($business, (int) ($validated['provider_id'] ?? 0) ?: null);` — then drop the inline block at `PublicBookingController.php:221-231` since the helper subsumes it. Note: the helper uses `firstOrFail()` just like the current code in `availableDates`/`slots`; `store()` currently emits a `409` for a missing provider (because the service-membership re-check runs inside the transaction), but in practice the Form Request rejects the ID first with a 422. After the change, `store()` consistency moves to "422 if the ID is bogus, ignore if the setting is off" — the 409 branch becomes unreachable for the `provider_id` case but is left in place for defensive reasons (a provider that exists at validation time but is soft-deleted by another request between validation and transaction still reaches that branch). See §3.4 for the `store()` redesign.

`providers()` does not use the helper — its short-circuit is a bare check at the top:
```php
if (! $business->allow_provider_choice) {
    return response()->json(['providers' => []]);
}
```

### 3.4 `store()` redesign

Current flow (`PublicBookingController.php:198-331`):
1. Resolve business.
2. Honeypot.
3. Resolve service.
4. Compute `startsAt` / `endsAt`.
5. **If `validated['provider_id']` is set**: re-check via `$service->providers()->where('providers.id', ...)->where('providers.business_id', ...)->first()`. Return 409 if not found. Result: `$selectedProvider`.
6. Transaction:
   - Availability re-check.
   - If `$selectedProvider` is null → `assignProvider()`.
   - Create customer + booking.
7. Notifications.

After change:
1-4 unchanged.
5. Replace with: `$selectedProvider = $this->resolveProviderIfChoiceAllowed($business, ...)`.
   - When `allow_provider_choice = false`: returns `null` → transaction runs the auto-assign branch.
   - When `allow_provider_choice = true` and `provider_id` submitted: the helper reads `$business->providers()` (not `$service->providers()`), which is a weaker check than the current `$service->providers()->where(...)`. **We must keep the service-membership check** — otherwise a valid business provider who isn't attached to the service could be assigned.

Refined helper contract:

```php
private function resolveProviderIfChoiceAllowed(
    Business $business,
    Service $service,
    ?int $providerId,
): ?Provider {
    if (! $business->allow_provider_choice || ! $providerId) {
        return null;
    }

    return $service->providers()
        ->where('providers.id', $providerId)
        ->where('providers.business_id', $business->id)
        ->first();
}
```

And `store()` handles the "ID is non-null, setting is on, but not attached to service" case:
```php
$selectedProvider = $this->resolveProviderIfChoiceAllowed(
    $business, $service, $validated['provider_id'] ?? null,
);

if (! empty($validated['provider_id']) && $business->allow_provider_choice && ! $selectedProvider) {
    return response()->json(['message' => __('Selected provider is not available for this service.')], 409);
}
```

That preserves today's 409 for "provider exists in business but not attached to this service" when the setting is on. When the setting is off, the `! empty($validated['provider_id'])` branch short-circuits because `allow_provider_choice` short-circuits the 409 path too — the `provider_id` is silently ignored.

For `availableDates()` / `slots()`, the "provider not attached to service" case matters less — those endpoints today resolve via `$business->providers()->where(...)` (not service-scoped) and hand the provider to `SlotGeneratorService::getSlotsForProvider()`, which will return an empty set if the provider isn't eligible for the service. The behaviour is already tolerant. For simplicity we let them use the same helper (business-scoped resolution) and accept that a `provider_id` for a business provider who isn't attached to the service returns empty availability — same as today.

Actually, to keep the three call sites identical and minimise diff, the helper returns a provider looked up through `$business->providers()` (not service-scoped). `store()` does its existing service-membership re-check as a second guard:

```php
private function resolveProviderIfChoiceAllowed(Business $business, ?int $providerId): ?Provider
{
    if (! $business->allow_provider_choice || ! $providerId) {
        return null;
    }

    return $business->providers()->where('id', $providerId)->firstOrFail();
}
```

Then `store()` looks like:
```php
$selectedProvider = $this->resolveProviderIfChoiceAllowed($business, $validated['provider_id'] ?? null);

if ($selectedProvider) {
    $serviceMember = $service->providers()
        ->where('providers.id', $selectedProvider->id)
        ->where('providers.business_id', $business->id)
        ->exists();

    if (! $serviceMember) {
        return response()->json(['message' => __('Selected provider is not available for this service.')], 409);
    }
}
```

Equivalent semantics, identical helper across all three call sites. This is the design that ships.

### 3.5 Frontend fix

`resources/js/pages/booking/show.tsx:36`:

```tsx
// Before
const [step, setStep] = useState<BookingStep>(preSelectedService ? 'provider' : 'service');

// After
const [step, setStep] = useState<BookingStep>(
    preSelectedService
        ? (business.allow_provider_choice ? 'provider' : 'datetime')
        : 'service',
);
```

One line. All other step transitions already consult `business.allow_provider_choice`:
- `handleServiceSelect` (line 51-55) ✓
- `goBack` (line 94) ✓
- `totalSteps` / `stepOrder` (line 105-108) ✓
- `stepIndex` computation (line 109) — works automatically.

No type changes. No new translation keys. No component restructuring.

---

## 4. Implementation order

Each step leaves the suite green. The suite today is 461 passing.

### Step 1 — D-068 decision file (docs only)

Append D-068 to `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md` using the text in §3.1. No code changes. This is the anchor for the rest of the session.

**Verifies**: `php artisan test --compact` still 461 passing (no code touched).

### Step 2 — `PublicBookingController::providers()` short-circuit

Modify `app/Http/Controllers/Booking/PublicBookingController.php::providers()`:
```php
public function providers(string $slug, Request $request): JsonResponse
{
    $business = $this->resolveBusiness($slug);

    $request->validate([
        'service_id' => ['required', 'integer'],
    ]);

    if (! $business->allow_provider_choice) {
        return response()->json(['providers' => []]);
    }

    // ...existing service lookup + provider fetch unchanged.
}
```

Add test: `returns empty list when allow_provider_choice is false` in `tests/Feature/Booking/ProvidersApiTest.php`.

**Verifies**: new test passes, existing `ProvidersApiTest` tests still pass (they use the default factory, which sets `allow_provider_choice = true` per the factory definition — verify this is the case, see §5.1).

### Step 3 — `PublicBookingController` private helper + `availableDates` / `slots` gate

Add the helper (§3.3 final version).

Update `availableDates()`:
```php
$provider = $this->resolveProviderIfChoiceAllowed(
    $business,
    $request->integer('provider_id') ?: null,
);
```

Update `slots()`: same replacement.

The existing inline `$request->filled('provider_id') ? $business->providers()->...->firstOrFail() : null` blocks are dropped.

Add tests in `tests/Feature/Booking/AvailableDatesApiTest.php` and `SlotsApiTest.php`:
- `ignores provider_id when allow_provider_choice is false` — submit a valid `provider_id` for a business with the setting off, assert the response matches the "any provider" response (i.e., identical to a request without `provider_id`).

**Verifies**: new tests pass; existing tests in both files still pass (they use the default factory, which has the setting on).

### Step 4 — `PublicBookingController::store()` gate

Update the `$selectedProvider` resolution block to use the helper (§3.4 final version). The service-membership check is retained as a second guard. The existing 409 branch is preserved for genuine service-mismatch cases.

Add test: `ignores provider_id when allow_provider_choice is false and auto-assigns` in `tests/Feature/Booking/BookingCreationTest.php`. Assert:
- Status 201.
- Booking is created (count increases).
- `booking.provider_id` equals the factory-provided provider (there's only one in the test's `beforeEach`).
- Optionally: POST a `provider_id` pointing at a *second* provider attached to the same service; assert the booking's `provider_id` is still the first-assigned one (proving the submitted value was ignored, not honoured). This is the sharper assertion.

Two providers is clean: the existing `beforeEach` creates one; the test creates a second, attaches it to the service, and sets `assignment_strategy = 'first_available'` so the auto-assignment is deterministic (picks the first provider by ID). POST `provider_id = second.id`. Assert `booking.provider_id === first.id`.

**Verifies**: new test passes; existing `BookingCreationTest` tests still pass (happy path is unchanged when setting is on).

### Step 5 — React step-init fix

Modify `resources/js/pages/booking/show.tsx:36` per §3.5.

Add Inertia-prop contract test in `tests/Feature/Booking/PublicBookingPageTest.php`:
- `preselected service page exposes allow_provider_choice = false when setting is off` — create a business with the setting off, visit `/{slug}/{service-slug}`, assert the Inertia props contain `business.allow_provider_choice === false` and `preSelectedServiceSlug === 'service-slug'`. This is the backend contract the frontend relies on; the React derivation itself is a trivial ternary that is verified by reading the diff.

**Verifies**: new test passes. Manual-QA checklist (§6) captures the browser behaviour.

### Step 6 — Pint, test run, build

- `vendor/bin/pint --dirty --format agent`
- `php artisan test --compact` — expected 461 + 6 = 467 passing (see §5.4).
- `npm run build` — expected clean build, no new TypeScript errors.

### Step 7 — HANDOFF + roadmap update

- Overwrite `docs/HANDOFF.md` with the new state (R-7 complete, next session candidate is R-8 or R-9 or R-10, decided by the developer).
- Check `docs/reviews/ROADMAP-REVIEW.md` checkbox for R-7 if that file uses one. (Currently the file has plain prose sections, no checkboxes — leave as-is.)
- Move `docs/plans/PLAN-R-7-PROVIDER-CHOICE.md` to `docs/archive/plans/`.

---

## 5. Testing plan

### 5.1 Existing coverage audit (happy path is solid)

Before adding tests, confirm the factory default:

- `database/factories/BusinessFactory.php` — check the default value for `allow_provider_choice`. If it's `true`, every existing test implicitly exercises the "setting on" branch, which is unchanged behaviour after the fix. If it's `false` or null, we have a problem and need to audit what each test expects. **Verification step before coding**: `php artisan tinker --execute 'echo Business::factory()->raw()["allow_provider_choice"] ? "true" : "false";'` or grep the factory. If `false`, either flip the default in the factory (affects many tests, risky) or add `'allow_provider_choice' => true` to the `beforeEach` of the four files that exercise provider-choice-dependent behaviour. The plan assumes the factory default is `true`; adjust step 1 if not.

Happy-path files that DO NOT need changes:
- `tests/Feature/Booking/PublicBookingPageTest.php` — does not test `allow_provider_choice`; unaffected.
- `tests/Feature/Booking/ProvidersApiTest.php` — seven tests, all default factory (setting on assumed); adding a new test at the end.
- `tests/Feature/Booking/AvailableDatesApiTest.php` — four tests, same.
- `tests/Feature/Booking/SlotsApiTest.php` — four tests, same.
- `tests/Feature/Booking/BookingCreationTest.php` — thirteen tests, same.
- `tests/Feature/Booking/BookingOverlapConstraintTest.php` — unaffected.
- `tests/Feature/Booking/BookingRaceSimulationTest.php` — unaffected.
- `tests/Feature/Booking/BookingBufferGuardTest.php` — unaffected.
- `tests/Feature/Booking/BookingManagementTest.php` — unaffected (management page, not flow).
- `tests/Feature/Settings/BookingSettingsTest.php` — tests the setter, not the downstream. Unaffected by R-7.

**Verdict**: existing coverage is sufficient for the happy path. New tests only need to exercise the "setting = off" matrix.

### 5.2 New tests (6)

1. **`ProvidersApiTest.php` — returns empty list when allow_provider_choice is false.**
   Factory-create a business with `allow_provider_choice => false`. Attach one provider to one service. GET `/booking/{slug}/providers?service_id={id}`. Assert 200, `providers` key exists and is `[]`.

2. **`AvailableDatesApiTest.php` — ignores provider_id when allow_provider_choice is false.**
   Two providers attached to the service; provider A has Monday availability, provider B has none. Set `allow_provider_choice => false`. GET with `provider_id = B.id`. Assert the response treats this as "any provider" — Monday dates return `true` because provider A is available. Compare against a control GET without `provider_id` — same response.

3. **`SlotsApiTest.php` — ignores provider_id when allow_provider_choice is false.**
   Same shape as #2 but for `slots()`. Request with `provider_id = B.id` for a Monday; assert slots include `09:00` / `17:00` etc. because provider A covers them. Compare against a control request without `provider_id`.

4. **`BookingCreationTest.php` — ignores provider_id when allow_provider_choice is false and auto-assigns.**
   Second provider (B) added in-test, attached to the service, with no Monday availability rule (so they'd fail availability anyway); first provider (A, from `beforeEach`) is available. Set business `allow_provider_choice => false` and `assignment_strategy = 'first_available'`. POST `validBookingData(['provider_id' => B.id])`. Assert 201 and `booking.provider_id === A.id`.
   Sharper variant: give both providers availability but rely on `first_available` picking the lower-ID provider (A) deterministically. Either shape is acceptable; the key assertion is "submitted ID is ignored".

5. **`BookingCreationTest.php` — honours provider_id when allow_provider_choice is true.**
   Control test proving the happy path still works end-to-end after the helper refactor. Second provider B attached to the service with full Monday availability. Business has `allow_provider_choice => true` (default). POST `validBookingData(['provider_id' => B.id])`. Assert `booking.provider_id === B.id`. (This is a regression pin for the helper change — if someone ever breaks `resolveProviderIfChoiceAllowed`, this test fires.)

6. **`PublicBookingPageTest.php` — preselected service page exposes allow_provider_choice prop.**
   Business with `allow_provider_choice => false`, one staffed service with slug `haircut`. GET `/{slug}/haircut`. Assert Inertia props: `business.allow_provider_choice === false`, `preSelectedServiceSlug === 'haircut'`. This locks the contract the React step-init depends on.

### 5.3 What's NOT tested and why

- **The React step-init itself** — `booking/show.tsx:36`. No browser-test infrastructure exists in the project (`Pest\Browser` / `visit()` — grep returns zero hits). The derivation is a trivial ternary on a prop the backend contract test (test #6) guarantees. A reviewer reads the one-line diff and verifies it. Manual-QA covers the browser verification.
- **Notifications / emails** for ignored-`provider_id` bookings — the existing `confirmation notification is sent` test in `BookingCreationTest` covers the end-to-end notification path; it is unchanged by R-7.
- **Manual booking's handling of `provider_id`** — explicitly out of scope per §2. No new test.
- **Settings getter/setter** for `allow_provider_choice` — already tested in `BookingSettingsTest.php` with `true`/`false` values; unchanged.

### 5.4 Count

- Existing: 461 passing.
- +1 `providers` empty.
- +1 `availableDates` ignore.
- +1 `slots` ignore.
- +1 `store` ignore + auto-assign.
- +1 `store` still honours when setting on.
- +1 Inertia prop contract.
- **Expected total: 467 passing, 1820+ assertions.**

---

## 6. Verification

### Automated

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
npm run build
```

Expected:
- Pint: clean.
- Tests: 467 passed (or whatever baseline + 6).
- Build: clean, no new TS errors, no new warnings.

### Manual QA (browser)

1. As admin, visit `/dashboard/settings/booking`, set **Allow customer to choose collaborator = OFF**, save.
2. In a fresh incognito window, visit `/{slug}/{service-slug}` (preselected-service URL). **Expected**: flow opens on the date-time picker, not the provider picker. Step indicator shows "1 of 4" (or "Date & time" as the first step).
3. Complete a booking through the flow. **Expected**: booking is created, customer gets confirmation, provider is auto-assigned (`first_available`).
4. Open DevTools Network tab. Observe GET `/booking/{slug}/providers?service_id=...` is NOT fired (the React step-init skipped the provider step). Manually hit the URL in a new tab. **Expected**: `{ providers: [] }`.
5. Manually POST to `/booking/{slug}/book` via DevTools console or curl with `provider_id` set to a valid provider's ID (one of the business's bookable providers). **Expected**: booking is created, but `booking.provider_id` is the auto-assigned one (may match by coincidence if only one provider exists; test with two providers to observe the ignore).
6. Toggle the setting back ON. Repeat step 2. **Expected**: flow opens on the provider picker. Happy path still works.
7. Regression check for R-5/R-6 (cross-verify): soft-delete one provider with history; visit `/my-bookings` and `/bookings/{token}`. Times still render in business timezone; deactivated marker still shows. Not R-7's domain but cheap to verify.

### What to watch for

- **Rate limiter warmth.** The public booking endpoints are behind `throttle:booking-api` / `throttle:booking-create`. Test runs create fresh limiter buckets; manual QA on a busy dev DB might hit throttle — reset via tinker if needed.
- **Factory default drift.** If the `Business` factory changes `allow_provider_choice`'s default before this session runs, §5.1's audit may need re-running.
- **Inertia prop caching.** `HandleInertiaRequests` caches shared props; `allow_provider_choice` is a page-level prop on `booking/show`, not shared — no interaction.

---

## 7. Files to create / modify / delete

### Created

- `docs/plans/PLAN-R-7-PROVIDER-CHOICE.md` — this file (moved to `docs/archive/plans/` on session completion).

### Modified

- `app/Http/Controllers/Booking/PublicBookingController.php`
  - Add `resolveProviderIfChoiceAllowed(Business, ?int): ?Provider` private helper.
  - `providers()` — short-circuit when setting is off.
  - `availableDates()` — replace inline provider lookup with helper call.
  - `slots()` — same.
  - `store()` — replace inline provider lookup with helper call; keep service-membership re-check.
- `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md` — append D-068.
- `resources/js/pages/booking/show.tsx` — fix `useState` initial step derivation.
- `tests/Feature/Booking/ProvidersApiTest.php` — +1 test.
- `tests/Feature/Booking/AvailableDatesApiTest.php` — +1 test.
- `tests/Feature/Booking/SlotsApiTest.php` — +1 test.
- `tests/Feature/Booking/BookingCreationTest.php` — +2 tests.
- `tests/Feature/Booking/PublicBookingPageTest.php` — +1 test.
- `docs/HANDOFF.md` — overwrite with R-7 summary (post-implementation).

### Deleted

None.

### Renamed

None.

---

## 8. Risks and mitigations

### 8.1 Race: admin toggles off mid-session

**Scenario**: customer is on the provider step with a selection in state; admin toggles `allow_provider_choice = false` in another tab. Customer clicks "next" → `store()` receives `provider_id` submitted against a setting that's now off.

**Behaviour under this plan**: `store()` silently ignores the `provider_id` and auto-assigns. The customer proceeds with a booking whose provider might differ from what they picked.

**Mitigation**: accept this as graceful degradation. The alternative (422) would hard-error the customer for an event they had no visibility into. Admins toggling a policy mid-flow is the ultra-rare edge case. If this ever becomes a real complaint, the server can extend the 409 "provider not available" response text to include a policy-explained hint — out of scope here.

### 8.2 Honest-client fails to hide the provider step after init

**Scenario**: the step-init fix is correct, but some future code (deep link, URL param, custom deployment) routes the customer back into the provider step. The step is now "reachable in a state that's forbidden".

**Mitigation**: the server is the binding layer. Even if the React flow glitches back to the provider step, the `providers()` endpoint returns `[]` — the picker renders empty, and if the customer forces a click, the `store()` gate fires. No data-model damage.

### 8.3 The helper's `firstOrFail()` exception path

**Scenario**: `resolveProviderIfChoiceAllowed` uses `firstOrFail()`. If `provider_id` is submitted but doesn't match a business provider (crafted request with an arbitrary ID), `firstOrFail()` throws `ModelNotFoundException` → default Laravel 404 response.

**Evaluation**: `availableDates()` / `slots()` today already do this (`$business->providers()->where('id', ...)->firstOrFail()`). Behaviour is unchanged. `store()` previously rejected cross-business IDs in the Form Request (422) before reaching the controller; after the change, the Form Request still runs first. So `firstOrFail()` inside the helper is only reached for an ID that *did* pass the Form Request validation — i.e., a real business provider. The exception path is unreachable on the `store()` route.

**Mitigation**: leave as-is; the control flow is correct.

### 8.4 Inertia prop contract test is brittle

**Scenario**: the prop name changes (unlikely) or the `show()` controller restructures. Test 6 starts failing for reasons unrelated to R-7.

**Mitigation**: the prop name is part of the public contract between backend and React — changing it is a deliberate decision with wider implications. If it changes, the test will catch the change and the renamer will update the test. That is the test's job.

### 8.5 D-068 sets a precedent

**Scenario**: future "business setting needs server enforcement" findings adopt D-068's pattern (gate in controller, silent ignore, empty-list for GET) even when a 422 or 403 would be more appropriate.

**Mitigation**: the decision's rationale is explicit — "silent ignore" is chosen because (a) the honest client doesn't hit this, (b) the crafted client gets the same outcome as the honest one, (c) the race case degrades gracefully. Future applications should re-evaluate these three conditions, not copy-paste the verdict. The decision text in §3.1 is phrased to invite that re-evaluation.

### 8.6 Happy-path regression in `store()`

**Scenario**: the `store()` refactor accidentally changes the behaviour of the `provider_id` path when the setting is on. Existing tests pass (they don't exercise two providers and assertion on which one is picked), but real traffic sees providers get mis-assigned.

**Mitigation**: test #5 (`honours provider_id when allow_provider_choice is true`) is a targeted regression pin. It creates two providers, submits a specific `provider_id`, and asserts the booking lands on exactly that provider. Any future break in the helper fires this test.

### 8.7 Out-of-scope bleed (the broader "settings not enforced" pattern)

**Scenario**: a reader of this plan concludes "R-7 should also audit the other settings listed in §1.4". The audit is already done (§1.4 shows the other settings are enforced). If a new gap surfaces during implementation, the planner must **not** expand scope — file it as a follow-up and stay inside R-7.

**Mitigation**: §1.4 is explicit. Any new gap goes into `docs/HANDOFF.md`'s "Open Questions / Deferred Items" at session close, not into this session's commits.

---

## 9. What happens after R-7

The roadmap lists R-8 (calendar hydration + mobile view switcher) and R-9 (popup embed service prefilter + modal robustness) next. Both are Medium priority and touch entirely different files from R-7. The developer picks one per session based on priority; this plan does not prescribe the order.

If R-8 is next:
- Files: `resources/js/components/calendar/week-view.tsx`, `current-time-indicator.tsx`, `calendar-header.tsx`, `day-view.tsx`, `month-view.tsx`, `calendar-event.tsx`.
- Priority: fixing the confirmed hydration warning first; mobile view switcher second.

If R-9 is next:
- Files: `public/embed.js`, `resources/js/pages/dashboard/settings/embed.tsx`.
- Priority: canonical service-prefilter contract (decision needed) → per-service popup snippet → focus trap / scroll lock / duplicate-overlay guard.

Neither R-8 nor R-9 depends on R-7.
