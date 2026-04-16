# REVIEW-2

- **Date**: 2026-04-16
- **Commit reviewed**: `aee896f02f70b24acc134049bdf94fc83a27617e`
- **Suite baseline observed**: `php artisan test --compact` → **496 passed (2073 assertions)** in **21.82s**
- **Versions observed**:
  - PHP **8.3**
  - Laravel **13.4.0**
  - Inertia Laravel **3.0.6**
  - React **19.2.5**
  - Boost `application-info` reports database engine `pgsql`; `SHOW server_version` on the inspected local DB returned **18.1 (Postgres.app)**

## EXECUTIVE SUMMARY

This re-review used the **16 consolidated R-NN items** from `docs/archive/reviews/ROADMAP-REVIEW-1.md` as the status matrix basis, then verified each claim directly in the current codebase, tests, routes, and live schema.

Overall verdict:

- **14 / 15 non-deferred REVIEW-1 items are fully fixed**
- **1 / 15 is only partially fixed**: `R-1`
- **0 / 15 are fully unfixed**
- **0 / 15 are regressed**
- **R-16 remains correctly Deferred** and is tracked in `docs/BACKLOG.md`; it is not counted as a failure

New findings introduced or left behind by the remediation work:

- **High**: 1
- **Medium**: 1
- **Info**: 1

Residual findings tied to original REVIEW-1 scope:

- **High**: 1 (`R-1` is only partially closed because launch gating still treats “provider row exists” as equivalent to “service is actually bookable”)

Verification signals:

- The full Pest suite is still green at **496 passed / 2073 assertions**
- A non-destructive frontend build (`./node_modules/.bin/vite build --outDir /tmp/riservo-review-build`) succeeded
- The build still emits a **large-chunk warning** with `app-C2Yiei2U.js` at **958.55 kB** before gzip; this matches the known **R-16 deferral** and is not reported as an unfixed bug

The main cross-cutting concerns for a second remediation round are **bookability consistency** and **multi-business invitation parity**: some remediated surfaces now key off “provider row exists”, while the actual availability engine still requires real provider availability windows and snapped booking buffers; meanwhile the invitation flow still assumes every invite acceptance creates a brand-new user even though multi-business membership is now an explicit capability.

## REVIEW-1 STATUS MATRIX

This matrix uses the **16 consolidated R-NN remediation items** from `ROADMAP-REVIEW-1.md`.

