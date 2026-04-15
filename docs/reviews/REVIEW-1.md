# REVIEW-1

## EXECUTIVE SUMMARY

This review was performed as a read-only assessment of the full repository after reading:

- `AGENTS.md`
- `docs/SPEC.md`
- `docs/ROADMAP.md`
- `docs/DECISIONS.md`
- `docs/UI-CUSTOMIZATIONS.md`

The review included direct inspection of backend, frontend, routing, configuration, migrations, factories/seeders, tests, and operational docs. I also used Laravel Boost to:

- confirm the installed stack and versions
- review database schema
- cross-check Laravel / Inertia guidance via official docs
- inspect browser logs and recent app logs

Additional verification:

- `php artisan test --compact` completed successfully: **379 tests passed, 1387 assertions**
- `npm run build` completed successfully, but Vite reported a **928.39 kB** main JS bundle before gzip and a large-chunk warning

Overall assessment:

- The project has a strong foundation, unusually clear product intent, and a genuinely solid scheduling core.
- The most serious problems are not random code smell. They cluster around a few systemic gaps:
  - the owner/admin role cannot actually operate as a provider, which breaks the solo-business path
  - tenant context is implicit rather than enforced
  - booking writes are not atomic
  - collaborator activation is not respected consistently
  - some customer-facing screens violate the documented timezone model
- The frontend is better than average structurally, but several responsive and correctness issues remain in the calendar and embed flows.

## STRENGTHS

- The product and architectural intent is exceptionally well documented. `docs/SPEC.md`, `docs/ROADMAP.md`, `docs/DECISIONS.md`, and `docs/UI-CUSTOMIZATIONS.md` make the target behavior explicit.
- The scheduling engine is one of the strongest parts of the codebase. `TimeWindow`, `AvailabilityService`, and `SlotGeneratorService` are readable and heavily tested.
- Transactional notifications are mostly modeled in the Laravel way: booking notifications implement `ShouldQueue` and call `afterCommit()`.
- The route map is coherent. The public catch-all booking route is registered last, and the route surface matches the product docs closely.
- The dashboard already uses useful Inertia patterns in several places:
  - server-side pagination
  - partial reloads for filters / calendar navigation
  - generated Wayfinder actions for many routes
- The test suite is broad. It covers availability, booking creation, notifications, settings, onboarding, and command flows well enough to act as a real behavioral signal.

## CRITICAL

### 1. Solo-business onboarding can complete successfully while producing an unbookable business

- Severity: CRITICAL
- Area / layer: Product flow, onboarding, role model, scheduling, public booking
- Affected files:
  - `app/Http/Controllers/Auth/RegisterController.php`
  - `app/Http/Controllers/OnboardingController.php`
  - `app/Http/Controllers/Dashboard/Settings/ServiceController.php`
  - `app/Services/SlotGeneratorService.php`
  - `app/Http/Controllers/Booking/PublicBookingController.php`
  - `resources/js/components/settings/service-form.tsx`
  - `resources/js/pages/onboarding/step-4.tsx`
- What I found:
  - Registration creates the business owner as `admin` only.
  - Onboarding step 2 configures business hours, not a provider schedule.
  - Onboarding step 3 creates a service without assigning any performer.
  - Onboarding step 4 is optional.
  - Service assignment UI only lists `business->collaborators()`, not admins.
  - Slot generation only considers `service->collaborators()`.
  - The public booking page still lists active services even when no eligible collaborator exists.
- Why it matters:
  - The product is explicitly aimed at any appointment-based professional, including solo operators.
  - A solo owner can finish onboarding and publish a booking page that advertises services but cannot actually produce slots.
  - The owner also cannot fix this cleanly from the current role model, because admins are excluded from service assignment and collaborator schedule management.
- Evidence and reasoning:
  - `RegisterController` attaches the new user with role `admin`.
  - `OnboardingController::storeService()` stores a service but never attaches a performer.
  - `ServiceController::create()` and `edit()` populate collaborator options from `business->collaborators()`.
  - `SlotGeneratorService::getEligibleCollaborators()` only reads the service-collaborator pivot.
  - `PublicBookingController::show()` exposes all active services, regardless of collaborator assignment.
  - The roadmap explicitly says step 4 invitations are optional.
