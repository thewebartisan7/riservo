---
name: PLAN-R-5-R-6-PROVIDER-LIFECYCLE-AND-CUSTOMER-TZ
description: "R-5 + R-6: provider lifecycle coherence + customer-facing timezone"
type: plan
status: shipped
created: 2026-04-16
updated: 2026-04-16
---

# PLAN-R-5-R-6 — Provider lifecycle coherence + customer-facing timezone

**Session**: R-5 (provider lifecycle in booking flows) + R-6 (customer-facing timezone rendering)
**Source**: `docs/reviews/ROADMAP-REVIEW.md` §R-5 and §R-6, `docs/reviews/REVIEW-1.md` issues #4 and #5
**Status**: proposed (plan-only)
**Date**: 2026-04-16
**Depends on**: R-1B (Provider as first-class, SoftDeletes — D-061), R-4B (booking overlap constraint — D-066)

---

## 1. Context

### 1.1 Why R-5 and R-6 are bundled

The R-5 investigation (see §1.2) confirms that the original R-5 concern — "deactivated providers still appear in NEW booking flows" — is already fully fixed by D-061's shift to `SoftDeletes` on `Provider`. The default global scope excludes soft-deleted providers from every read path in the slot engine, public providers list, availability endpoints, store flows, and auto-assignment. No bypass (`withTrashed`, `withoutGlobalScopes`) exists in any booking read path.

However, R-5's investigation also surfaced an **adjacent** gap that is not about NEW bookings but about **DISPLAY** of HISTORICAL bookings: four display controllers dereference `$booking->provider->...` with no null guard, and `belongsTo(Provider::class)` returns `null` for a booking whose provider has since been soft-deleted. This is a latent 500 the moment an admin deactivates a provider with history.

R-6 is a pure display-layer correctness fix: customer-facing booking views render UTC strings with `new Date(...).toLocale*()` and no `timeZone` option, so a customer in Asia/Tokyo viewing a Europe/Zurich appointment sees Tokyo-local time instead of Zurich-local time (violates D-005 and SPEC §14).

**R-5 gap and R-6 touch exactly the same two customer-facing controllers and pages**:

| Controller / page | R-5 gap | R-6 gap |
| --- | --- | --- |
| `app/Http/Controllers/Booking/BookingManagementController.php::show` | `$booking->provider->user?->name` can hit null | Already sends `business.timezone` (frontend ignores it) |
| `app/Http/Controllers/Customer/BookingController.php::index` | `$booking->provider->user?->name` in `formatBooking()` can hit null | Never sends `business.timezone` |
| `resources/js/pages/bookings/show.tsx` | Accesses `booking.provider.name` | Uses `toLocaleDateString/TimeString` w/o timezone |
| `resources/js/pages/customer/bookings.tsx` | Accesses `booking.provider.name` | Uses `toLocaleDateString/TimeString` w/o timezone |

Editing the same four files twice in back-to-back sessions is churn. Bundling them gives one coherent "customer-facing display correctness" increment. R-7 (provider-choice enforcement) is intentionally deferred because it touches different files (`PublicBookingController::store`, `resources/js/pages/booking/show.tsx` — the multi-step flow, not the booking management view) and a different concern (policy enforcement on POST, React step-init), so bundling it in here would make the diff less reviewable.

This is scope **(B)** from the session brief: R-5 is mostly fixed, with one adjacent gap worth a targeted fix plus regression tests; bundled with R-6.

### 1.2 R-5 investigation findings

Each numbered item below is a read path that could in principle leak a deactivated provider into a booking flow. Every item was inspected in the current code (post-R-1B, post-R-4B). `Provider` uses `SoftDeletes` (`app/Models/Provider.php:19`); the FK `bookings.provider_id` is `NOT NULL` with `restrictOnDelete` (`2026_04_16_000006_repoint_bookings_collaborator_id_to_provider_id.php:42-43`), so hard-deletion of providers with bookings is blocked; soft-deletion preserves the row and merely adds a `deleted_at` timestamp.

1. **Slot engine — `SlotGeneratorService::getEligibleProviders`** (`app/Services/SlotGeneratorService.php:226-233`): `$service->providers()->where('providers.business_id', ...)->get()`. `$service->providers()` is `belongsToMany(Provider::class, 'provider_service')` (`app/Models/Service.php:44-49`); the default `SoftDeletingScope` applies to the target query. **Clean.**

2. **Slot engine — `SlotGeneratorService::leastBusyProvider`** (`SlotGeneratorService.php:240-249`): receives candidates from `getEligibleProviders`. No direct provider query; orders by a `Booking` count query keyed on `provider_id`. **Inherits.**

3. **Slot engine — `SlotGeneratorService::assignProvider`** (`SlotGeneratorService.php:42-70`): goes through `getEligibleProviders`. **Inherits.**

4. **Slot engine — `SlotGeneratorService::getSlotsForAnyProvider`** (`SlotGeneratorService.php:101-127`): iterates `getEligibleProviders`. **Inherits.**

