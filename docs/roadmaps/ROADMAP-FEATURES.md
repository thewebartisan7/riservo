---
name: ROADMAP-FEATURES
description: Feature roadmap (F1, F2, F3) - folded into ROADMAP-MVP-COMPLETION
type: roadmap
status: superseded
created: 2026-04-17
updated: 2026-04-17
supersededBy: roadmaps/ROADMAP-MVP-COMPLETION.md
---

# riservo.ch — Feature Roadmap

> **SUPERSEDED 2026-04-16** — Sessions F1, F2, F3 were folded into `docs/roadmaps/ROADMAP-MVP-COMPLETION.md` (Sessions 1, 4, 5 respectively) with definitive decisions locked. Read the active roadmap for current scope; this file is kept for historical context only.

> Version: 0.1 — Draft  
> Status: Superseded  
> Scope: Three focused feature sessions to be scheduled alongside or after the core MVP roadmap.  
> Each session represents a focused, reviewable unit of work with a clear deliverable.  
> Implementation details are defined per-session by the agent at the start of each session.

---

## Session F1 — Google OAuth Foundation (Pre-Session 12 prerequisite)

### Context

Session 12 (Google Calendar Sync, fully specified in `docs/plans/PLAN-SESSION-12.md`) requires `laravel/socialite` with the Google driver, `google/apiclient`, OAuth credentials wired into `config/services.php`, and a working redirect → callback round-trip before any sync logic can be built. Rather than front-loading all of that inside the already-heavy Session 12, this session installs and validates the plumbing in isolation so Session 12 can focus entirely on the sync business logic.

The session deliberately stops short of calendar sync. Its deliverable is a verified OAuth loop: a collaborator can click "Connect Google Account", be redirected to Google's consent screen, approve the scopes, and land back in the app with their tokens stored. Nothing more.

### Dependencies

- Session 5 (Authentication) must be complete — collaborator accounts and the `web` guard must exist.
- Session 9 (Business Settings) must be complete — the Settings area and `settings-layout.tsx` must exist so the Calendar Integration page has a home.
- Google Cloud Console OAuth 2.0 client must be created by the developer before this session runs (Web application type, redirect URI = `{APP_URL}/dashboard/settings/calendar-integration/callback`). `.env` must contain `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`.

### Deliverables

- [ ] `composer require laravel/socialite google/apiclient:"^2.15"` (or the Guzzle-based alternative evaluated per `ROADMAP-CALENDAR.md` — the agent must record the choice in the appropriate topical file listed in `docs/DECISIONS.md` before implementing)
- [ ] `config/services.php`: add `google` block reading from env (`client_id`, `client_secret`, `redirect`)
- [ ] `.env.example`: add `GOOGLE_CLIENT_ID=`, `GOOGLE_CLIENT_SECRET=`, `GOOGLE_REDIRECT_URI=`
- [ ] `database/migrations`: extend `calendar_integrations` with OAuth token columns if not already present (`access_token` encrypted, `refresh_token` encrypted, `token_expires_at`, unique index on `(user_id, provider)`)
- [ ] `CalendarIntegrationController` (stub): `index()` renders the Settings > Calendar Integration page; `connect()` redirects via Socialite with correct scopes (`calendar.events`, `openid`, `email`, offline access, consent prompt); `callback()` stores tokens in `CalendarIntegration` and redirects with a flash success message
- [ ] Settings > Calendar Integration page (`resources/js/pages/Dashboard/settings/calendar-integration.tsx`): minimal UI — not-connected state with a "Connect Google Calendar" button; connected state showing the linked Google account email and a "Disconnect" button; error banner slot for future use by Session 12
- [ ] `settings-layout.tsx`: add "Calendar Integration" nav item, visible to both `admin` and `collaborator` roles (per D-062 in `docs/plans/PLAN-SESSION-12.md`)
- [ ] Routes: OAuth connect/callback/disconnect under the admin+collaborator settings middleware group
- [ ] `disconnect()` action: deletes the `CalendarIntegration` record cleanly; no watch to stop yet
- [ ] Feature tests: connect redirects with correct Socialite scopes and `access_type=offline`; callback stores `CalendarIntegration` with encrypted tokens; disconnect deletes the record; both admin and collaborator can reach the page (200); unauthenticated users are redirected (302)
- [ ] `php artisan wayfinder:generate` after route additions
- [ ] `vendor/bin/pint --dirty`
- [ ] All existing tests remain green