- Recommended direction:
  - Decide explicitly whether admins can also be providers.
  - If yes, model that directly and make the owner bookable from day one.
  - If not, block launch until at least one active, scheduled provider is assigned to each public service.
  - Add an end-to-end test proving that a newly onboarded solo business can actually receive a booking.
- Issue type: correctness, product-fit, architecture

## HIGH

### 2. Tenant context is derived from “first attached business” instead of an explicit current-business scope

- Severity: HIGH
- Area / layer: Auth, authorization, tenancy boundaries, dashboard state
- Affected files:
  - `app/Models/User.php`
  - `app/Http/Middleware/EnsureUserHasRole.php`
  - `app/Http/Middleware/HandleInertiaRequests.php`
  - all dashboard / onboarding controllers that call `currentBusiness()` or `currentBusinessRole()`
- What I found:
  - `User::currentBusiness()` returns the first attached business.
  - `User::currentBusinessRole()` also derives role from the first attached business.
  - `EnsureUserHasRole` authorizes against any attached business, not an active one.
  - Nothing in the data model prevents a user from belonging to multiple businesses.
- Why it matters:
  - If a user ever belongs to more than one business, middleware authorization and controller scoping can diverge.
  - The request may be authorized because the user is an admin somewhere, while writes and reads hit whatever business happens to be returned first.
  - This is a classic cross-tenant correctness and security risk.
- Evidence and reasoning:
  - `business_user` has a uniqueness constraint on `(business_id, user_id)` but no constraint limiting a user to one business.
  - Controllers across dashboard, onboarding, settings, and shared props rely on `currentBusiness()` as if it were deterministic and explicit.
  - `CollaboratorController::updateSchedule()` amplifies the problem by deleting `availabilityRules()` via the user relation without business scoping.
- Recommended direction:
  - Either enforce single-business membership at the model / workflow level, or add explicit tenant selection and make all authorization tenant-aware.
  - Centralize business membership checks in policies / scoped query helpers rather than controller-by-controller `abort_unless` logic.
- Issue type: security, correctness, maintainability

### 3. Booking creation is still vulnerable to race conditions and can double-book a slot

- Severity: HIGH
- Area / layer: Public booking, dashboard manual booking, scheduling, persistence
- Affected files:
  - `app/Http/Controllers/Booking/PublicBookingController.php`
  - `app/Http/Controllers/Dashboard/BookingController.php`
  - `app/Services/SlotGeneratorService.php`
  - `database/migrations/2026_04_12_191019_create_bookings_table.php`
- What I found:
  - Both booking creation paths re-check slot availability in application code and then insert the booking.
  - There is no transaction or lock protecting the read-check-write sequence.
  - The bookings table has useful indexes, but no invariant that prevents overlapping bookings for the same collaborator.
- Why it matters:
  - This is the platform’s core write path.
  - Parallel requests can both pass the availability check and both create a booking.
  - The bug may stay invisible in tests yet surface in production during retries, slow requests, or concurrent booking attempts.
- Evidence and reasoning:
  - `PublicBookingController::store()` explicitly calls the second availability check “race condition protection”, but it is still only another read.
  - `Dashboard\BookingController::store()` repeats the same pattern.
  - There is no database-level conflict guard for collaborator/time overlap.
- Recommended direction:
  - Wrap availability validation plus booking creation in a transaction.
  - Use row locking or an equivalent persistence-level conflict guard on the collaborator/time window.
  - Add a concurrency test or at least a service-layer integration test around overlapping inserts.
- Issue type: correctness, performance

### 4. Deactivated collaborators remain bookable, assignable, and visible in booking flows

- Severity: HIGH
- Area / layer: Collaborator lifecycle, public booking, manual booking, scheduling
- Affected files:
  - `app/Http/Controllers/Dashboard/Settings/CollaboratorController.php`
  - `app/Services/SlotGeneratorService.php`
  - `app/Http/Controllers/Booking/PublicBookingController.php`
  - `app/Http/Controllers/Dashboard/CalendarController.php`
  - `app/Http/Controllers/Dashboard/BookingController.php`