| # | Remediation Item | Status | Decision(s) | Notes |
|---|---|---|---|---|
| R-1 | Admin as Provider | **Partially fixed** | D-061, D-062 | Verified owner opt-in, provider model split, launch blocking, and solo-business E2E coverage in `app/Http/Controllers/OnboardingController.php`, `app/Http/Requests/Onboarding/StoreServiceRequest.php`, `tests/Feature/Onboarding/Step3ServiceTest.php`, `Step5LaunchTest.php`, and `SoloBusinessBookingE2ETest.php`. Residual risk: launch still passes when a provider row exists but has zero availability rules; see **HIGH-1**. |
| R-2 | Tenant Context and Multi-Business Membership | **Fixed** | D-063 | `App\Support\TenantContext`, `ResolveTenantContext`, `EnsureUserHasRole`, `HandleInertiaRequests`, `LoginController`, `MagicLinkController`, `RegisterController`, and tenant-context tests now align on a session-pinned current business. |
| R-3 | Cross-Tenant Validation of Foreign Keys | **Fixed** | D-064 | `App\Rules\BelongsToCurrentBusiness` is in place and used by the relevant settings/manual-booking/onboarding Form Requests. Cross-tenant validation tests cover foreign service/provider IDs. |
| R-4 | Booking Race Condition | **Fixed** | D-065, D-066 | Live schema confirms `bookings_no_provider_overlap` as a partial `GIST` exclusion index on `tsrange(effective_starts_at, effective_ends_at, '[)')`; both booking write paths now transact and catch `23P01`. Original race is fixed. Related new drift in availability math is reported separately in **HIGH-2**. |
| R-5 | Deactivated Collaborators Still Appear in Booking Flows | **Fixed** | D-061, D-067 | Soft-deleted `providers` are excluded from new-work queries and preserved for historical booking display via `Booking::provider()->withTrashed()`. Covered in public-page, provider API, customer page, and booking-management tests. |
| R-6 | Timezone Rendering on Customer-Facing Pages | **Fixed** | D-005 | `BookingManagementController`, `Customer\BookingController`, and `resources/js/lib/datetime-format.ts` now pass and use business timezones consistently. Customer-facing timezone props are covered by tests. |
| R-7 | Collaborator-Choice Policy Enforcement | **Fixed** | D-068 | `PublicBookingController` now returns an empty provider list when choice is off and ignores submitted `provider_id` on available-dates, slots, and store. `resources/js/pages/booking/show.tsx` also skips the provider step when disabled. |
| R-8 | Calendar Bug Fixes and Mobile Improvements | **Fixed** | D-069 | The nested-`<li>` hydration bug is gone; `CurrentTimeIndicator` is now rendered directly inside the grid list. Mobile week view now falls back to an agenda list and the view switcher is visible on small screens. |
| R-9 | Popup Embed: Service Pre-Filter and Modal Robustness | **Fixed** | D-054, D-070 | `public/embed.js` now supports `data-riservo-service`, guards against duplicate overlays, restores focus, handles Escape, and locks body scroll. `dashboard/settings/embed.tsx` generates matching snippets. Manual accessibility QA is still a carry-over, but the original implementation gap is fixed. |
| R-10 | Reminder Scheduling: DST Safety and Delayed-Run Resilience | **Fixed** | D-071 | `SendBookingReminders` now uses business-timezone wall-clock eligibility plus past-due recovery, backed by DST and delayed-run tests. Live schema confirms `booking_reminders (booking_id, hours_before)` uniqueness. |
| R-11 | Rate Limiting on Auth Recovery Endpoints | **Fixed** | D-072 | `SendMagicLinkRequest` and `SendPasswordResetRequest` implement per-email and per-IP buckets from `config/auth.php`, and `AuthRecoveryThrottleTest` covers lockout, decay, and namespace separation. |
| R-12 | Dashboard Welcome Links and Copy Drift | **Fixed** | — | `resources/js/pages/dashboard/welcome.tsx` now uses existing settings routes, and invitation expiry copy is sourced from `BusinessInvitation::EXPIRY_HOURS`. Covered by `WelcomePageTest.php` and `StaffTest.php`. |
| R-13 | Customer Registration Scope Clarification | **Fixed** | D-074 | Customer registration is now open: `CustomerRegisterRequest` validates `unique:users,email`, and `CustomerRegisterController` creates or links `Customer` rows without requiring a prior booking. Covered by `CustomerRegisterTest.php`. |
| R-14 | Notification Delivery and Branding Cleanup | **Fixed** | D-075 | Interactive notifications now dispatch with `dispatch(fn () => ...)->afterResponse()` and are covered by `AfterResponseDispatchTest.php`. Runtime branding is coherent through `.env.example`, `config/app.php`, and notification copy. |
| R-15 | Dependency and URL Generation Cleanup | **Fixed** | D-076 | `axios` and `geist` are gone from `package.json`, `composer dev` no longer runs `php artisan serve`, `asset('storage/...')` is gone from `app/`, and logo clearing now deletes files and normalizes to `null` through `Business::removeLogoIfCleared()`. |
| R-16 | Frontend Code Splitting | **Deferred** | — | `resources/js/app.tsx` still uses `import.meta.glob(..., { eager: true })`, and the non-destructive build still produces a ~958 kB main bundle. This matches the explicit backlog deferral in `docs/BACKLOG.md` and is not treated as an outstanding bug. |

## CRITICAL

No critical findings.

## HIGH

### HIGH-1 — R-1 is only partially fixed because launch gating checks for provider rows, not actual bookability

- **Severity**: High
- **Area / layer**: Onboarding, public booking, scheduling correctness
- **Affected file(s)**:
  - `app/Http/Controllers/OnboardingController.php:446-463`
  - `app/Http/Controllers/OnboardingController.php:548-566`
  - `app/Http/Requests/Onboarding/StoreServiceRequest.php:21-37`
  - `app/Services/AvailabilityService.php:40-47`
  - `tests/Feature/Booking/AvailableDatesApiTest.php:83-95`
- **What I found**:
  - The new launch gate blocks only when an active service `whereDoesntHave('providers')`.
  - The onboarding step-3 request requires a `provider_schedule` array when the owner opts in, but it does **not** require at least one enabled window.
  - `writeProviderSchedule()` deletes existing rules and inserts nothing when every day is disabled or every `windows` array is empty.
  - `AvailabilityService::getAvailableWindows()` returns an empty result whenever the provider has no effective provider windows.
- **Why it matters**:
  - This is a narrower form of the original solo-business failure mode: onboarding can still finish while the public page advertises a service that can never produce slots.
  - The current fix guarantees “provider row exists”, not “service is actually bookable”.
