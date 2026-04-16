# riservo.ch — End-to-End Testing Roadmap (Pest + Playwright)

> Version: 0.1 — Draft  
> Status: Planning  
> Scope: Full end-to-end browser test coverage across all user-facing flows.  
> Tooling: Pest PHP with `pestphp/pest-plugin-browser` (Playwright under the hood, PHP test authoring).  
> Format: WHAT is defined here. HOW is decided by the agent at plan time.  
> Each session is a focused, reviewable unit of work with a clear deliverable.

---

## Overview

This roadmap introduces end-to-end (E2E) browser testing to riservo.ch. The existing test suite covers unit and feature tests at the HTTP layer. E2E tests complement that suite by driving a real browser through actual user journeys, covering UI interactions, multi-step flows, and integration between frontend and backend that HTTP-level tests cannot reach.

The target toolchain is **Pest PHP** with the official **`pestphp/pest-plugin-browser`** plugin, which wraps Playwright's browser automation in a PHP/Pest API. Tests are authored in PHP alongside the existing Pest suite. Node.js is required as a peer dependency (for Playwright's browser binaries) but test code remains entirely in PHP.

Sessions are ordered so that infrastructure and helpers are in place before functional flows, and core user journeys are covered before edge cases. Every session must leave the full test suite (unit + feature + E2E) green.

---

## Testing Guidelines

These guidelines apply to every E2E session. The implementing agent must follow them unless a session explicitly overrides a specific point and documents the rationale.

### Isolation
- Each test must be fully isolated. Database state must be reset between tests. The agent must evaluate whether `RefreshDatabase` or a per-test `migrate:fresh` approach is more appropriate for E2E speed and select one strategy at E2E-0 time. All subsequent sessions use the chosen approach.
- Tests must never depend on execution order or on data left by a previous test.
- No shared browser session state between tests unless a test explicitly sets it up.

### Setup via factories, not seeders
- Test preconditions must be built with factories in the test itself. The main database seeder is for development convenience and must not be the baseline for E2E tests.
- Repeated setup sequences (create a business, complete onboarding, etc.) must be extracted into helper methods or a shared `E2ESetup` class to avoid duplication. The agent must create these helpers in E2E-0 and expand them as needed.

### Test what the user sees
- E2E tests interact through the browser as a user would: clicking buttons, filling forms, following links. They must not call internal APIs or manipulate the database mid-test except for setup and assertion purposes.
- Assertions must check visible UI state (text, element presence, URL, toast/flash messages), not database records alone.

### Coverage contract
- Every named route in the application must be touched by at least one E2E test. The agent must maintain a route coverage checklist within the session plan.
- Every multi-step flow (wizard, booking funnel, invite acceptance) must have a complete happy-path test that walks every step.
- Each flow must also have at least one test covering a critical validation failure (required field missing, invalid format, conflicting state).
- Authorization boundaries must be tested: each protected route must be verified to redirect unauthenticated users and return 403 / redirect for roles that lack access.

### Page objects and helpers
- Repeated interactions (logging in, creating a business, completing the onboarding wizard to a known state) must be encapsulated as reusable page-object methods or helper functions. These live in `tests/Browser/Support/`.
- No copy-pasted login sequences across test files.

### Stability
- Tests must not use arbitrary `sleep()` or fixed timeouts. Use Playwright's built-in waiting mechanisms (wait for element, wait for navigation, wait for network idle) as exposed by `pest-plugin-browser`.
- Flaky tests must not be merged. If a test is non-deterministically failing due to timing, the agent must fix the root cause before closing the session.

### Failure artefacts
- On test failure, a screenshot and browser trace must be captured automatically. The agent must configure this in E2E-0 so it applies to all subsequent sessions.

### CI
- E2E tests run headless in CI. The agent must configure the appropriate CI script in E2E-0.
- E2E tests are separated from the unit/feature suite by a dedicated Pest group or directory so they can be run independently (`php artisan test --group=e2e`) without requiring a browser in standard unit test runs.