- What I found:
  - The app stores deactivation on `business_user.is_active`.
  - The booking engine never filters eligible collaborators by that pivot flag.
  - Public collaborator lists and manual-booking collaborator lists also ignore it in several paths.
- Why it matters:
  - Deactivation should remove a collaborator from future scheduling.
  - Right now the UI toggle changes a flag, but the public booking engine still treats the collaborator as active.
- Evidence and reasoning:
  - `CollaboratorController::toggleActive()` updates the pivot.
  - `SlotGeneratorService::getEligibleCollaborators()` uses the service-collaborator pivot only.
  - `PublicBookingController::collaborators()` returns `service->collaborators()` with no business-user active filter.
  - `CalendarController` and booking pages load service collaborators without consistently filtering active members.
- Recommended direction:
  - Make “active collaborator” a first-class eligibility rule at the query source.
  - Filter by `business_user.is_active = true` in public collaborator lists, slot generation, manual booking, and assignment logic.
  - Add tests proving a deactivated collaborator disappears from public booking and cannot be auto-assigned.
- Issue type: correctness, maintainability

### 5. Customer-facing booking pages violate the documented timezone model

- Severity: HIGH
- Area / layer: Customer UX, correctness, time handling
- Affected files:
  - `app/Http/Controllers/Booking/BookingManagementController.php`
  - `app/Http/Controllers/Customer/BookingController.php`
  - `resources/js/pages/bookings/show.tsx`
  - `resources/js/pages/customer/bookings.tsx`
- What I found:
  - Booking-management and customer-bookings pages render stored ISO datetimes with `new Date(...).toLocale*()` in the browser.
  - The booking-management controller sends `business.timezone`, but the page ignores it.
  - The customer booking list does not receive timezone information at all.
- Why it matters:
  - The project’s documented rule is that customers see the business’s local time.
  - A customer in another timezone will see shifted appointment times, which is exactly the behavior the docs say the system should avoid.
- Evidence and reasoning:
  - `BookingManagementController` includes `business.timezone`.
  - `resources/js/pages/bookings/show.tsx` formats with plain `toLocaleDateString()` and `toLocaleTimeString()` without a timezone option.
  - `resources/js/pages/customer/bookings.tsx` does the same and never receives timezone data.
- Recommended direction:
  - Pass the business timezone through all customer-facing booking pages and use the existing timezone-aware formatting utilities consistently.
  - Add a frontend test or browser test for a non-local timezone viewer.
- Issue type: correctness, framework best-practice

### 6. Several validation paths accept cross-business IDs and trust the frontend to keep tenant boundaries intact

- Severity: HIGH
- Area / layer: Validation, settings, invitations, service assignment
- Affected files:
  - `app/Http/Requests/Dashboard/Settings/StoreSettingsServiceRequest.php`
  - `app/Http/Requests/Dashboard/Settings/UpdateSettingsServiceRequest.php`
  - `app/Http/Requests/Dashboard/Settings/StoreCollaboratorInvitationRequest.php`
  - `app/Http/Requests/Onboarding/StoreInvitationsRequest.php`
  - `app/Http/Controllers/Dashboard/Settings/ServiceController.php`
  - `app/Http/Controllers/Dashboard/Settings/CollaboratorController.php`
- What I found:
  - Service collaborator IDs are validated only with `exists:users,id`.
  - Invitation `service_ids` are validated only with `exists:services,id`.
  - Controllers then sync or persist those IDs without verifying business ownership.
- Why it matters:
  - A tampered request can attach unrelated users to a service or associate invitation service IDs from another business.
  - This weakens tenant isolation and makes data relationships depend on the honesty of the client.
- Evidence and reasoning:
  - The service settings UI and onboarding UI likely only show valid in-business choices, but the backend does not enforce that assumption.
  - The relevant Form Requests all return `authorize(): true` and perform only generic existence checks.
- Recommended direction:
  - Replace plain `exists` rules with business-scoped validation.
  - Re-check membership in the controller / service layer before syncing pivots.
  - Add tests that post a valid foreign `users.id` / `services.id` and confirm rejection.
- Issue type: security, correctness, maintainability

## MEDIUM

### 7. Collaborator-choice policy is enforced in the UI inconsistently and not enforced on the server