### Notes for the agent

- Evaluate `google/apiclient` vs raw Guzzle per the explicit guidance in `ROADMAP-CALENDAR.md §HTTP client` and record the decision in the appropriate topical file listed in `docs/DECISIONS.md` before writing any code.
- Scopes must include `https://www.googleapis.com/auth/calendar.events` so Session 12 does not need a re-auth flow.
- The `CalendarIntegration` model already exists from Session 2 — do not recreate it; only extend it.
- The `disconnect()` route will need to call `stopWatch` in Session 12 once webhooks exist — leave a clear `// TODO Session 12: stop webhook watch here` comment.

---

## Session F2 — Collaborator Self-Service Account Settings

### Context

The current settings area is entirely admin-only. Collaborators can only manage their schedule if an admin does it on their behalf via the collaborator management section. This creates friction for day-to-day use: a collaborator who takes a sick day or wants a holiday cannot record it themselves.

This session opens a self-service sub-area in Settings for collaborators. It covers two things: (1) personal account management (profile, password, avatar) and (2) own availability management (weekly schedule, exceptions). Both capabilities already exist as admin-side features — this session exposes them as a self-service view scoped to the logged-in collaborator.

D-062 (in `docs/plans/PLAN-SESSION-12.md`) already anticipates the settings role split. This session implements it fully.

### Dependencies

- Session 5 (Authentication) — collaborator auth and role middleware must exist.
- Session 9 (Business Settings) — `settings-layout.tsx`, the admin collaborator schedule editor, and the exception management UI must all exist as reference implementations.
- Session 4 (Frontend Foundation) — COSS UI and layout conventions must be in place.

### Deliverables

**Role split in the settings area**

- [ ] Audit all current settings routes and pages; document which are admin-only and which should be accessible to collaborators — the agent must define this split explicitly in the plan before touching any code
- [ ] `settings-layout.tsx`: conditionally show nav items based on the authenticated user's role; admin-only items hidden from collaborators; collaborator-accessible items visible to both
- [ ] Route middleware: existing admin-only settings routes remain guarded; new collaborator-accessible routes added under a combined `role:admin,collaborator` group

**Collaborator account page**

- [ ] New route: `GET /dashboard/settings/account` → renders `Dashboard/settings/account.tsx`
- [ ] Accessible to both `admin` and `collaborator` roles
- [ ] Sections:
  - Profile: edit display name, email (with re-verification flow if email changes, consistent with existing email verification logic)
  - Password: change password (current password required); magic-link users who have no password yet should be able to set one for the first time
  - Avatar: upload/remove own avatar (reuses the immediate-upload endpoint pattern from D-042; the agent should evaluate whether to create a new endpoint or extend the existing one)
- [ ] Controller: `Dashboard\Settings\AccountController` — `show()`, `updateProfile()`, `updatePassword()`, `uploadAvatar()`
- [ ] Form requests with appropriate validation
- [ ] Feature tests: admin can update own profile; collaborator can update own profile; collaborator cannot update another user's profile; avatar upload stores file and returns URL; password update rejects wrong current password

**Collaborator own-availability page**

- [ ] New route: `GET /dashboard/settings/availability` → renders `Dashboard/settings/availability.tsx`
- [ ] Accessible to both `admin` and `collaborator` roles
- [ ] Admin sees their own availability (as a collaborator of the business); collaborator sees their own availability — the data scope is always `auth()->user()` within `currentBusiness()`
- [ ] Sections:
  - Weekly schedule editor: same UI as the admin-side collaborator schedule editor from Session 9 (`settings/collaborator-form.tsx` or equivalent), scoped to the current user — the agent should extract this into a reusable component if it is not already
  - Exceptions: add / edit / delete own availability exceptions (sick days, holidays, partial blocks, extra availability) — same UI as the admin exception editor from Session 9, scoped to the current user
- [ ] Controller: `Dashboard\Settings\AvailabilityController` — `show()`, `updateSchedule()`, `storeException()`, `updateException()`, `destroyException()`
- [ ] The schedule and exception logic must operate identically to the admin-side paths — no separate service or calculation code; reuse existing form requests and service calls, scoped to `auth()->user()`
- [ ] Feature tests: collaborator can update own schedule; collaborator can create/update/delete own exceptions; collaborator cannot edit another collaborator's schedule or exceptions via these routes; admin using this page edits their own data, not all collaborators

