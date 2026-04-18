---
name: PLAN-R-17-BOOKABILITY
description: "R-17: bookability enforcement (Round 2, Session A)"
type: plan
status: shipped
created: 2026-04-16
updated: 2026-04-16
---

# PLAN-R-17 — Bookability enforcement (Round 2, Session A)

> **Status**: Draft — awaiting approval
> **Roadmap item**: R-17 in `docs/reviews/ROADMAP-REVIEW-2.md`
> **Source finding**: REVIEW-2 HIGH-1 (`docs/reviews/REVIEW-2.md`)
> **Baseline**: commit `aee896f`, Pest suite **496 passed / 2073 assertions**
> **Related decisions**: extends D-061 + D-062 (no supersede)

---

## 1. Goal

Close the gap where the D-062 launch gate treats "service has any provider row attached" as equivalent to "service is actually bookable". A provider row with **zero `availability_rules`** still produces no slots, so the public page advertises a service the availability engine can never fulfil. Round 2 tightens the definition once, centrally, and wires it through four callers (onboarding launch, public listing, dashboard banner, and the existing settings/account flash message).

The session must also make misconfiguration visible to the admin without muddying the distinction between **structural** (data-model) and **temporary** (calendar) unavailability — a full-agenda week or vacation exception is **not** a misconfiguration.

## 2. Scope

### In scope

1. A single `Service` query scope (`structurallyBookable` / `structurallyUnbookable`) that is the source of truth for bookability across the app.
2. Rigid step-3 validation: an opt-in schedule with zero enabled-with-windows days is rejected at the FormRequest layer, with a defensive guard in `writeProviderSchedule()` as a second line.
3. Launch gate switches to the new scope; `launchBlocked` payload lists every structurally unbookable active service, not only attachment gaps.
4. Public `/{slug}` listing hides structurally unbookable services (established D-062 pattern extended — Q1 confirmation).
5. Persistent dashboard-wide banner via Inertia shared props, rendered from `AuthenticatedLayout`, visible to admins only, listing every affected service with a link to the fix.
6. Migrate the one remaining `whereDoesntHave('providers')` consumer (`Dashboard\Settings\AccountController::toggleProvider` flash-message count) onto the same scope so behaviour stays coherent.
7. Full test coverage per the roadmap's minimum list plus a consistency test that asserts the three callers agree.
8. New decision recorded in `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md` (extends D-062).
9. Session-close: HANDOFF rewrite, roadmap tick-through, Pint/test/build all green, plan file archived.

### Out of scope (BACKLOG.md, per the roadmap)

- Email/push admin notification when a service crosses into unbookable post-launch.
- Richer "provider is on vacation" UX on the public page.
- Auto-dismiss history / per-user ack for the banner.
- Auto-hide services when another admin detaches the last provider (already covered by D-062 via `whereHas('providers')`; extension under the new scope inherits the same self-heal behaviour).

## 3. Confirmed design decisions (from the pre-plan round)

| Decision | Choice | Rationale |
| --- | --- | --- |
| Public page UX | **Hide** unbookable services | Matches D-062's existing `whereHas('providers')` filter; zero-state already handled by `ServiceList`. |
| Validation layer | **FormRequest `withValidator` closure** | Keeps `storeService` thin; error key `provider_schedule` already surfaces in `step-3.tsx:367`. A `LogicException` guard in `writeProviderSchedule()` is defense-in-depth for future callers. |
| Predicate location | **Query scope on `Service`** | Composes with `$business->services()->...`; supports both bulk listing and single-row probes; idiomatic Laravel. |

## 4. Structural-bookability definition

A service is **structurally bookable** iff all three:

1. `services.is_active = true`
2. At least one **non-soft-deleted** provider is attached via `provider_service`
3. At least one of those providers has ≥1 `availability_rules` row

Explicitly **not** part of the definition:

- Slots exist in the next N days
- Calendar exceptions (vacation, block, open)
- Full agenda for the visible horizon
- Business hours closed for the current day

Those are temporary unavailability — they produce "no slots" correctly and must **not** trigger the banner or hide the service.

## 5. Target implementation

### 5.1 Scope on `Service` (the single source of truth)

`app/Models/Service.php` — add two scopes:

```php
public function scopeStructurallyBookable(Builder $query): Builder
{
    return $query
        ->where('is_active', true)
        ->whereHas('providers', fn (Builder $q) => $q->has('availabilityRules'));
}

public function scopeStructurallyUnbookable(Builder $query): Builder
{
    return $query
        ->where('is_active', true)
        ->whereDoesntHave('providers', fn (Builder $q) => $q->has('availabilityRules'));
}
```