---

## E2E-0 — Infrastructure & Tooling Setup

This session has no functional tests as its output. Its deliverable is a working E2E testing foundation that every subsequent session builds on.

### What to deliver

- Install and configure `pestphp/pest-plugin-browser` and its Playwright binary dependency.
- Choose and document the database reset strategy for E2E tests (refresh per test, or transaction rollback — evaluate what `pest-plugin-browser` supports and choose the most reliable approach).
- Configure a dedicated test database (or schema) for E2E tests to avoid collisions with the feature test database.
- Configure `.env.testing` (or `.env.e2e`) with appropriate values: a running app server URL, E2E-specific database, mail driver set to `array`, queue driver set to `sync` so jobs run inline during tests.
- Configure automatic screenshot and trace capture on test failure.
- Create `tests/Browser/Support/` directory with the following initial helpers:
  - `BusinessSetup` — creates a `Business` + admin `User` via factories, with optional service and collaborator creation; returns the relevant models.
  - `AuthHelper` — logs in a user as admin, collaborator, or customer via the browser; encapsulates the login form interaction.
  - `OnboardingHelper` — drives the full onboarding wizard to completion from a freshly registered business account.
  - `BookingFlowHelper` — drives the public booking funnel from the business landing page through to the confirmation screen; parameterised to allow guest or registered customer flow.
- Add a `php artisan test --group=e2e` npm/composer script and document it.
- Configure CI (GitHub Actions or equivalent) to run E2E tests headless with Playwright's Chromium binary; cache the binary between runs.
- Verify the stack is working with one smoke test: open the app's root URL and assert the page loads (HTTP 200, correct `<title>`).

---

## E2E-1 — Authentication Flows

Covers all authentication entry points and role-based access enforcement.

### What to deliver

**Business registration**
- A new user completes the registration form (name, email, business name, password) and is redirected to the email verification notice.
- Registering with an already-used email shows a validation error.
- Submitting with missing required fields shows per-field validation errors.

**Email + password login**
- Admin logs in with valid credentials and lands on the dashboard (or onboarding wizard if onboarding is not complete).
- Login with wrong password shows an error.
- Login with an unverified email shows the appropriate notice.

**Magic link login**
- Admin requests a magic link: form submitted, success flash shown.
- Collaborator requests a magic link: same flow.
- (Magic link delivery is tested at the HTTP feature level; the E2E test covers the UI flow up to the success state and the magic link landing page if a signed URL can be generated in the test.)

**Password reset**
- Admin requests a password reset email, is shown the success screen.
- Admin lands on the reset form via a signed URL, sets a new password, is redirected to the dashboard.

**Collaborator invite acceptance**
- A collaborator receives an invite link (generated via factory + signed URL), lands on the set-password form, sets their password, and is redirected to the dashboard with their role confirmed as `collaborator`.
- An expired invite link shows the appropriate error screen.

**Route protection and role enforcement**
- Unauthenticated user visiting `/dashboard` is redirected to `/login`.
- Unauthenticated user visiting `/dashboard/settings` is redirected to `/login`.
- A `collaborator`-role user visiting an admin-only settings route receives a 403 or is redirected — the test asserts they do not see the admin page.
- A `customer`-role user cannot access the business dashboard.

**Logout**
- Admin clicks logout and is redirected to the login page; attempting to revisit `/dashboard` immediately after redirects back to login.

---

## E2E-2 — Business Onboarding Wizard

Covers the full 5-step onboarding wizard and its edge cases.

### What to deliver

**Complete happy-path wizard**
- A freshly registered and email-verified admin enters the wizard and completes all 5 steps in sequence:
  - Step 1: sets business name, description, contact info, and a valid slug; slug availability check shows the slug as available in real time.
  - Step 2: sets weekly working hours (at least two days enabled with time windows).
  - Step 3: creates a first service with name, duration, and price.
  - Step 4: skips the collaborator invite step.
  - Step 5: sees the public booking URL, copies the link (assert clipboard or button state), clicks "Go to Dashboard".