- Severity: MEDIUM
- Area / layer: Public booking flow, server/client responsibility split
- Affected files:
  - `app/Http/Controllers/Booking/PublicBookingController.php`
  - `resources/js/pages/booking/show.tsx`
- What I found:
  - The server exposes `allow_collaborator_choice`.
  - The public page usually respects it, but a pre-filtered service URL initializes the step to `collaborator` unconditionally.
  - The booking endpoint still honors a submitted `collaborator_id` as long as that collaborator is assigned to the service.
- Why it matters:
  - A business setting should be enforced server-side, not only by the happy-path UI.
  - Right now the flow can expose collaborator selection even when disabled, and crafted requests can still target specific collaborators.
- Evidence and reasoning:
  - `resources/js/pages/booking/show.tsx` initializes `step` to `'collaborator'` whenever a preselected service exists.
  - `PublicBookingController::store()` validates the collaborator only against the service relationship, not against `allow_collaborator_choice`.
- Recommended direction:
  - Enforce the business rule in the controller by ignoring or rejecting `collaborator_id` when collaborator choice is disabled.
  - Fix the page initialization so preselected services skip directly to date/time when collaborator choice is off.
- Issue type: correctness, framework best-practice

### 8. The calendar has a real DOM/hydration bug and weak mobile behavior

- Severity: MEDIUM
- Area / layer: Frontend, React, accessibility, responsive design
- Affected files:
  - `resources/js/components/calendar/week-view.tsx`
  - `resources/js/components/calendar/current-time-indicator.tsx`
  - `resources/js/components/calendar/calendar-header.tsx`
- What I found:
  - The current-time indicator returns an `<li>`.
  - In week view it is wrapped inside another `<li>`, producing invalid nested list markup.
  - Laravel Boost browser logs captured a hydration warning for this exact issue.
  - On small screens, the week view hides booking items (`hidden sm:flex`) and the calendar header hides the view switcher (`hidden md:block`) with no mobile replacement.
- Why it matters:
  - Invalid list nesting is not cosmetic; it triggers browser/React warnings and can destabilize hydration semantics.
  - The mobile calendar does not meet the roadmap’s “responsive behavior for smaller screens” requirement if users cannot switch views and the default week view hides events.
- Evidence and reasoning:
  - Browser log excerpt: `In HTML, <li> cannot be a descendant of <li>. This will cause a hydration error.`
  - `CalendarHeader` hides the view selector below `md`.
  - `WeekView` hides event elements below `sm`.
- Recommended direction:
  - Fix the week-view/current-time-indicator structure first.
  - Add an explicit mobile view-switcher.
  - Rework the mobile week view so bookings remain discoverable.
- Issue type: correctness, accessibility, maintainability

### 9. The main frontend bundle is needlessly large because all pages are eagerly imported

- Severity: MEDIUM
- Area / layer: Frontend performance, Inertia/Vite bundling
- Affected files:
  - `resources/js/app.tsx`
  - `vite.config.js`
- What I found:
  - The Inertia page resolver uses `import.meta.glob(..., { eager: true })`.
  - The production build emits a single main JS asset of **928.39 kB** before gzip and warns about large chunks.
- Why it matters:
  - Every user pays for code they do not need on first load.
  - This especially hurts public booking pages and auth screens, which should be the leanest parts of the app.
- Evidence and reasoning:
  - Build output:
    - `public/build/assets/app-BX6ZRx6V.js 928.39 kB`
    - Vite large-chunk warning
  - The eager page glob prevents route-level code splitting.
- Recommended direction:
  - Switch to lazy page loading and let Vite split the app per page boundary.
  - Re-measure after code-splitting before doing deeper component-level optimization.
- Issue type: performance, framework best-practice

### 10. The popup embed does not meet the documented feature set and behaves like a brittle modal

- Severity: MEDIUM
- Area / layer: Embed/widget, frontend behavior, accessibility
- Affected files:
  - `public/embed.js`
  - `resources/js/pages/dashboard/settings/embed.tsx`
  - `docs/SPEC.md`
  - `docs/ROADMAP.md`
- What I found:
  - The docs say both iframe and popup embeds support service pre-filtering.
  - The iframe preview does; the popup script does not.
  - `embed.js` reads only `data-slug`, not a service hint.
  - The popup implementation also lacks focus management, scroll locking, and a guard against duplicate overlays if the trigger is clicked repeatedly.