- **Evidence and reasoning**:
  - `OnboardingController::storeLaunch()` uses `whereDoesntHave('providers')`, which is attachment-only.
  - `StoreServiceRequest` validates the schedule shape, but not semantic availability.
  - `writeProviderSchedule()` persists zero rules for an all-disabled payload.
  - `AvailableDatesApiTest.php:83-95` explicitly demonstrates that a provider with no availability rules yields no available dates.
- **Recommended direction**:
  - Define bookability centrally and use that definition in onboarding launch checks, public service visibility, and any future provider toggles.
  - At minimum, reject an opted-in onboarding schedule that contains no enabled windows.
  - Preferably, gate launch on “service has at least one provider capable of producing availability”, not on `whereHas('providers')` alone.
- **Type**: correctness

### HIGH-2 — Slot availability still uses live service buffers instead of the snapped booking buffers stored for D-066

- **Severity**: High
- **Area / layer**: Scheduling, booking correctness, read/write invariant alignment
- **Affected file(s)**:
  - `app/Services/SlotGeneratorService.php:172-193`
  - `app/Http/Controllers/Booking/PublicBookingController.php:295-309`
  - `app/Http/Controllers/Dashboard/BookingController.php:305-319`
  - `database/migrations/2026_04_16_100007_create_bookings_table.php:23-24`
  - `database/migrations/2026_04_16_100007_create_bookings_table.php:40-64`
  - `tests/Feature/Booking/BookingBufferGuardTest.php:62-155`
  - `tests/Feature/Booking/BookingOverlapConstraintTest.php:78-101`
- **What I found**:
  - The write path correctly snapshots `buffer_before_minutes` and `buffer_after_minutes` into each booking.
  - The DB overlap invariant is correctly enforced from the generated `effective_starts_at` / `effective_ends_at` columns derived from those snapped fields.
  - But `SlotGeneratorService::conflictsWithBookings()` still computes blocking intervals from `$booking->service->buffer_before` and `$booking->service->buffer_after`, i.e. the **current** service configuration.
- **Why it matters**:
  - Editing a service’s buffers after bookings already exist makes the UI’s availability math diverge from the DB invariant.
  - Result: customers and staff can be shown slots that later 409 at booking time, or lose slots that should now be available.
- **Evidence and reasoning**:
  - Controllers now persist snapped buffer fields on booking creation.
  - The live schema confirms the exclusion guard uses `effective_starts_at` / `effective_ends_at`.
  - `SlotGeneratorService` ignores those snapped fields and rehydrates intervals from the mutable service relation instead.
  - Existing tests cover buffer blocking and DB overlap behavior, but none cover the “edit service buffers after booking creation” case.
- **Recommended direction**:
  - Make all availability conflict checks read from `bookings.buffer_before_minutes` / `bookings.buffer_after_minutes` or from the generated effective columns.
  - Add a regression test that creates a booking, edits the service buffers, and confirms slot visibility still matches the DB constraint.
- **Type**: correctness, maintainability

## MEDIUM

### MEDIUM-1 — Existing-user invitation flow is broken now that multi-business membership is an accepted capability

- **Severity**: Medium
- **Area / layer**: Auth, invitations, tenant membership
- **Affected file(s)**:
  - `app/Http/Controllers/Dashboard/Settings/StaffController.php:147-205`
  - `app/Http/Controllers/Auth/InvitationController.php:34-66`
  - `database/migrations/0001_01_01_000000_create_users_table.php:11-15`
  - `tests/Feature/Auth/InvitationTest.php:39-68`
  - `tests/Feature/Settings/StaffTest.php:89-112`
- **What I found**:
  - The invite path accepts any email that is not already a member of the current business and does not reject emails that already exist in `users`.
  - The acceptance path still always does `User::create(...)`, then attaches the new user to the invited business and creates a provider row.
- **Why it matters**:
  - After `D-063`, multi-business membership is no longer treated as invalid data; it is an accepted capability, even if the business-switcher UX is deferred.
  - That makes “invite an already-registered user into another business” a real scenario, but the current flow still assumes the invitee is always a brand-new user.
  - The result is a broken invitation flow: an admin can send an invite that the acceptance path cannot fulfill because `users.email` is globally unique.
- **Evidence and reasoning**:
  - `StaffController::invite()` checks only “existing active invitation” and “already a member of this business”.
  - `InvitationController::accept()` unconditionally calls `User::create(['email' => $invitation->email, ...])`.
  - `0001_01_01_000000_create_users_table.php` defines `users.email` as unique.
  - The current tests cover only the new-user acceptance path and the “already a member of this business” rejection path; they do not cover an existing user invited into a second business.