- After completion, the admin lands on the dashboard with a welcome state visible.

**Admin opts in as bookable provider**
- During onboarding, the admin chooses "Yes, I take bookings myself"; the weekly schedule form for their own availability appears and is completed.
- After completion, the admin's name appears in the collaborator picker on the public booking page.

**Slug availability check**
- Entering an already-taken slug shows an "unavailable" indicator; entering a free slug shows "available".
- Entering a reserved system slug (e.g., `login`, `dashboard`) shows an unavailable state.

**Collaborator invite during onboarding**
- Admin fills in a collaborator email on Step 4; after wizard completion, the invite record exists (assert via visible confirmation copy on Step 5 or via a subsequent dashboard page).

**Wizard resume**
- Admin completes Steps 1–2, closes the browser (session cookie persists), returns to the app, and is brought back to Step 3 — not Step 1.

**Validation errors**
- Attempting to advance from Step 1 with a missing business name shows a field error and does not navigate forward.
- Attempting to advance from Step 3 with no service created (if service creation is required to proceed) shows an appropriate error.

**Post-wizard state**
- After completion, revisiting `/onboarding` redirects the admin to `/dashboard` (wizard is not re-shown to a completed business).

---

## E2E-3 — Public Booking Flow

Covers the complete customer-facing booking funnel at `/{slug}`.

### What to deliver

**Business landing page**
- Visiting `/{slug}` shows the business name, description, and list of active services.
- Inactive services are not visible on the public page.
- Visiting `/{slug}/{service-slug}` pre-selects that service and skips (or fast-forwards) the service selection step.

**Full guest booking — happy path (collaborator choice enabled)**
- Service selection → collaborator selection (choose a specific collaborator) → date selection → time slot selection → customer details → summary → confirm.
- The confirmation screen shows the booking details, the customer's name, and a management link.
- Attempting to select a day with no available slots is not possible (greyed-out days cannot be clicked, or clicking shows no slots).

**Full guest booking — happy path (automatic collaborator assignment)**
- For a business with `allow_collaborator_choice = false`, the collaborator selection step is skipped entirely.
- Booking completes with a collaborator auto-assigned.

**"Any available" collaborator option**
- When `allow_collaborator_choice = true`, a customer selects "Any available"; the system assigns a collaborator and the confirmation shows who was assigned.

**No-availability UX**
- When no slots exist for the currently visible week, the "no availability this week" message is shown and the "next week" navigation shortcut is visible and functional.
- When a specific collaborator has no slots but others do, the "no availability for [Name] — view other collaborators" prompt is shown.

**Customer details validation**
- Submitting the details form with a missing required field (name, email, phone) shows per-field errors and does not advance.
- Submitting with an invalid email format shows a validation error.

**Booking confirmation and management link**
- After booking, the confirmation page shows the correct service name, collaborator, date, and time.
- The unique management link is present on the confirmation page.

**Customer booking management via token**
- Following the management link opens the booking detail page showing all booking fields.
- The cancel button is visible when the booking is within the cancellation window.
- Cancelling the booking shows a success confirmation and updates the booking status.
- After the cancellation window has passed, the cancel button is not shown.

**Rate limiting**
- Submitting the booking creation endpoint rapidly multiple times triggers the rate-limit response (HTTP 429 or appropriate UI feedback) — test can drive this via direct repeated form submission.

---

## E2E-4 — Dashboard: Bookings & Calendar

Covers the booking list, booking detail panel, status management, and calendar views.

### What to deliver

**Bookings list**
- Admin visits the bookings list and sees bookings across all collaborators.
- Collaborator visits the bookings list and sees only their own bookings.
- Filtering by status (`confirmed`, `pending`, `cancelled`, `completed`, `no_show`) updates the list.
- Filtering by collaborator (admin only) updates the list.
- Clicking a booking row opens the booking detail panel.