- Why it matters:
  - This is a documented product promise, not an optional enhancement.
  - The popup behaves like a visual overlay rather than a robust modal, which hurts keyboard users and embedded-site reliability.
- Evidence and reasoning:
  - `resources/js/pages/dashboard/settings/embed.tsx` changes the iframe URL when a service is selected, but the popup snippet remains static.
  - `public/embed.js` creates an overlay but never traps focus and does not prevent multiple `createOverlay()` calls.
- Recommended direction:
  - Extend the popup API to support service prefiltering explicitly.
  - Treat the popup as a proper modal: focus return, escape handling, scroll lock, and single-instance guard.
- Issue type: correctness, accessibility, maintainability

### 11. Reminder scheduling is fragile around missed scheduler windows and DST-sensitive expectations

- Severity: MEDIUM
- Area / layer: Scheduling, notifications, timezone correctness
- Affected files:
  - `app/Console/Commands/SendBookingReminders.php`
  - `routes/console.php`
  - `tests/Feature/Commands/SendBookingRemindersTest.php`
- What I found:
  - Reminder selection uses a narrow ±5 minute window around `now() + hours_before`.
  - It does not derive “24 hours before” from each booking’s business timezone.
  - Tests cover UTC happy paths, but not DST or delayed scheduler runs.
- Why it matters:
  - A delayed scheduler run can miss reminders entirely.
  - Around timezone offset changes, “24 hours before local appointment time” is not always equivalent to “24 UTC hours before `starts_at`”.
- Evidence and reasoning:
  - The command loops unique `reminder_hours`, computes UTC-ish windows from `now()`, and inserts `booking_reminders` rows immediately.
  - No test covers DST boundaries or backfill behavior.
- Recommended direction:
  - Calculate due reminders per booking/business timezone rather than assuming fixed-hour UTC arithmetic is enough.
  - Make the command resilient to delayed runs by using a safe backfill strategy.
- Issue type: correctness, operations, performance

### 12. Auth recovery endpoints are not rate-limited even though they send email and expose abuse surfaces

- Severity: MEDIUM
- Area / layer: Security, auth, operational abuse prevention
- Affected files:
  - `routes/web.php`
  - `app/Http/Controllers/Auth/MagicLinkController.php`
  - `app/Http/Controllers/Auth/PasswordResetController.php`
- What I found:
  - Login has request-level throttling.
  - Magic-link requests and password-reset requests do not.
- Why it matters:
  - These endpoints can be abused for email spam and noisy account probing.
  - Generic success messages help with enumeration, but they do not stop volume-based abuse.
- Evidence and reasoning:
  - Route list shows no throttle middleware for `POST /magic-link` or `POST /forgot-password`.
  - Laravel’s own security guidance favors throttling auth and public endpoints that can trigger side effects.
- Recommended direction:
  - Add rate limiters for magic-link and password-reset requests, segmented by IP and, where appropriate, email.
  - Add tests for throttle behavior similar to the existing login throttling test.
- Issue type: security, framework best-practice

### 13. Dashboard welcome links and onboarding copy have drifted from the actual route surface and behavior

- Severity: MEDIUM
- Area / layer: Frontend correctness, UX consistency
- Affected files:
  - `resources/js/pages/dashboard/welcome.tsx`
  - `resources/js/components/settings/collaborator-invite-dialog.tsx`
  - `app/Notifications/InvitationNotification.php`
  - `routes/web.php`
- What I found:
  - The welcome screen links to `/dashboard/settings/team` and `/dashboard/settings/notifications`, which do not exist.
  - The collaborator invite dialog says the invitation expires in 7 days, while backend behavior and notification copy say 48 hours.
- Why it matters:
  - Broken or misleading “next step” guidance damages trust immediately after onboarding.
  - Copy drift around invite expiry can create real support issues.
- Evidence and reasoning:
  - `routes/web.php` has no `/dashboard/settings/team` or `/dashboard/settings/notifications`.
  - `InvitationNotification` says 48 hours.
  - The invite controller stores `expires_at` as `now()->addHours(48)`.