Notes:
- `providers()` is `BelongsToMany` through `provider_service`; `whereHas` filters by the pivot join.
- `Provider` uses `SoftDeletes`, so `whereHas('providers')` excludes soft-deleted rows by default.
- `availabilityRules` is `HasMany<AvailabilityRule>` on `Provider`; `$q->has('availabilityRules')` checks ≥1 rule exists.
- Both scopes filter `is_active = true` because the banner, public listing, and launch gate only care about active services. Inactive services are never advertised and never block launch.

Imports needed: `Illuminate\Database\Eloquent\Builder`.

### 5.2 Onboarding step-3 validation

`app/Http/Requests/Onboarding/StoreServiceRequest.php` — add `withValidator(Validator $validator)`:

```php
public function withValidator(Validator $validator): void
{
    $validator->after(function (Validator $v) {
        if (! $this->boolean('provider_opt_in')) {
            return;
        }
        $schedule = $this->input('provider_schedule', []);
        $hasAnyWindow = collect($schedule)
            ->contains(fn ($day) => ! empty($day['enabled']) && ! empty($day['windows']));
        if (! $hasAnyWindow) {
            $v->errors()->add('provider_schedule', __(
                'Add at least one available window so customers can book with you.'
            ));
        }
    });
}
```

Import: `Illuminate\Validation\Validator`.

The existing per-window validation rules (`size:7`, `date_format:H:i`, `after:...`) already reject malformed payloads. This `after()` callback only fires when the shape is valid but semantically empty.

### 5.3 Defensive guard in `writeProviderSchedule`

`app/Http/Controllers/OnboardingController.php:548-567` — prepend a guard:

```php
private function writeProviderSchedule(Provider $provider, Business $business, array $schedule): void
{
    $hasAny = collect($schedule)
        ->contains(fn (array $day) => ! empty($day['enabled']) && ! empty($day['windows']));
    if (! $hasAny) {
        throw new \LogicException('Refusing to persist an empty provider schedule.');
    }
    // ...existing delete + insert...
}
```

Rationale: the FormRequest is the primary gate; this guard catches any future caller that skips validation. It never fires on the current code paths (the only callers are the validated step-3 payload and `writeScheduleFromBusinessHours`, which builds from `business_hours` that step-2 already forces to be non-empty for enabled days).

Note on `writeScheduleFromBusinessHours`: step-2's `StoreHoursRequest` already rejects an empty set of hours, and `enableOwnerAsProvider` is a one-click recovery that's only reachable when the admin already has valid business hours. So the guard is purely defensive — it will not regress existing flows. A targeted test confirms the happy path still works.

### 5.4 Launch gate

`OnboardingController::storeLaunch()` — replace the query body:

```php
$unstaffedServices = $business->services()
    ->structurallyUnbookable()
    ->get(['id', 'name'])
    ->map(fn (Service $s) => ['id' => $s->id, 'name' => $s->name])
    ->values()
    ->all();
```

The session-flash key stays `launchBlocked` → `services` so the existing step-3 frontend continues to render the list without modification. Copy remains accurate — the services listed are those that fail the structural-bookability check.

### 5.5 Public page

`app/Http/Controllers/Booking/PublicBookingController.php:42-45` — replace the services query:

```php
$services = $business->services()
    ->structurallyBookable()
    ->get();
```

`ServiceList` already handles the zero-service case ("Nothing to book just yet.") — no frontend change.

### 5.6 Dashboard banner

Shared prop (eager, admin-only, cheap single query):

`app/Http/Middleware/HandleInertiaRequests.php` — add a shared prop:

```php
'bookability' => fn () => $this->resolveBookability($request),

private function resolveBookability(Request $request): array
{
    if (! $request->user()) {
        return ['unbookableServices' => []];
    }
    $tenant = tenant();
    if (! $tenant->has()) {
        return ['unbookableServices' => []];
    }
    $business = $tenant->business();
    if (! $business->isOnboarded()) {
        return ['unbookableServices' => []];
    }
    if ($tenant->role() !== BusinessMemberRole::Admin) {
        return ['unbookableServices' => []];
    }
    return [
        'unbookableServices' => $business->services()
            ->structurallyUnbookable()
            ->get(['id', 'name'])
            ->map(fn (Service $s) => ['id' => $s->id, 'name' => $s->name])
            ->values()
            ->all(),
    ];
}
```