**Booking detail panel**
- The panel shows: customer name, email, phone, service, collaborator, date/time, status, and notes.
- External bookings (source = `google_calendar`) show "External Event" in place of customer details.

**Status transitions**
- Admin confirms a `pending` booking; status chip updates to `confirmed`.
- Admin cancels a `confirmed` booking; status chip updates to `cancelled`.
- Admin marks a booking as `no_show`; status chip updates accordingly.
- Collaborator can see their own bookings but cannot change status on admin-only actions (assert button is absent or action is blocked, per SPEC permissions).

**Manual booking creation**
- Admin opens the manual booking dialog from the dashboard, fills in customer name, email, service, collaborator, date, and time, and submits.
- The new booking appears in the list.
- Creating a manual booking for an already-occupied slot shows an availability error.

**Calendar — views and navigation**
- All three views (day, week, month) render without JS errors.
- Navigation buttons (prev, today, next) change the visible date range correctly.
- A booking created via factory appears in the correct position in the week and day views.
- Clicking a booking in the calendar opens the detail panel.

**Calendar — admin collaborator filter**
- Admin can toggle individual collaborators on and off; hidden collaborators' bookings disappear from the view.
- Color coding by collaborator is visible in the combined admin view.

**Calendar — collaborator scope**
- A collaborator logs in; the calendar shows only their own bookings and the filter controls are absent.

**Current time indicator**
- In the day and week views, the current time indicator line is visible when the current time falls within the visible range.

---

## E2E-5 — Dashboard: Settings

Covers all settings sections accessible to admins, and the collaborator self-service settings where applicable.

### What to deliver

**Business profile**
- Admin edits business name, description, phone, and email; saves; changes persist on page reload.
- Admin uploads a logo image; the logo is shown in the settings page preview.
- Admin removes the logo; the logo reverts to the default state.
- Admin changes the business slug; the new public URL is shown and the old URL no longer resolves to the same page.

**Booking settings**
- Admin toggles confirmation mode from auto to manual; saves; the setting persists.
- Admin toggles collaborator choice; saves; the public booking page reflects the change (collaborator step shown or hidden).
- Admin changes the cancellation window; saves; the new window is enforced on the customer management page.

**Business hours**
- Admin disables a day of the week; the public booking calendar greys out that day.
- Admin adds a second time window to a day (e.g., morning and afternoon); the public booking page offers slots in both windows.

**Business exceptions**
- Admin adds a full-day closure exception for a future date; that date appears as unavailable on the public booking page.
- Admin edits the exception; the change persists.
- Admin deletes the exception; the date becomes available again.

**Services**
- Admin creates a new service with all fields populated; the service appears on the public booking page.
- Admin edits an existing service (changes price and duration); the updated values appear on the public page.
- Admin deactivates a service; it disappears from the public booking page.
- Admin assigns a collaborator to a service; the collaborator appears as eligible in the booking flow for that service.
- Admin removes a collaborator from a service; the collaborator is no longer offered for that service in the booking flow.

**Collaborators**
- Admin invites a new collaborator by email; the invite appears in the collaborators list with a "pending" state.
- Admin edits a collaborator's weekly schedule; the collaborator's availability is updated in the booking flow.
- Admin adds an exception for a collaborator (full-day absence); that collaborator has no slots on the exception date.
- Admin uploads an avatar for a collaborator; the avatar is shown in the collaborator picker on the public page.
- Admin deactivates a collaborator; the collaborator no longer appears in the booking flow.

**Embed & Share**
- The iframe snippet is displayed; the copy button copies the snippet to the clipboard (or asserts the copy action triggers correctly).
- The popup JS snippet is displayed; the copy button works.
- The live preview renders the booking form within the settings page.

**Calendar Integration (Google)**
- The Calendar Integration settings page loads for both admin and collaborator roles.
- Unauthenticated access to the settings page redirects to login.
- The "Connect Google Calendar" button is visible when no integration exists.
- The connected state (integration exists in the database) shows the linked account email and a disconnect button.