- Recommended direction:
  - Replace hardcoded welcome links with real Wayfinder routes or remove them.
  - Make expiry copy derive from the actual configured invite lifetime.
- Issue type: correctness, maintainability

### 14. Customer account creation is narrower than the product docs describe

- Severity: MEDIUM
- Area / layer: Product behavior, auth, customer lifecycle
- Affected files:
  - `app/Http/Requests/Auth/CustomerRegisterRequest.php`
  - `app/Http/Controllers/Auth/CustomerRegisterController.php`
  - `app/Http/Controllers/Auth/MagicLinkController.php`
  - `resources/js/pages/auth/customer-register.tsx`
- What I found:
  - Customer registration requires the email to already exist in `customers`.
  - The customer register page explicitly tells users to use the email from an existing booking.
  - Magic-link creation also only works for users or customers already present in the system.
- Why it matters:
  - The spec describes optional customer registration as a general capability, not one limited to previously-booked customers.
  - The current behavior may be intentional, but it is a documented scope reduction and should be made explicit.
- Evidence and reasoning:
  - `CustomerRegisterRequest` uses `exists:customers,email`.
  - `CustomerRegisterController` assumes the customer record already exists.
  - `MagicLinkController::resolveUser()` only auto-creates users from existing customer records.
- Recommended direction:
  - Either update the product docs to reflect the current rule, or widen the flow so customers can register before their first booking.
- Issue type: correctness, product-fit

## LOW

### 15. Invitation and magic-link notifications still run on the request path, and outgoing mail defaults still leak Laravel branding locally

- Severity: LOW
- Area / layer: Notifications, branding, response time
- Affected files:
  - `app/Notifications/InvitationNotification.php`
  - `app/Notifications/MagicLinkNotification.php`
  - `config/app.php`
  - `config/mail.php`
- What I found:
  - Invitation and magic-link notifications are sent directly on the request path.
  - `config/app.php` still defaults app name to `Laravel`.
  - Local mail logs show `From: Laravel <hello@example.com>` and generic Laravel framing in auth and invite emails.
- Why it matters:
  - SMTP latency should not sit on the critical path for login or collaborator invite requests.
  - These two flows are interactive and user-triggered, so they benefit from being sent immediately after the response rather than waiting on a worker, but they still should not slow the HTTP response itself.
  - Branding drift is not catastrophic, but it weakens product polish and trust.
- Evidence and reasoning:
  - `Booking*Notification` classes are queued and treated as background system events; `InvitationNotification` and `MagicLinkNotification` are not.
  - Recent mail log entries still render Laravel defaults.
- Recommended direction:
  - Move invitation and magic-link delivery off the request path using a post-response mechanism such as Laravel's `background` or `deferred` execution model, instead of sending synchronously during the request.
  - Keep the booking-centric notifications on the real queue, since they benefit more from retryability and worker-based delivery.
  - Make branding defaults coherent even outside production-specific env files.
- Issue type: performance, maintainability, UX

### 16. Dependency and environment drift is starting to accumulate

- Severity: LOW
- Area / layer: Developer experience, upgrades, configuration hygiene
- Affected files:
  - `package.json`
  - `composer.json`
  - `app/Http/Controllers/Booking/PublicBookingController.php`
  - `app/Http/Controllers/Dashboard/BookingController.php`
  - `app/Http/Controllers/Dashboard/CalendarController.php`
  - `app/Http/Controllers/Dashboard/Settings/ProfileController.php`
  - `app/Http/Controllers/OnboardingController.php`
- What I found:
  - `axios` remains in `package.json` even though the codebase has moved to Inertia v3 `useHttp`.
  - `geist` remains in `package.json` even though it is no longer used.
  - `composer dev` still runs `php artisan serve`, while the project guidance and local usage also refer to Herd-hosted URLs.
  - Storage URLs are generated inconsistently: some controllers use `asset('storage/...')`, others use `Storage::disk('public')->url(...)`.
- Why it matters:
  - These are not release blockers, but they increase confusion and make host / asset behavior drift harder to reason about.
  - The mixed host story already shows up in local mail logs and docs.
- Evidence and reasoning:
  - Repository search found no runtime `axios` or `geist` imports.
  - `composer.json` still starts `php artisan serve`.
  - Controllers mix different storage URL helpers.