5. **Slot engine — `SlotGeneratorService::getBlockingBookings`** (`SlotGeneratorService.php:206-221`): `Booking::where('provider_id', $provider->id)->...`. Queries `Booking` by the raw `provider_id` column, not via the `Provider` model — no provider scope applies. This is correct: a booking whose provider is soft-deleted still blocks overlapping slots for the same provider (the GIST constraint from D-066 works the same way). **Correct by design.**

6. **`AvailabilityService::getAvailableWindows`** (`app/Services/AvailabilityService.php:23-51`): receives a `Provider` instance from callers. Reads `AvailabilityRule` / `AvailabilityException` by raw `provider_id`. No separate provider lookup here. **Inherits from callers.**

7. **Public booking — `PublicBookingController::show`** (`app/Http/Controllers/Booking/PublicBookingController.php:37-91`): lists services with `->whereHas('providers')`. `whereHas` on the `providers` belongsToMany applies `SoftDeletingScope`. Already covered by test `service disappears when its last provider is soft-deleted` (`tests/Feature/Booking/PublicBookingPageTest.php:85-101`). **Clean, tested.**

8. **Public booking — `PublicBookingController::providers`** (`PublicBookingController.php:93-119`): `$service->providers()->where('providers.business_id', ...)->with('user:...')->get()`. **Clean.** Not tested yet.

9. **Public booking — `PublicBookingController::availableDates` / `slots`** (`PublicBookingController.php:121-196`): `$business->providers()->where('id', ...)->firstOrFail()`. `$business->providers()` is `hasMany(Provider::class)` (`app/Models/Business.php:79-82`); `SoftDeletingScope` applies to the target query; a soft-deleted `provider_id` → `firstOrFail()` → 404. **Clean.** Not tested yet.

10. **Public booking — `PublicBookingController::store`** (`PublicBookingController.php:198-331`): two checks: (a) Form Request `StorePublicBookingRequest` validates via inline closure `Provider::where('id', $value)->where('business_id', $businessId)->exists()` — `Provider::where` enters through `SoftDeletingScope`, so soft-deleted providers fail validation (`app/Http/Requests/Booking/StorePublicBookingRequest.php:28-50`); (b) controller then re-looks up via `$service->providers()->where('providers.id', ...)->first()` — same scope, same rejection. **Clean.** Not tested yet.

11. **Manual booking — `Dashboard\BookingController::store`** (`app/Http/Controllers/Dashboard/BookingController.php:229-339`): Form Request `StoreManualBookingRequest` uses `new BelongsToCurrentBusiness(Provider::class)` (`app/Http/Requests/Dashboard/StoreManualBookingRequest.php:26`). The rule does `$this->modelClass::query()->where(...)->exists()` — for `Provider::class` this enters `SoftDeletingScope` and rejects soft-deleted providers. Covered by `soft-deleted in-tenant provider fails by default` in `tests/Feature/Rules/BelongsToCurrentBusinessTest.php:44-57`. **Clean, unit-tested at the rule level.** Not tested at the controller integration level.

12. **Manual booking — provider dropdown** (`Dashboard\BookingController::index:98-103`): `$business->providers()->with('user:...')->get()`. **Clean.**

13. **Calendar provider legend** (`Dashboard\CalendarController::index:60-66`): `$business->providers()->with('user:...')`. **Clean.**

14. **Form Request rule — `BelongsToCurrentBusiness`** (`app/Rules/BelongsToCurrentBusiness.php:52-55`): `$this->modelClass::query()->where(...)->exists()`. Uses `Provider::query()`, which enters `SoftDeletingScope`. **Clean, tested.**

15. **Grep for `withTrashed` / `withoutGlobalScope` / `onlyTrashed` in `app/`**: only appears in `OnboardingController`, `Dashboard\Settings\AccountController`, `Dashboard\Settings\StaffController`. All three are provider-lifecycle surfaces (toggle on/off, staff detail page listing both active and trashed providers). **None is a booking read path.**

**Verdict on original R-5 scope**: fully fixed by D-061. The risk that motivated R-5 (new booking flows accidentally using a deactivated provider) is structurally closed at the query-engine layer.

### 1.3 R-5 adjacent gap — historical bookings crash display controllers

Four display controllers dereference `$booking->provider->...` without a null guard. `Booking::provider()` is a plain `belongsTo(Provider::class)` (`app/Models/Booking.php:62-66`), so eager-loading `->with('provider.user:...')` on a booking whose provider row has `deleted_at IS NOT NULL` returns a null relation (SoftDeletingScope filters the `whereIn` on the `providers` table). The subsequent property access raises `Attempt to read property "id" on null` (or `"user"` on null).

Trigger scenario: an admin deactivates a provider who has existing (past or future) bookings — the normal case, because the provider they want to deactivate is typically the one with history. After the deactivation, any request that renders one of those bookings 500s.

Affected lines, ordered by severity:

- **`app/Http/Controllers/Dashboard/CalendarController.php:72-102`** — the calendar map callback builds a `provider` payload with `$booking->provider->id`, `$booking->provider->user?->name`, and `$booking->provider->user?->avatar`. The first dereference crashes.
- **`app/Http/Controllers/Dashboard/BookingController.php:106-135`** — the bookings list map callback builds the same shape via `$booking->provider->id` → crash.
- **`app/Http/Controllers/Booking/BookingManagementController.php:23-48`** — the customer-facing confirmation page builds `'provider' => ['name' => $booking->provider->user?->name ?? '']`. The intermediate `$booking->provider->user` crashes when `$booking->provider` is null.
- **`app/Http/Controllers/Customer/BookingController.php:77-101`** — `formatBooking()` does the same `$booking->provider->user?->name ?? ''`, same crash.

`Dashboard\BookingController::updateStatus` / `updateNotes` (`BookingController.php:164-227`) already use null-safe `$booking->provider?->user_id` and are fine. Notification helpers (`notifyStaff` on both controllers) use null-safe `$booking->provider?->user` — they silently drop the notification to the deactivated provider's user, which is a separate semantic question (see §8 Risks).

The seeder (`database/seeders/BusinessSeeder.php`) never creates a soft-deleted provider, so the existing test suite does not reproduce this failure mode. The 452-test green status is not a signal that this code path is safe — just that no test drives it.

### 1.4 R-6 — customer-facing timezone rendering

- **`app/Http/Controllers/Booking/BookingManagementController.php:40-41`** already sends `business.timezone`. **`resources/js/pages/bookings/show.tsx:41-48`** formats `date.toLocaleDateString()` / `date.toLocaleTimeString(...)` with no `timeZone` option — the prop is passed but unused.
- **`app/Http/Controllers/Customer/BookingController.php:77-101`** (`formatBooking`) builds `'business' => ['name' => $booking->business->name]` — no `timezone` field. **`resources/js/pages/customer/bookings.tsx:12-26`** (`BookingItem`) formats the same way. Even if the frontend wanted the timezone, the backend is not sending it.
- A shared util already exists: **`resources/js/lib/datetime-format.ts`** exposes `formatDateTimeShort`, `formatDateTimeMedium`, `formatDateTimeLong`, `formatTimeShort`, `formatRelativeDay`, all of which accept an optional `timezone: string | undefined` and forward it as `Intl.DateTimeFormatOptions.timeZone`. The dashboard pages use these correctly: `resources/js/pages/dashboard/bookings.tsx:304` (`formatDateTimeShort(booking.starts_at, timezone)`).
- The TypeScript contracts in `resources/js/types/index.d.ts` already have `BookingDetail.business.timezone: string` (line 23) but `BookingSummary.business` is `{ name: string }` only (line 36) — the customer list has no timezone field in the type.

**Verdict**: the util and the server-side pattern are already in place; only the two customer-facing pages and the `Customer\BookingController::index` response are missing the plumbing.

---

## 2. Goal and scope

### Goal

1. **R-5 regression**: pin down the D-061 invariant ("default reads never return soft-deleted providers") with controller-level and service-level regression tests so future refactors cannot re-open the original R-5 gap.
2. **R-5 adjacent gap**: make display of historical bookings with soft-deleted providers a first-class, non-crashing case. A booking's provider is a historical record — soft-deletion of the provider must never break the rendering of bookings that already reference them.
3. **R-6**: comply with D-005 and SPEC §14 on the two customer-facing booking views (`/bookings/{token}` and `/my-bookings`). Customers always see times in the business's local timezone.

### In scope

- A new architectural decision **D-067** recording that `Booking::provider()` is authoritative on the historical provider and therefore includes soft-deleted rows by default.
- Change the `Booking::provider()` relation on the Eloquent model so that eager loads and lazy access both resolve to the provider row regardless of its `deleted_at`. Add a `display_name` / `is_active` contract on the provider payload in every booking-display controller so the UI can render a "(deactivated)" marker without plumbing bespoke fields case-by-case.
- Update the four affected display controllers to emit `provider.is_active: boolean` (derived from `!$booking->provider->trashed()`) and to keep rendering the provider name even when trashed.
- Update the React pages to render a visual "(deactivated)" suffix when `is_active === false` on any booking's provider.
- Pass `business.timezone` from `Customer\BookingController::index` and use it in `resources/js/pages/customer/bookings.tsx` via the shared `datetime-format` helpers.
- Use the shared `datetime-format` helpers in `resources/js/pages/bookings/show.tsx` and consume the already-sent `booking.business.timezone`.
- Extend the `BookingSummary` TypeScript interface with `business.timezone: string` so the customer list has a typed contract for timezone.
- Regression and new tests (see §5).

### Out of scope

- R-7 (`allow_provider_choice` server-side enforcement) — deferred to its own session.
- Any change to the slot engine, availability engine, auto-assignment, or the overlap constraint. R-5 original scope is already fixed; we do not re-open the engine.
- Changes to the Dashboard calendar / bookings list **UI** beyond what is needed to render a "(deactivated)" marker on the provider cell. This is not a UX overhaul.
- Changes to the provider toggle UI, staff management, onboarding, account settings. They already handle `withTrashed` correctly.
- Changes to notifications. If a booking is cancelled for a deactivated provider, the trashed provider's user is currently **not** notified because the `?->` guards on `notifyStaff` silently skip. That is arguably wrong (see §8), but fixing it changes notification behaviour for a narrow edge case and is not required for R-5's correctness goal. Recorded as a deferred follow-up.
- Changes to the business-timezone label rendering in `resources/js/layouts/booking-layout.tsx`. Already correct.
- Cross-timezone appointment UX. SPEC §14 scopes MVP to "customer sees business local time" — this plan does not introduce customer-local or dual-clock rendering.
- Changes to `Dashboard\BookingController::index` / `Dashboard\CalendarController::index` that touch timezone. They already use `formatDateTimeShort` with the business timezone.