- **Recommended direction**:
  - Align the invitation flow with the post-`D-063` model: when the invited email already belongs to a `User`, attach that existing user to the invited business instead of trying to create another `User`.
  - If that broader join flow is intentionally deferred to `R-2B`, reject such invites explicitly at invite time rather than relying on a downstream uniqueness failure during acceptance.
- **Type**: correctness, maintainability

## LOW

No low findings.

## INFO

### INFO-1 — D-061’s `business_members` uniqueness shape does not match the live schema

- **Severity**: Info
- **Area / layer**: Schema / decision consistency
- **Affected file(s)**:
  - `database/migrations/2026_04_16_100003_create_business_members_table.php:11-20`
  - `app/Models/BusinessMember.php:12-25`
  - Boost `database-schema`
  - Boost `database-query` on `pg_indexes`
- **What I found**:
  - `providers` correctly use a deleted-at-aware unique index: `(business_id, user_id, deleted_at)`.
  - `business_members` do **not**: the live schema and migration still define `UNIQUE (business_id, user_id)`.
  - D-061, `SPEC.md`, and `ARCHITECTURE-SUMMARY.md` describe deleted-at-aware uniqueness for both `providers` and `business_members`.
- **Why it matters**:
  - There is no immediate runtime failure because the current product flow does not yet expose member soft-delete / rejoin behavior.
  - But the codebase now carries schema-vs-decision drift in a tenancy-critical table, which will matter as soon as a membership deactivation or re-invite flow is implemented.
- **Evidence and reasoning**:
  - `database_schema` reports `business_members_business_id_user_id_unique`.
  - `database_query` on `pg_indexes` confirms: `CREATE UNIQUE INDEX business_members_business_id_user_id_unique ON public.business_members USING btree (business_id, user_id)`.
  - The migration matches the live DB, so this is not a migration drift; it is a decision / implementation mismatch.
- **Recommended direction**:
  - Either align the schema with D-061, or explicitly update the decision/docs to state that membership rows use restore-only semantics while providers allow historical duplicates.
- **Type**: maintainability

## STRENGTHS

- The tenant remediation is well-structured. `TenantContext`, `ResolveTenantContext`, and `BelongsToCurrentBusiness` materially reduce the cross-tenant risk surface and are backed by focused tests.
- The booking write-path hardening is real. The live schema confirms the Postgres exclusion guard exists, and the suite now contains race-simulation and overlap-constraint coverage instead of relying on optimistic re-checks alone.
- The reminder and auth-recovery remediations are disciplined. Both areas now have targeted tests for the exact failure modes REVIEW-1 called out.
- The public/customer timezone cleanup is complete and consistent: controllers now pass business timezone data and frontend formatting is centralized in `resources/js/lib/datetime-format.ts`.
- The R-15 cleanup work is coherent: storage URL generation is standardized, `axios` / `geist` are gone, and logo removal is normalized through a single model helper.

## TESTING OBSERVATIONS

- The current suite is healthy and remains a strong signal. The new test surface around tenant context, booking races, reminder semantics, after-response dispatch, and customer registration materially improves confidence in the remediated areas.
- Two important gaps remain around the findings above:
  - No test posts an opted-in onboarding schedule with **zero usable availability** and asserts launch is blocked.
  - No test creates a booking, then edits the underlying service buffers, and asserts the slot generator still matches the DB exclusion invariant.
- Browser-level coverage is still absent for the calendar mobile behavior and popup embed modal behavior. The code fixes are in place, but the remaining validation there is still manual, matching the carry-overs noted in `docs/HANDOFF.md`.

## OPEN QUESTIONS

- **Bookability enforcement strategy**: The follow-up remediation should choose a central definition of “bookable enough” and the right UX for each phase.
  Proposed directions from this review:
  - In onboarding, if the owner opts into being a provider, require at least one enabled availability window before allowing progression, so the initial setup cannot end in a structurally unbookable service.
  - After launch, do not necessarily hard-block the entire public business page when a provider has no slots for a temporary reason such as vacation or a full agenda. Instead, evaluate whether structurally unbookable services should be hidden or shown as unavailable, while surfacing a strong in-app admin alert/banner and, optionally, an admin notification.
  - Keep the distinction explicit between temporary lack of slots and structural misconfiguration; the follow-up agent should choose the best implementation boundary.