- Recommended direction:
  - Remove unused dependencies.
  - Standardize local-dev assumptions and URL generation.
  - Pick one storage URL strategy and use it consistently.
- Issue type: maintainability, operations

### 17. Logo removal leaves stale files behind and stores an empty string instead of a clean null state

- Severity: LOW
- Area / layer: File handling, settings/onboarding correctness
- Affected files:
  - `resources/js/pages/dashboard/settings/profile.tsx`
  - `resources/js/pages/onboarding/step-1.tsx`
  - `app/Http/Controllers/Dashboard/Settings/ProfileController.php`
  - `app/Http/Controllers/OnboardingController.php`
- What I found:
  - The UI supports removing a logo by clearing the hidden form value.
  - The update endpoints simply persist the validated payload.
  - Old files are deleted on replacement uploads, but not on removal.
- Why it matters:
  - The visible UI suggests a clean remove action.
  - The underlying file remains orphaned, and the stored value becomes an empty string rather than a null/no-logo state.
- Evidence and reasoning:
  - Replacement upload handlers explicitly delete the previous file.
  - The regular profile save path just updates the string field.
- Recommended direction:
  - Normalize “remove logo” to `null` and delete the existing file when the user clears it.
- Issue type: correctness, maintainability

## INFO

### ARCHITECTURAL OBSERVATIONS

- The repository has a strong “docs-first” posture, and that pays off. The problem areas are not from lack of intent; they come from a handful of model and boundary decisions that the implementation has outgrown.
- The biggest architectural mismatch is role modeling:
  - `admin` is treated as a business manager
  - `collaborator` is treated as the only kind of bookable provider
  - the product, however, clearly needs a viable one-person business path
- Authorization is scattered:
  - many Form Requests use `authorize(): true`
  - ownership checks live in controllers and middleware
  - policies are absent
  - that directly correlates with the tenant-scope misses in this review
- Public URL stability is weaker than the docs imply:
  - the slug is the platform’s public identifier
  - profile settings let admins change it
  - there is no redirect / alias history for old public URLs, embed snippets, or bookmarks
- The booking funnel is intentionally stateful in the browser:
  - that keeps the implementation simple
  - but it also makes refresh/back behavior more fragile than a server-backed draft or URL-state approach
- Duplication is noticeable and already causing drift:
  - onboarding profile vs settings profile
  - onboarding hours vs settings hours
  - public booking vs dashboard booking availability logic
  - manual booking vs public booking creation logic

### TESTING OBSERVATIONS

- Positive:
  - The availability and slot-generation tests are real tests, not scaffolding.
  - Booking creation, notifications, settings, and commands all have useful coverage.
  - The full suite passed during this review.
- Blind spots:
  - no test proves a newly onboarded solo business can actually receive bookings
  - no test covers multi-business user membership
  - no test covers deactivated collaborators disappearing from public booking
  - no browser-facing test covers the calendar hydration error or mobile behavior
  - no test covers popup embed service prefiltering
  - no test covers customer-facing timezone rendering

### QUICK WINS

1. Make the owner/provider path explicit, then add a single end-to-end test for “new business can take a booking”.
2. Filter active collaborators at the query source and add tests around deactivation.
3. Add tenant-scoped validation rules for collaborator IDs and service IDs.
4. Wrap booking creation in a transaction with a real conflict guard.
5. Fix customer-facing timezone rendering first, before adding more scheduling features.
6. Remove eager page imports and let Vite split the app.
7. Fix the calendar nested-`li` bug and add a mobile view-switcher.

### OPEN QUESTIONS

- Is an admin intended to be able to perform services, or should there be a distinct owner/provider concept?
- Is multi-business membership in scope for MVP, or should the app enforce one business per user?
- Should deactivation block login and booking visibility, or only exclude someone from assignment?
- Is the “existing customer only” account-registration rule intentional product scope, or an accidental narrowing from the spec?

## FINAL NOTES

- The project is not in bad shape. The core engine and test posture are much better than average.
- The highest-value fixes are concentrated and clear.
- If the critical and high-severity issues are addressed, the codebase is well positioned for a disciplined second pass on UX polish, performance, and future features like billing and calendar sync.