---

## 3. Approach

### 3.1 D-067 — Booking::provider() includes trashed by default

**The claim.** A booking row carries `provider_id` as a historical, immutable fact: this is the provider who performed (or is about to perform) the service. Soft-deletion of the provider is a statement about the provider's future bookability, not about the past. Therefore, `Booking::provider()` should resolve the provider row irrespective of `deleted_at`.

**Proposed model change** in `app/Models/Booking.php`:

```php
/** @return BelongsTo<Provider, $this> */
public function provider(): BelongsTo
{
    // A booking's provider is a historical fact (FK is NOT NULL, rows are
    // only soft-deleted). Resolve the row regardless of deleted_at so
    // display/notification sites never crash on $booking->provider->…
    // Eligibility checks for NEW work still go through Provider::query()
    // / $service->providers() / $business->providers(), which apply the
    // default SoftDeletingScope and continue to exclude trashed rows.
    return $this->belongsTo(Provider::class)->withTrashed();
}
```

**Why not the alternatives** (considered and rejected):

- **Alternative A — add a second relation `providerIncludingTrashed()` on Booking and use it at each display site.** More explicit at each call site, but verbose and easy to forget. A developer writing a new dashboard page will reach for `$booking->provider` and (re-)introduce the same latent 500 if they forget to use the other relation. Rejected.
- **Alternative B — leave the relation alone and add `->withTrashed()` at each eager-load site.** Same drift risk as A; also uglier to compose with nested `provider.user:...` selects. Rejected.
- **Alternative C — mark the booking's `provider_id` nullable and set it to `NULL` on provider deletion.** Changes the schema contract and breaks the R-4B GIST constraint (which requires a `provider_id` for partitioning). Rejected.

**Why Option 1 is safe.** The override is scoped to this one relation; the SoftDeletingScope continues to govern every other provider-lookup path:

- `Provider::query()` / `Provider::where(...)` — scope applies (used by `BelongsToCurrentBusiness`, and by `StorePublicBookingRequest`'s inline closure).
- `$service->providers()` — scope applies on the target side of the many-to-many (used by slot engine and the providers endpoint).
- `$business->providers()` — scope applies on the target side of the hasMany (used by manual booking dropdown, calendar legend, availability endpoints).
- `$provider->availabilityRules()`, `$provider->services()`, etc. — unchanged.

The override only affects code that walks from a `Booking` to its provider — which is precisely the set of sites where "who was this booking for" is the right question.

**Behavioural consequences.** Every controller that eager-loads `'provider'` or `'provider.user'` now receives a non-null provider object for every booking. The `->trashed()` / `!->trashed()` state becomes a piece of UX data, not a reason to crash.

**Ripple check** — grep for `$booking->provider` in `app/`:
- display sites (calendar, bookings list, booking management, customer bookings) — now non-null → correct.
- `notifyStaff` on both booking controllers uses null-safe `?->user` to merge the provider's user into the admin notification list. Post-change, `$booking->provider?->user` becomes `$booking->provider->user` (provider is guaranteed non-null). Effect: the deactivated provider's user now receives booking notifications for their own historical bookings. This is arguably the correct semantic — a deactivated staff member still wants to know their Wednesday 10:00 customer cancelled. If the product disagrees, suppression can be added in a later session; for MVP we accept the change. Explicitly recorded in the decision.
- `$booking->provider?->user_id !== $user->id` in `Dashboard\BookingController::updateStatus` / `updateNotes` — post-change this is `$booking->provider->user_id !== $user->id`, i.e., a staff user whose provider row is trashed can still update their own historical bookings. Matches the "historical ownership survives deactivation" intent.

### 3.2 Booking-payload contract

Every controller method that emits a booking-shaped Inertia payload or JSON adds a consistent provider shape:

```php
'provider' => [
    'id' => $booking->provider->id,
    'name' => $booking->provider->user?->name ?? '',
    'avatar_url' => $booking->provider->user?->avatar ? asset('storage/'.$booking->provider->user->avatar) : null,
    'is_active' => ! $booking->provider->trashed(),
],
```

The `avatar_url` field already exists on Dashboard controllers; Booking/Customer controllers keep their reduced shape (`{ name, is_active }`) since their views do not render avatars today.

### 3.3 Frontend — "(deactivated)" marker

The two customer pages and the two dashboard pages render `provider.name` as a string today. The marker is a suffix rendered inline via the shared i18n helper:

```tsx
{provider.is_active ? provider.name : t(':name (deactivated)', { name: provider.name })}
```

No new component. The marker is the only visual change. The dashboard already renders small helper text next to provider cells; adding the marker there is a two-line change per page.

### 3.4 R-6 — timezone plumbing

**`Customer\BookingController::index`** — `formatBooking()` adds:

```php
'business' => [
    'name' => $booking->business->name,
    'timezone' => $booking->business->timezone,
],
```

**`resources/js/types/index.d.ts`** — `BookingSummary.business` expands to `{ name: string; timezone: string }`.

**`resources/js/pages/customer/bookings.tsx`** — replace raw `date.toLocale*` with the shared helpers:

```tsx
import { formatDateMedium, formatTimeShort } from '@/lib/datetime-format';
// ...
{formatDateMedium(booking.starts_at, booking.business.timezone)} &middot;{' '}
{formatTimeShort(booking.starts_at, booking.business.timezone)} -{' '}
{formatTimeShort(booking.ends_at, booking.business.timezone)}
```

(Note: `formatDateMedium` in the current util does not yet take a `timezone` argument — see §5 Step 5 for the small util extension.)

**`resources/js/pages/bookings/show.tsx`** — same pattern. The backend already sends `booking.business.timezone`.

### 3.5 Why bundling makes the diff smaller, not larger

The four files listed in §1.1 all get touched once — each receives both the provider-lifecycle fix and the timezone fix in a single edit. Splitting into two sessions would touch each of them twice, and the second edit would have to rebase across the first.

---

## 4. Decision: D-067

Append to `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md`:

```md
### D-067 — Booking::provider() resolves the historical provider, including trashed
- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: Post-R-1B (D-061) Provider uses SoftDeletes. The `bookings.provider_id` FK is NOT NULL with `restrictOnDelete`, so provider rows are never hard-deleted when bookings exist — deactivation sets `deleted_at`. Eloquent's default SoftDeletingScope then filters the trashed row out of eager-loaded relationships, so `$booking->provider` returns null for any booking whose provider has been deactivated. Four display controllers (dashboard calendar, dashboard bookings list, customer booking management page, customer bookings list) dereference `$booking->provider->...` and thus 500 the moment an admin deactivates a provider who has booking history — the normal case.
- **Decision**: `Booking::provider()` is defined as `belongsTo(Provider::class)->withTrashed()`. A booking's provider is a historical, immutable fact ("who performed this service"), not a real-time eligibility query. The SoftDeletingScope continues to govern every other provider-lookup path (`Provider::query()`, `$service->providers()`, `$business->providers()`, `BelongsToCurrentBusiness(Provider::class)`), which is what controls eligibility for NEW work. Every booking-display payload adds `provider.is_active: boolean` (= `!trashed()`) so the UI can render a "(deactivated)" marker.
- **Consequences**:
  - Display sites never crash on historical bookings; no `withTrashed()` sprinkled at each eager-load site.
  - Eligibility for new bookings / slot generation / auto-assignment is unchanged — those still flow through the SoftDelete-scoped provider queries.
  - Staff users tied to a trashed provider regain access to their own historical bookings' status/notes updates in `Dashboard\BookingController::updateStatus` / `updateNotes`. This matches "historical ownership survives deactivation."
  - `notifyStaff` paths continue to include the deactivated provider's user in booking notifications (they remain a user in the business membership; the deactivation is only about provider-side bookability). If the product later decides deactivated providers should not receive notifications, that is a separate, narrower decision; for MVP we accept the current behaviour.
  - New display sites (exports, CSV, future reporting) inherit the safe default automatically.
- **Supersedes**: none.
```

No other decision file needs to change. D-005 (business timezone as single source of truth) already covers R-6; R-6 is compliance, not a new decision.

---

## 5. Step-by-step implementation order

Each step leaves `php artisan test --compact` green. Steps are ordered so that later steps don't depend on re-touching earlier ones.

### Step 1 — Override `Booking::provider()` (model-level fix)

- Edit `app/Models/Booking.php`: change `provider(): BelongsTo` to `return $this->belongsTo(Provider::class)->withTrashed();`. Keep the PHPDoc type unchanged. Add a short inline comment anchoring the decision to D-067.
- Run the full suite — existing tests must stay green. Nothing should now crash that crashed before; several previously silent behaviours may now surface (e.g., if any test inadvertently depended on `$booking->provider === null` for a trashed provider, it would fail; none is expected).

### Step 2 — Display controllers: emit `provider.is_active`, keep rendering the name

Update the four affected controllers' provider payloads to include `is_active`:

- `app/Http/Controllers/Dashboard/CalendarController.php::index` — provider block inside the bookings map (line 89-95) and not the separate `providers` legend array.
- `app/Http/Controllers/Dashboard/BookingController.php::index` — provider block inside the bookings paginator map (line 122-128).
- `app/Http/Controllers/Booking/BookingManagementController.php::show` — `'provider' => [...]` block (line 35-37).
- `app/Http/Controllers/Customer/BookingController.php::formatBooking` — `'provider' => [...]` block (line 93-95).

The existing `?->name` on the nested `user` stays — a staff User record is not soft-deleted alongside the provider.

No behaviour change for bookings whose provider is active (`is_active: true`), so existing feature tests continue to pass. Existing assertions that only introspect `provider.id` or `provider.name` keep passing because those keys still appear.

### Step 3 — R-5 regression tests

Add three new tests that lock in the invariant. Each uses `attachProvider($business, $user, active: false)` to create a soft-deleted provider fixture (helper exists at `tests/Pest.php:66-82`).

- `tests/Feature/Services/SlotGeneratorServiceTest.php` — new test `soft-deleted provider is excluded from eligible providers`: two providers assigned to a service, soft-delete one; assert `getAvailableSlots($business, $service, $date)` returns slots only from the non-trashed provider, and `assignProvider(...)` returns the non-trashed provider.
- `tests/Feature/Booking/ProvidersApiTest.php` — new test `soft-deleted provider is not returned`.
- `tests/Feature/Booking/BookingCreationTest.php` — new test `soft-deleted provider_id is rejected as 409 on public store`: POST to the public booking endpoint with the soft-deleted provider's ID; assert 409 + existing `Selected provider is not available for this service.` translation key.

No changes to existing tests in these files.

### Step 4 — R-5 gap tests: display doesn't crash

Add two tests that cover the previously-latent bug:

- `tests/Feature/Dashboard/CalendarControllerTest.php` — new test `calendar renders bookings for a deactivated provider with is_active=false`: create a business + active admin + provider with one confirmed booking; soft-delete the provider; hit `/dashboard/calendar`; assert 200 (not 500); assert the provider prop on the booking has `is_active === false` and name preserved.
- `tests/Feature/Dashboard/DashboardBookingsTest.php` — analogous test on `/dashboard/bookings`.

Add two customer-side tests (these land after step 5 so they can also verify timezone):

- `tests/Feature/Booking/BookingManagementTest.php` — new test `booking management page renders with deactivated provider`.
- `tests/Feature/Customer/` directory does not yet exist; create `tests/Feature/Customer/BookingsListTest.php` with a new test `customer bookings list renders with deactivated provider`.

### Step 5 — R-6 backend: `Customer\BookingController::index` sends timezone

- Edit `app/Http/Controllers/Customer/BookingController.php::formatBooking` to add `'timezone' => $booking->business->timezone` to the `'business'` block.
- Eager-load adjustment: the `with(['service', 'provider.user', 'business'])` call already includes `business`, so no extra query is needed. Double-check no N+1 warning from Eloquent.

### Step 6 — R-6 frontend: use shared helpers with timezone

- Extend `resources/js/lib/datetime-format.ts`: `formatDateMedium(isoString, timezone?)` currently ignores timezone. Small addition:
  ```ts
  export function formatDateMedium(isoString: string | null, timezone?: string): string {
      if (!isoString) return '—';
      return new Date(isoString).toLocaleDateString([], { dateStyle: 'medium', timeZone: timezone });
  }
  ```
- `resources/js/types/index.d.ts`: extend `BookingSummary.business` to `{ name: string; timezone: string }`.
- `resources/js/pages/bookings/show.tsx`: replace the raw `date.toLocaleDateString()` and the two `toLocaleTimeString(...)` calls with `formatDateMedium` / `formatTimeShort` using `booking.business.timezone`.
- `resources/js/pages/customer/bookings.tsx::BookingItem`: same replacement, consume `booking.business.timezone`.
- Both pages: add the `(deactivated)` marker for `provider.is_active === false`.

### Step 7 — Dashboard "(deactivated)" marker on provider cells

- `resources/js/pages/dashboard/bookings.tsx` and the calendar booking popovers/cells use the booking's `provider.name` in multiple places. In each, add the marker via the same helper.
- Keep this step narrow: only the provider-name render sites. Do not touch grid layout, colors, avatars, or any unrelated styling.

### Step 8 — R-6 regression test

Add a timezone assertion test that exercises the rendered prop (backend → Inertia contract). This is cheaper and more stable than a Pest 4 browser test for an assertion of this shape.

- `tests/Feature/Booking/BookingManagementTest.php` — new test `booking management page passes business.timezone through to the page`: asserts the Inertia prop contains `booking.business.timezone` equal to the business's configured timezone.
- `tests/Feature/Customer/BookingsListTest.php` — new test `customer bookings list passes business.timezone per booking`: same shape.

These tests drive the contract. A Pest 4 browser test that exercises the client-side `Intl.DateTimeFormat` would also be valuable but is optional; the Inertia prop assertion already proves the backend-to-frontend contract, and the util itself is trivial enough that unit-style coverage in Vitest/JS is not adding much. Recorded as a future optional smoke test.

### Step 9 — Final verification

- `php artisan test --compact` — expect green. +8 new tests (§§3–8).
- `vendor/bin/pint --dirty --format agent` — apply style fixes.
- `npm run build` — expect no errors; the `datetime-format.ts` and type-file edits are small.

---

## 6. Files to create / modify / delete / rename

### Create

- `tests/Feature/Customer/BookingsListTest.php` — covers R-5 gap and R-6 timezone on the customer bookings list.

### Modify — backend

- `app/Models/Booking.php` — Step 1 (override `provider()` to `withTrashed`).
- `app/Http/Controllers/Dashboard/CalendarController.php` — Step 2 (add `is_active` to booking-map provider payload).
- `app/Http/Controllers/Dashboard/BookingController.php` — Step 2 (add `is_active`).
- `app/Http/Controllers/Booking/BookingManagementController.php` — Step 2 (add `is_active`).
- `app/Http/Controllers/Customer/BookingController.php` — Step 2 (add `is_active`) + Step 5 (add `business.timezone`).

### Modify — frontend

- `resources/js/types/index.d.ts` — extend `BookingSummary.business` with `timezone`; add `is_active: boolean` to provider shapes on `BookingDetail`, `BookingSummary`, `DashboardBooking`, `CustomerBookingHistory` (and any other booking-shaped type used by the four affected pages).
- `resources/js/lib/datetime-format.ts` — Step 6 (add optional `timezone` to `formatDateMedium`).
- `resources/js/pages/bookings/show.tsx` — Step 6 (use helpers with timezone) + Step 2 (deactivated marker).
- `resources/js/pages/customer/bookings.tsx` — Step 6 + Step 2 (deactivated marker).
- `resources/js/pages/dashboard/bookings.tsx` — Step 7 (deactivated marker on provider name render sites).
- `resources/js/pages/dashboard/calendar.tsx` and calendar subcomponents that render `provider.name` — Step 7.

### Modify — tests

- `tests/Feature/Services/SlotGeneratorServiceTest.php` — Step 3 (soft-deleted provider excluded).
- `tests/Feature/Booking/ProvidersApiTest.php` — Step 3 (soft-deleted provider not listed).
- `tests/Feature/Booking/BookingCreationTest.php` — Step 3 (soft-deleted provider_id → 409 on public store).
- `tests/Feature/Dashboard/CalendarControllerTest.php` — Step 4 (deactivated provider booking renders).
- `tests/Feature/Dashboard/DashboardBookingsTest.php` — Step 4 (deactivated provider booking renders).
- `tests/Feature/Booking/BookingManagementTest.php` — Steps 4 and 8 (deactivated provider + timezone prop).

### Modify — docs

- `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md` — append D-067 (see §4).

### Delete / rename

- None.

---

## 7. Testing plan

### 7.1 R-5 original-scope regression coverage (Step 3)

Three new tests covering the "default reads never return soft-deleted providers" invariant at three distinct layers — service layer, public JSON endpoint, public POST endpoint. Existing test `tests/Feature/Rules/BelongsToCurrentBusinessTest.php::soft-deleted in-tenant provider fails by default` already covers the manual-booking layer, so the manual `Dashboard\BookingController::store` path does not need a new controller test — the rule-level test suffices for that write path.

Each test is deterministic: fixtures use `attachProvider(..., active: false)` for the trashed provider; assertions target the emitted JSON/Inertia prop shape or the returned slot/provider collections. No time-sensitive expectations.

### 7.2 R-5 gap coverage (Step 4)

Four new tests, one per affected controller:

- `CalendarControllerTest` — `calendar renders bookings for a deactivated provider with is_active=false`.
- `DashboardBookingsTest` — `bookings list renders bookings for a deactivated provider with is_active=false`.
- `BookingManagementTest` — `booking management page renders with deactivated provider`.
- `BookingsListTest` (new file) — `customer bookings list renders with deactivated provider`.

Each creates a booking, soft-deletes the provider (`$provider->delete()`), makes the request, asserts:

- Response is 200 (proves no null-deref crash).
- Inertia prop `bookings.0.provider.is_active` is `false`.
- Inertia prop `bookings.0.provider.name` contains the original provider's display name (not blank).

### 7.3 R-6 coverage (Step 8)

Two contract tests:

- `BookingManagementTest::booking management page passes business.timezone through to the page` — asserts `booking.business.timezone` equals the seeded timezone.
- `BookingsListTest::customer bookings list passes business.timezone per booking` — asserts each booking item has `business.timezone`.

The frontend formatter is trivial enough that a unit test on the server-to-browser contract plus the dashboard's existing green tests (which already consume the same formatter with a timezone arg) prove the end-to-end behaviour. A Pest 4 browser test is considered optional (§5 Step 8).

### 7.4 Existing-test regression check

The model-level change (Step 1) may subtly affect any test that:

- Soft-deletes a provider and then asserts `$booking->provider` is null.
- Relies on `$booking->provider === null` branch behaviour in a notification or controller test.

Neither pattern exists in the current suite (confirmed by the grep in §1.2 step 15 — no such test constructs). But the plan verifies by running the full suite after Step 1 with no other edits.

### 7.5 Test-count expectation

Baseline (post-R-4B): **452 passed, 1727 assertions.** Target after this session: **+7 to +9 tests** (3 from §7.1, 4 from §7.2, 2 from §7.3). No deletions, no replacements.

---

## 8. Risks and mitigations

### 8.1 Model-level relation override has side effects beyond display

- **Risk**: overriding `Booking::provider()` to include trashed means every access of `$booking->provider` now resolves. Dependent code that relied on the null case silently changes behaviour.
- **Audit**: a grep over `app/` for `$booking->provider` and `$booking?->provider` yielded three usage classes: (a) display map callbacks (fix target); (b) null-safe user-equality in `updateStatus`/`updateNotes` — change is safe, see §3.1; (c) null-safe `?->user` merges in `notifyStaff` — change means trashed provider's user now receives notifications for historical bookings. §3.1 argues this is the correct semantic for MVP.
- **Mitigation**: §3.1's behavioural consequences are listed in D-067 so a future reviewer can see the contract. Step 1 is run with no other edits and the whole suite is exercised; any existing test that implicitly depended on the null case will fail loudly.

### 8.2 D-067 conflicts with D-061 if misread

- **Risk**: a future reader sees D-061 ("SoftDeletes on Provider is authoritative for deactivation") and D-067 ("Booking::provider includes trashed") and concludes that provider eligibility is now fuzzy.
- **Mitigation**: D-067's decision text explicitly names the eligibility-query paths that continue to apply `SoftDeletingScope` and separates them from the one display-shaped path that does not. The decision is named *"Booking::provider resolves the historical provider, including trashed"* — with "historical" carrying the semantic load.

### 8.3 "(deactivated)" marker localization

- **Risk**: the suffix is English-base `":name (deactivated)"`. Pre-launch translation files for IT/DE/FR need this key.
- **Mitigation**: the key is simple and explicit. Adding it to `lang/en.json` (and the placeholder IT/DE/FR files if present) is a one-line entry. Included in Step 7 when the React components add the `t()` call.

### 8.4 Missing provider avatar on trashed provider's User

- **Risk**: if a User's avatar was deleted alongside provider deactivation (it is not — avatar lives on `users.avatar`, independent of Provider), `$booking->provider->user->avatar` could be missing.
- **Mitigation**: Users aren't soft-deleted by the provider-toggle flow; avatars persist. `?->` guards on the nested `user` already handle the theoretical null-user case. No change.

### 8.5 Rippling provider.is_active to other booking-shaped types

- **Risk**: the TypeScript expansion of `BookingSummary` / `BookingDetail` / `DashboardBooking` / `CustomerBookingHistory` with an `is_active: boolean` on `provider` may reach pages that this plan doesn't touch (e.g., `resources/js/pages/dashboard/customers.tsx`, `customer-show.tsx`).
- **Mitigation**: making the field **required** in the type forces every Inertia-returning controller that emits one of those shapes to include it, which is the right level of pressure. The audit of affected pages in §6 names every page that composes these types; each gets the marker as part of Step 7. If a page is found not rendering provider anywhere, no action needed.

### 8.6 Notifications for deactivated providers

- **Risk** (§3.1): `notifyStaff` paths now include the deactivated provider's user in notification recipients. If the product says deactivation must also suppress notifications, this semantic is wrong.
- **Mitigation**: recorded explicitly in D-067's consequences. If the product team disagrees, a two-line filter in the `notifyStaff` helpers (`->reject(fn ($u) => $u->providerForBusiness($businessId)?->trashed())`) can undo it in a follow-up. Not a correctness regression vs the current MVP behaviour (which relied on null provider as an implicit suppression).

### 8.7 Pest 4 browser test vs Inertia prop assertion for R-6

- **Risk**: an Inertia prop assertion does not fully prove that the rendered DOM contains the correctly formatted string.
- **Mitigation**: §7.3 covers the backend contract. The client-side formatter is `formatTimeShort(iso, tz)` which is a pure Intl wrapper — correctness is trivial once `tz` is plumbed. A Pest 4 browser test at a later session (or as an optional add-on at Step 8) can verify end-to-end rendering. Not blocking.

---

## 9. Verification

After the session:

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm run build
```

Expected:

- All tests green. Count is baseline **+8** (±1) new tests.
- Pint reports no changes after formatting.
- Vite build completes without errors; main bundle size unchanged (this session does not materially change bundle size — the helper additions are trivial).

Manual smoke check (optional):

- In dev, seed with `migrate:fresh --seed`, log in as admin, deactivate a provider that has existing bookings via `/dashboard/settings/staff/{user}` toggle, then reload `/dashboard/calendar` and `/dashboard/bookings`. Expect the provider's name to render with "(deactivated)" and no 500 page.
- In dev, open a booking confirmation URL in an incognito window with the browser timezone forced to a non-Europe/Zurich zone (via devtools). Expect the rendered appointment time to match the seeded business timezone, not the browser's.

---

## 10. Out-of-plan follow-ups

- **R-7** (`allow_provider_choice` server-side enforcement + React step-init fix) — next session. Does not touch any file modified here, so no conflict.
- **Optional Pest 4 browser test** for the customer-facing timezone rendering — nice-to-have; deferred because the prop assertion already pins the contract.
- **Notifications for deactivated provider's User** — addressed by D-067 as the accepted MVP behaviour. If the product decides otherwise later, a narrow filter in `notifyStaff` is the fix. Recorded as a monitored edge case, not a deferred bug.
- **`docs/ARCHITECTURE-SUMMARY.md`** still uses old terminology ("collaborators", `business_user` pivot). Unrelated to R-5/R-6; flagged for a docs-only housekeeping session.

---

*End of plan. Stop here and wait for developer approval.*