---

## E2E-6 — Embed Modes & Cross-Cutting Concerns

Covers the widget embed modes, authorization edge cases, and accessibility basics.

### What to deliver

**Iframe embed mode (`?embed=1`)**
- Visiting `/{slug}?embed=1` strips the site navigation and adapts the layout for iframe embedding.
- The full booking funnel completes correctly in embed mode.
- Service pre-filter via URL (`/{slug}/{service-slug}?embed=1`) works in embed mode.

**Popup embed mode**
- The JS popup snippet (copied from Embed & Share settings) can be injected into a test HTML page; clicking the trigger button opens the booking form in a modal overlay.
- The modal overlay closes after booking completion or on explicit dismissal.
- Service pre-filter via URL param works in popup embed mode.

**Authorization edge cases**
- A collaborator from Business A cannot access Business B's dashboard, even with a valid session.
- A customer cannot view another customer's booking management page (different token).
- A booking management link with a tampered or expired token shows an appropriate error page.

**Accessibility — keyboard navigation in booking funnel**
- All booking funnel steps can be completed using keyboard navigation only (Tab, Enter, Space, arrow keys for date picker).
- Focus is managed correctly when a booking step transitions to the next (focus moves to the top of the new step, not left behind on the previous).

**Accessibility — focus management in onboarding wizard**
- Step transitions in the onboarding wizard move focus to the top of the incoming step.
- The progress indicator is correctly accessible (announced to screen readers if applicable).

**Timezone display**
- A booking created in a business whose timezone is set to `Europe/Zurich` shows the correct local time on both the confirmation screen and the customer management page, regardless of the test runner's system timezone.

---

## Session ordering recommendation

```
E2E-0  Infrastructure & Tooling Setup
  │
  ├─▶ E2E-1  Authentication Flows          ← depends on E2E-0 helpers
  │
  ├─▶ E2E-2  Onboarding Wizard             ← depends on E2E-1 (registered user needed)
  │
  ├─▶ E2E-3  Public Booking Flow           ← depends on E2E-2 (needs a launched business)
  │
  ├─▶ E2E-4  Dashboard: Bookings & Calendar ← depends on E2E-2
  │
  ├─▶ E2E-5  Dashboard: Settings           ← depends on E2E-2
  │
  └─▶ E2E-6  Embed Modes & Cross-Cutting   ← depends on E2E-3, E2E-5
```

E2E-4 and E2E-5 are independent of each other and can be run in parallel by two agents. E2E-3 can begin once E2E-2 is complete. E2E-6 requires both E2E-3 (for the booking flow in embed mode) and E2E-5 (for the embed snippet).

---

## Notes for the implementing agent

- The `pestphp/pest-plugin-browser` plugin requires Playwright's Chromium binary. The agent must verify the binary can be installed in the CI environment and document the exact install command in `E2E-0`.
- The existing Pest configuration in `phpunit.xml` or `pest.php` must not be modified in ways that break the existing unit/feature suite. E2E tests must be in a separate group or directory that the default `php artisan test` run can optionally include or exclude.
- Tests that require a running HTTP server (i.e., all browser tests) must use the app server started by `pest-plugin-browser` or an equivalent Artisan serve configuration — not assumptions about an externally running process.
- Google Calendar OAuth (Session 12) is not in scope for E2E testing of the actual OAuth round-trip with Google. The E2E-5 test for Calendar Integration covers the UI states (connected / disconnected) using factory-seeded `CalendarIntegration` records. The actual OAuth flow is validated at the feature test level.
- Every session must end with `php artisan test` (full suite, including E2E) green and `npm run build` clean.

---

*This roadmap defines the WHAT. The HOW — browser interaction strategy, helper design, CI configuration, and any Playwright-specific options — is decided by the agent at plan time. Active session plans live in `docs/plans/`; completed plans move to `docs/archive/plans/`.*