**Settings nav**

- [ ] `settings-layout.tsx` nav for collaborators shows: "Account" and "Availability" (and "Calendar Integration" from F1 once that session is done)
- [ ] Admin nav continues to show all existing sections plus "Account" and "Availability" for themselves
- [ ] `settings-layout.tsx` should derive visible items from role rather than hardcoding two separate nav lists — the agent should pick the simplest clean approach

### Notes for the agent

- Review the REVIEW-1.md observation about collaborator-choice policy (issue #7) — the availability page should not expose or modify any business-level settings; if the agent finds any scope creep during implementation, flag it and defer.
- Review issue #4 in REVIEW-1.md (deactivated collaborators) — a deactivated collaborator should not be able to access settings. The agent should verify that the existing `is_active` check is applied correctly to settings routes or add it if missing.
- Do not refactor admin-only settings controllers in this session. The goal is additive, not a settings rewrite.

---

## Session F3 — Advanced Calendar Interactions

### Context

The dashboard calendar built in Session 11 provides month, week, and day views adapted from TailwindPlus templates, with collaborator filtering and booking detail panels. It is functional but passive — users can only read it and navigate, not act on it directly.

This session transforms the calendar into an interactive workspace. The primary goals are: letting staff create a booking by clicking an empty slot, moving a booking by dragging it to a new time, adjusting a booking's duration by resizing its event block, and surfacing key booking info on hover without opening the full detail panel. Secondary goals are UX polish improvements that make the calendar feel production-quality.

REVIEW-1.md (issue #8) also surfaced two correctness problems in the current calendar that must be fixed as part of this session before adding new interactions: the nested `<li>` hydration bug in the current-time indicator, and the missing mobile view-switcher.

### Dependencies

- Session 11 (Calendar View) must be complete — the three-view calendar with TailwindPlus foundations must exist.
- Session 8 (Business Dashboard) must be complete — the booking detail panel and manual booking dialog must exist, as this session will reuse and extend them.
- Session 5 (Authentication) — role-based calendar scoping must be in place.

### Deliverables

**Bug fixes (required before new interactions)**

- [ ] Fix the nested `<li>` / `<li>` hydration error in `current-time-indicator.tsx` and `week-view.tsx` (REVIEW-1 issue #8)
- [ ] Add a mobile view-switcher so the calendar is usable on small screens — the agent should pick an appropriate pattern (bottom bar, dropdown, sheet — whatever fits the existing layout best)
- [ ] Verify week view booking items are visible on small screens or add a sensible mobile fallback (e.g., collapse to a list below the grid)

**Click-to-create booking**

- [ ] In week view and day view: clicking an empty time cell opens the manual booking dialog (built in Session 8) pre-populated with the clicked date and time
- [ ] In month view: clicking an empty day cell opens the manual booking dialog pre-populated with that date and time defaulting to the business's opening hour
- [ ] Admin: clicking an empty cell in another collaborator's column (if visible) should pre-select that collaborator in the dialog
- [ ] The existing manual booking dialog should be reused with minimal changes — the agent should evaluate whether pre-population is cleanest via props, context, or a small URL param approach

**Drag and drop — move a booking**

- [ ] In week view and day view: a booking event block can be dragged to a new time slot on the same day or a different day within the visible range
- [ ] On drop: validate that the new slot is available (call `AvailabilityService` via a lightweight JSON endpoint or reuse an existing one); show an inline error if the slot is taken; commit the change if valid
- [ ] Optimistic UI: move the event immediately on drop, revert on validation failure
- [ ] The agent must evaluate and document the drag library choice — options include `@dnd-kit/core` (recommended starting point given it is headless and SSR-safe), native HTML5 drag API, or another approach. Record the decision in the appropriate topical file listed in `docs/DECISIONS.md`.
- [ ] Collaborator view: collaborators can only drag their own bookings
- [ ] Month view drag is out of scope for this session (limited value, high complexity)

**Resize to change duration**

- [ ] In week view and day view: a resize handle at the bottom of a booking event block allows vertical drag to extend or shorten the appointment duration
- [ ] On resize end: validate the new duration against slot availability; show inline error on conflict; commit if valid
- [ ] Minimum resize unit: the service's `slot_interval_minutes`; the agent should decide whether to enforce this client-side, server-side, or both
- [ ] Optimistic UI consistent with drag-and-drop approach

**Hover mini-preview**

- [ ] Hovering a booking event in any view shows a small popover/tooltip with: customer name, service name, start–end time, and booking status chip
- [ ] The preview should appear after a short delay (250–400 ms) to avoid flicker during casual mouse movement
- [ ] The agent should use a COSS UI Popover or Tooltip component rather than a custom implementation
- [ ] Clicking through to the full detail panel must still work (hover state must not interfere with click)

**UX polish (the agent should evaluate, implement what is sensible, and skip what is not)**

- [ ] Today's date/column visually distinct in all three views (not just the current-time indicator)
- [ ] "Jump to date" quick-nav: a compact date picker that navigates directly to a selected date without stepping through weeks/months
- [ ] Keyboard navigation: arrow keys to move between dates in week/day view, `t` to jump to today — the agent should evaluate scope and implement what is low-effort and high-value
- [ ] Loading state for calendar navigation (partial reload skeleton or spinner so the grid does not flash blank during Inertia partial reloads)

**Backend endpoints required by new interactions**

- [ ] `PATCH /dashboard/bookings/{booking}/reschedule` — validates new `starts_at` + `ends_at`, checks availability, updates the booking; returns JSON (used by drag-and-drop and resize)
- [ ] The endpoint must respect the same availability rules as booking creation: only `pending`/`confirmed` bookings of the same collaborator block the new slot
- [ ] Dispatch `PushBookingToCalendarJob` on reschedule if Session F1 and Session 12 are complete (guard against missing integration gracefully if not)
- [ ] Feature tests: reschedule to a free slot succeeds; reschedule to an occupied slot returns a validation error; collaborator cannot reschedule another collaborator's booking; deactivated collaborator's bookings cannot be rescheduled via this endpoint

**Test coverage**

- [ ] Unit/feature tests for the `reschedule` endpoint covering success and conflict paths
- [ ] Existing calendar tests must remain green
- [ ] The agent should add at least one smoke test for the click-to-create path using the existing manual booking test infrastructure

### Notes for the agent

- Drag-and-drop and resize only apply in week and day views. Month view is read-only for interactions beyond click-to-create.
- The TailwindPlus calendar templates use fixed pixel heights per hour in week/day view — the agent must verify that the resize handle approach is compatible with this layout before committing to it.
- If `@dnd-kit` or a similar library is chosen, verify it does not bloat the bundle significantly (see REVIEW-1 issue #9 on the existing 928 kB bundle). Lazy-import the drag library so it only loads on the calendar route.
- The `reschedule` endpoint should send notifications consistent with the existing status-change notification rules from Session 10 — e.g., if rescheduling is material enough to warrant a "your booking has been updated" email, add it; if not, document the decision.

---

## Session ordering recommendation

```
Core MVP roadmap (Sessions 1–11, as per ROADMAP.md)
       │
       ├─▶ F1  Google OAuth Foundation     ← run after Session 9 (Settings exists)
       │        └─▶ Session 12  Google Calendar Sync  ← F1 is a hard prerequisite
       │
       ├─▶ F2  Collaborator Self-Service Settings  ← run after Session 9; independent of F1
       │
       └─▶ F3  Advanced Calendar Interactions  ← run after Session 11 (Calendar exists);
                                                   optionally after Session 12 if calendar
                                                   sync events should also be draggable
```

**Recommended sequencing rationale:**

- **F1 before Session 12** — this is a hard dependency. Session 12 (`docs/plans/PLAN-SESSION-12.md`) explicitly assumes Socialite and `google/apiclient` are already installed. Running F1 first removes a large chunk of plumbing from Session 12 and makes that session's scope manageable.
- **F2 is independent** — it only depends on Session 5 and Session 9. It can be scheduled any time after Session 9, in parallel with F1 if two agents are working.
- **F3 after Session 11** — the calendar views must exist before interactions can be added. F3 can run before or after Session 12; if it runs after, the agent should verify that Google-sourced external bookings are also draggable/previewable and update the scope if needed.
- **F3 and Session 12 are independent** — neither blocks the other. If Session 12 is delayed (e.g., Google Cloud Console setup is not ready), F3 can proceed without it.

---

*This document defines the WHAT and the sequencing. The HOW is decided per session by the agent in dedicated plan documents. Each session is independently shippable once its dependencies are met.*