Frontend banner in `resources/js/layouts/authenticated-layout.tsx`:

- Read `bookability.unbookableServices` from `usePage<PageProps>().props`.
- When non-empty, render an `Alert variant="warning"` inside `<main>` above the existing content (both in fullBleed and normal branches — positioned so it's visible above the heading bar, does not affect layout width, and collapses cleanly when empty).
- Alert contents (all wrapped in `t()`):
  - Title: `"Some services aren't bookable yet"`
  - Description: names list + one-line explanation + a `Link` to `/dashboard/settings/services` with label `"Fix"`.
- Only renders when the array is non-empty (implicit admin-only via shared-prop logic).

`types/index.d.ts` — extend `PageProps` with a `bookability: { unbookableServices: { id: number; name: string }[] }` field so TypeScript sees it.

### 5.7 Consumer audit — the one remaining call site

`app/Http/Controllers/Dashboard/Settings/AccountController.php:148-152` uses `whereDoesntHave('providers')` to count in a flash message. Migrate to `structurallyUnbookable()` so the flash message stays consistent with the banner and launch gate (a service becomes "unbookable" the moment the last provider detaches OR the last provider's rules vanish — same definition everywhere).

`grep` confirmed these are the only three sites across `app/`:
- `OnboardingController.php:450` (launch gate) — replace
- `Dashboard/Settings/AccountController.php:151` (flash count) — replace
- `Booking/PublicBookingController.php:44` (public listing) — replace

No other `whereHas/whereDoesntHave('providers')` patterns exist on `Service`. No other code rehydrates bookability from raw joins.

## 6. Tests to add / extend

All tests are Pest feature tests. Helpers `attachProvider`, `attachAdmin` live in `tests/Pest.php`.

### 6.1 Onboarding step-3 (`tests/Feature/Onboarding/Step3ServiceTest.php`)

**New**: `opt-in true with all days disabled is rejected`

```php
$schedule = collect(range(1, 7))->map(fn ($d) => [
    'day_of_week' => $d, 'enabled' => false, 'windows' => [],
])->all();

$this->actingAs($this->user)->post('/onboarding/step/3', [...valid service..., 'provider_opt_in' => true, 'provider_schedule' => $schedule])
    ->assertSessionHasErrors(['provider_schedule']);

expect(Provider::where('business_id', $this->business->id)->exists())->toBeFalse();
expect(Service::where('business_id', $this->business->id)->exists())->toBeFalse();
```

**New**: `opt-in true with days enabled but no windows is rejected` — same shape, all 7 days `enabled = true` but `windows = []`. Same assertions.

### 6.2 Onboarding step-5 launch (`tests/Feature/Onboarding/Step5LaunchTest.php`)

**New**: `launch blocked when active service has provider with zero availability rules`

```php
$provider = attachProvider($this->business, $this->user);
$provider->services()->attach($this->service->id);
// note: NO availability_rules rows

$response = $this->actingAs($this->user)->post('/onboarding/step/5');
$response->assertRedirect(route('onboarding.show', ['step' => 3]))
    ->assertSessionHas('launchBlocked', function (array $data) {
        expect(collect($data['services'])->pluck('id'))->toContain($this->service->id);
        return true;
    });
```

**Extend** the existing `launch blocked lists every unstaffed active service but ignores inactive ones` to also assert inclusion of a "provider-attached-but-zero-rules" service alongside the "no-provider" service, proving the launchBlocked payload uses the structural definition.

### 6.3 Public page (new: `tests/Feature/Booking/PublicPageBookabilityTest.php`)

**New**: `public page hides service with a provider attached but no availability rules`

```php
$business = Business::factory()->onboarded()->create();
$service = Service::factory()->create(['business_id' => $business->id, 'is_active' => true]);
$user = User::factory()->create();
$provider = attachProvider($business, $user);
$provider->services()->attach($service);
// zero availability_rules

$response = $this->get('/'.$business->slug);
$response->assertInertia(fn ($page) => $page
    ->component('booking/show')
    ->where('services', []),
);
```

**New**: `public page shows service when provider has availability rules` — sibling positive test.

### 6.4 Dashboard banner (new: `tests/Feature/Dashboard/UnbookableBannerTest.php`)

**New**: `admin dashboard exposes unbookable service list via shared prop`

```php
// create onboarded business, attach admin, create active service with provider but no rules
$response = $this->actingAs($admin)->get('/dashboard');
$response->assertInertia(fn ($page) => $page
    ->where('bookability.unbookableServices.0.name', $service->name)
    ->where('bookability.unbookableServices', fn ($list) => count($list) === 1),
);
```

**New**: `staff does not see the banner even when unbookable services exist` — assert `bookability.unbookableServices = []` for role=staff (banner is admin-only).

**New**: `banner is empty when all services are structurally bookable` — sibling negative test, covers the "goes away automatically" requirement.

**New (regression)**: `temporary unavailability does not trigger the banner`

```php
// provider has AvailabilityRule rows + is attached to service
// create a full-day AvailabilityException(type=Block) covering today
$response = $this->actingAs($admin)->get('/dashboard');
$response->assertInertia(fn ($page) => $page
    ->where('bookability.unbookableServices', []),
);
```

This is the critical test proving the scope ignores calendar exceptions / full agendas.

### 6.5 Consistency (new: `tests/Feature/Bookability/ScopeConsistencyTest.php`)

**New**: `onboarding launch, public page, and dashboard banner agree on the unbookable set`

Create a business with three services:
- A: active, provider attached, availability_rules present → bookable
- B: active, provider attached, no availability_rules → unbookable
- C: active, no providers attached → unbookable

Assertions in one test:

1. `POST /onboarding/step/5` on a pre-onboarded version redirects with `launchBlocked.services` containing exactly `{B, C}`.
2. `GET /{slug}` renders `services` containing exactly `{A}`.
3. `GET /dashboard` renders `bookability.unbookableServices` containing exactly `{B, C}`.

This is the single integration-level check the roadmap requires for centralisation.

### 6.6 Defensive guard

**New (unit-ish)**: `writeProviderSchedule throws on empty schedule` — via a focused test in `Step3ServiceTest.php` that sends an opt-in request and asserts the FormRequest catches it before the controller runs. The `LogicException` guard is not surfaced by its own test; it's defense-in-depth for future callers, and making it testable publicly would require exposing the private method.

### 6.7 Existing tests to update

- `tests/Feature/Booking/AvailableDatesApiTest.php` is unchanged — it tests the slot API under the assumption that the service is reachable; that's still true via the direct API endpoint. The page-level hiding does not affect the API.
- `tests/Feature/Onboarding/SoloBusinessBookingE2ETest.php` is unchanged — the happy path writes a valid schedule.
- `tests/Feature/Onboarding/Step5LaunchTest.php`: the existing `launch blocked when active service has zero providers` keeps passing because "no providers" is one of the two unbookable flavours.

## 7. Files to touch (complete list)

**Backend**

- `app/Models/Service.php` — add two scopes (+ `Builder` import)
- `app/Http/Requests/Onboarding/StoreServiceRequest.php` — add `withValidator`
- `app/Http/Controllers/OnboardingController.php` — replace launch query, prepend guard in `writeProviderSchedule`
- `app/Http/Controllers/Booking/PublicBookingController.php` — replace services query (line 42-45)
- `app/Http/Controllers/Dashboard/Settings/AccountController.php` — replace flash-count query (line 149-152)
- `app/Http/Middleware/HandleInertiaRequests.php` — add `bookability` shared prop (+ `Service`, `BusinessMemberRole` imports)

**Frontend**

- `resources/js/layouts/authenticated-layout.tsx` — render Alert from shared prop
- `resources/js/types/index.d.ts` — extend `PageProps` with `bookability.unbookableServices`

**Tests**

- `tests/Feature/Onboarding/Step3ServiceTest.php` — +2 tests
- `tests/Feature/Onboarding/Step5LaunchTest.php` — +1 test, extend 1 existing
- `tests/Feature/Booking/PublicPageBookabilityTest.php` — **new file**, 2 tests
- `tests/Feature/Dashboard/UnbookableBannerTest.php` — **new file**, 4 tests
- `tests/Feature/Bookability/ScopeConsistencyTest.php` — **new file**, 1 test

**Docs**

- `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md` — append D-078
- `docs/HANDOFF.md` — full rewrite at session close
- `docs/reviews/ROADMAP-REVIEW-2.md` — flip R-17 to **complete** with closing date
- `docs/BACKLOG.md` — add the three out-of-scope items listed in §2
- `docs/plans/PLAN-R-17-BOOKABILITY.md` → `docs/archive/plans/PLAN-R-17-BOOKABILITY.md` at session close

## 8. Decision to record (D-078)

**File**: `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md` (appended — extends D-062)

**Title**: D-078 — Structural bookability is a single query scope; public page hides, dashboard banners

**Body outline**:

1. **Name & location**: `Service::scopeStructurallyBookable` / `Service::scopeStructurallyUnbookable` in `app/Models/Service.php`.
2. **Three structural conditions**: active service, ≥1 non-soft-deleted provider attached, ≥1 of those providers has ≥1 availability rule.
3. **Explicit exclusion**: temporary unavailability (exceptions, full agendas, closed-today business hours) is **not** part of the definition — those surfaces render normally and simply produce zero slots.
4. **Four callers** share the scope: `OnboardingController::storeLaunch`, `PublicBookingController::show`, `HandleInertiaRequests::share` (bookability prop → dashboard banner), `Dashboard\Settings\AccountController::toggleProvider` (flash-message count).
5. **Public page behaviour**: hide structurally unbookable services from `/{slug}` (continues the D-062 `whereHas('providers')` pattern).
6. **Dashboard banner**: persistent `Alert variant="warning"` in `AuthenticatedLayout`, admin-only, lists affected services with a link to `/dashboard/settings/services`. Auto-clears when empty — no manual dismiss for MVP.
7. **Extends, does not supersede**: D-062's core rule ("launch requires at least one eligible provider per active service") stands; D-078 sharpens "eligible".

## 9. Verification plan

End-to-end:

```bash
php artisan test --compact            # expected: ≥ 505 passed (496 + ≥9 new)
vendor/bin/pint --dirty --format agent
npm run build
```

Focused reruns during implementation:

```bash
php artisan test --compact --filter='Step3Service|Step5Launch|PublicPageBookability|UnbookableBanner|ScopeConsistency'
```

Manual smoke (only if frontend touches behave oddly):

```bash
# 1. Log in as admin of an onboarded business, detach the sole provider's availability rules via tinker
# 2. Visit /dashboard — expect the warning Alert with the service name at the top
# 3. Visit /{slug} — expect the service to disappear from the listing
# 4. Re-add an availability rule — expect the banner to vanish and the service to reappear
```

## 10. Risks & mitigations

| Risk | Mitigation |
| --- | --- |
| `HandleInertiaRequests::share` runs on every Inertia request — query cost. | The query hits one small table (`services`) with a `whereHas` nested against `providers`/`availability_rules`, both indexed. It short-circuits for guests, non-tenants, and staff. Expected cost: single-digit ms per request. If it ever surfaces as a hotspot, we can memoize per request via the `TenantContext`. |
| `LogicException` guard in `writeProviderSchedule` surfaces as a 500 if a future code path bypasses validation. | Two existing callers are covered: step-3 validated payload, and `writeScheduleFromBusinessHours` which is itself gated by step-2's non-empty `business_hours`. A targeted test confirms the happy path still runs. |
| Banner flashes during onboarding because the shared prop runs before onboarding completes. | Resolver checks `$business->isOnboarded()` and returns an empty list pre-launch. The banner only appears post-launch, which is the exact window the roadmap requires. |
| `authenticated-layout.tsx` is on the `useLayoutProps`-free path — adding a shared prop doesn't require `setLayoutProps`. | Confirmed: prop comes through `HandleInertiaRequests::share`, read via `usePage<PageProps>().props.bookability`. No layout-prop plumbing needed. |
| Consistency test becomes fragile if scopes diverge in the future. | Exactly the point — the consistency test is a canary. If it breaks, either all three callers must move together, or the definition needs a new decision. |

## 11. Session-done checklist

Per `docs/reviews/ROADMAP-REVIEW-2.md` §"Session-done checklist":

- [ ] `php artisan test --compact` green
- [ ] `vendor/bin/pint --dirty --format agent` → `{"result":"pass"}`
- [ ] `npm run build` green
- [ ] `docs/HANDOFF.md` rewritten (not appended)
- [ ] D-078 recorded in `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md`
- [ ] `docs/reviews/ROADMAP-REVIEW-2.md` R-17 → **complete** with closing date `2026-04-16`
- [ ] Plan file moved to `docs/archive/plans/PLAN-R-17-BOOKABILITY.md`
- [ ] Out-of-scope items appended to `docs/BACKLOG.md`

---

*Approval gate: await developer sign-off before implementation.*
