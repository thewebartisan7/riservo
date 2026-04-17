# Frontend and UI Decisions

This file contains live decisions about the React/Inertia frontend stack, COSS UI usage, public booking presentation, and dashboard calendar UI behavior.

---

### D-032 — UI Library: COSS UI replaces VenaUI
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: VenaUI (`vena-ui`) availability could not be confirmed. The project needed a reliable, production-tested UI component library for the React/Inertia frontend.
- **Decision**: Use COSS UI (copy-paste, Tailwind CSS) instead of VenaUI (vanilla CSS, npm package). COSS UI is production-tested (used at Cal.com and coss.com), ships 60+ components built on Base UI primitives, and has first-class AI agent skill support (`pnpm dlx skills add cosscom/coss`). Tailwind CSS v4 is adopted as a consequence of this choice.
- **Consequences**: Tailwind CSS is now part of the frontend stack. Components are copied into the project rather than installed via npm — no UI library version to pin or upgrade. The "No Tailwind CSS" rule is reversed. Session 4 must install the COSS UI skill and set up Tailwind v4 before building any UI.

---

### D-033 — React i18n: useTrans() hook with Laravel JSON translations
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: P-002 asked how to handle translations in React. Options considered: react-i18next (full library) vs. a lightweight custom hook. The project uses Laravel's `__()` for PHP and needs an equivalent in React.
- **Decision**: `HandleInertiaRequests` middleware shares the current locale's JSON translation file (`lang/{locale}.json`) as an Inertia prop. A `useTrans()` hook reads translations from page props and returns a `t(key, replacements?)` function. `t()` mirrors `__()` behavior: falls back to the key itself, uses `:placeholder` replacement syntax. No i18n library dependency.
- **Consequences**: All React components use `const { t } = useTrans()` for translated strings. New translation keys are added to `lang/en.json`. Pre-launch, `lang/it.json`, `lang/de.json`, `lang/fr.json` provide translations per D-008.

---

### D-034 — COSS UI via shadcn CLI, components copied into project
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: COSS UI components need to be installed into the project. The CLI (`npx shadcn@latest`) handles dependency resolution, file placement, and transitive component imports.
- **Decision**: COSS UI was initialized with `npx shadcn@latest init @coss/style --template laravel`. This installed all 55 primitives into `resources/js/components/ui/`, the `cn()` utility into `resources/js/lib/utils.ts`, theme tokens into `resources/css/app.css`, and Inter font via `@fontsource-variable/inter`. The `components.json` config file maps aliases to the Laravel project structure.
- **Consequences**: Components are local files — no npm UI package to version. Future COSS updates can be applied by re-running `npx shadcn@latest add @coss/<component>` to overwrite individual files. The `geist` mono font package was removed (Next.js-only) — monospace font falls back to system defaults.

---

### D-047 — Booking layout with minimal riservo branding
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: The public booking page needs its own layout distinct from guest-layout, authenticated-layout, and onboarding-layout. The question was how much riservo branding to show.
- **Decision**: New `booking-layout.tsx` with a small riservo logo in the header and "Powered by riservo.ch" link in the footer. Business name and logo are displayed prominently. No navigation links, no sidebar.
- **Consequences**: Clean, professional customer-facing page. Business branding is primary. Similar to Cal.com and Calendly's approach.

---

### D-048 — Upgrade Inertia client to v3 for useHttp hook
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: The Inertia server adapter is already v3 (`inertiajs/inertia-laravel@3`). The client (`@inertiajs/react`) was v2. Session 7 needs standalone HTTP requests for slot availability and booking creation APIs. The `useHttp` hook (v3 only) provides reactive state management, automatic validation error parsing, and a consistent HTTP layer.
- **Decision**: Upgrade `@inertiajs/react` from ^2 to ^3. Remove the `bootstrap.js` file (axios setup, no longer needed). All new AJAX calls use `useHttp`. Existing `fetch()` calls in onboarding pages are outside scope but noted for future migration.
- **Consequences**: Access to `useHttp` with reactive `processing`, `errors`, `wasSuccessful` state. Axios dependency removed. React 19 requirement already met.

---

### D-058 — Calendar default view is week
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: The calendar supports day, week, and month views. A default is needed when navigating to `/dashboard/calendar` without a `view` parameter.
- **Decision**: Default view is `week`. Week view provides the best balance of detail and overview for daily business operations. This matches industry conventions (Google Calendar, Cal.com, Calendly).
- **Consequences**: The URL `/dashboard/calendar` renders the current week. View preference is not persisted — each navigation starts with week view unless a `view` query param is present.

---

### D-059 — Collaborator colors assigned from fixed palette by index
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: Admin calendar shows bookings from all collaborators, color-coded for visual distinction. Colors need to be assigned deterministically without database storage.
- **Decision**: A palette of 8 visually distinct colors is defined in `resources/js/lib/calendar-colors.ts`. Each collaborator is assigned `palette[index % 8]` based on their position in the collaborators array (sorted by ID).
- **Consequences**: Colors are consistent within a session but may shift if collaborators are added/removed/reordered. No database migration needed. Sufficient for MVP volumes (3-5 collaborators).

---

### D-060 — Calendar collaborator filter is client-side only
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: Admin calendar shows all collaborators' bookings. A filter lets the admin toggle visibility per collaborator. Two approaches: client-side filtering (hide/show in UI) or server-side filtering (re-query with selected collaborator IDs).
- **Decision**: Client-side filtering. All bookings for the date range are loaded via Inertia props. The collaborator toggle filter hides/shows bookings in the UI without a server roundtrip.
- **Consequences**: Fast toggle interaction (no network delay). Acceptable for MVP volumes. If a business has many collaborators with dense bookings, server-side filtering can be added post-MVP.

---

### D-069 — Mobile calendar week view falls back to agenda list; view switcher is always visible
- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: Pre-R-8, the dashboard calendar's week view was effectively unusable on phones. Booking events carried `hidden sm:flex` (week-view.tsx:179), so the 7-column time grid (which collapses to 1 column under `sm` per CSS Grid auto-placement) rendered empty. The view switcher was wrapped in `hidden md:block` (calendar-header.tsx:159), so phone users could not switch to day or month view either. Combined with D-058 (default view is week), a phone admin landing on `/dashboard/calendar` saw an empty grid with no escape. A separate hydration bug (nested `<li>` between `WeekView` and `CurrentTimeIndicator`) was already closed by commit `191c029`; this decision covers only the remaining mobile-rendering policy.
- **Decision**:
  1. **Mobile week view (< 640 px) renders an agenda list.** The 7-day header strip already at the top of `WeekView` (week-view.tsx:67-91) remains. Below it, the time grid is replaced by a vertically scrollable list of the week's bookings, grouped by day with date dividers, mirroring the existing pattern in `MonthView` (month-view.tsx:234-280). Each booking renders as a tappable card showing service, customer, and start/end time, and opens `BookingDetailSheet` on click — same handler as desktop. The time grid itself (`<ol>` at week-view.tsx:157) becomes `hidden sm:grid`; the agenda list becomes `block sm:hidden`.
  2. **The view switcher is always visible.** The `<Select>` in `CalendarHeader` (calendar-header.tsx:159-170) drops its `hidden md:block` wrapper. Width becomes `w-[110px] sm:w-[130px]` to keep the mobile header from overflowing.
  3. **D-058 is preserved.** Week remains the default view across viewports. The phone simply renders week as agenda; the URL `?view=week` is unchanged. A user explicitly choosing week from the switcher on mobile sees the same agenda layout.
  4. **Day view + month view are unchanged.** Both already have working mobile patterns (day view: mobile day strip + single-column time grid; month view: compact dot-indicator grid + selected-day agenda list). The audit confirmed no parallel issues.
  5. **The "New booking" button on mobile is deferred.** It remains `hidden md:inline-flex` and is captured as a BACKLOG item ("mobile calendar primary CTA"). R-8 is a bug-fix-and-mobile-rendering session, not a CTA redesign.
- **Consequences**:
  - Phone users get a usable week view (agenda) and can navigate views from the always-visible switcher.
  - The time-grid metaphor is preserved for tablet (`sm`) and up.
  - URL semantics are stable — no JS-driven view rewriting based on viewport.
  - The `BookingDetailSheet` mobile UX is exercised more often (sheets are mobile-friendly by COSS default; no change needed).
  - Future calendar work that touches week-view rendering must respect the agenda-vs-grid split at `sm`.
- **Rejected alternatives**:
  - *Auto-redirect mobile from `view=week` to `view=day`.* Would override the URL parameter, breaking deep-link sharing, analytics, and back-button semantics. Worse for the user who explicitly picked week.
  - *Compact event cards in the existing 7-column grid below `sm`.* 7 columns at 320 px = ~45 px wide each. Cannot fit any meaningful event metadata. Visually cramped.
  - *Swipe-driven horizontal week view (Google Calendar mobile).* Requires gesture infra (e.g., `react-use-gesture` or hand-rolled pointer events) that does not exist in this project. Out of scope for one screen.
  - *Hide week view entirely on mobile.* Removing a documented view based on viewport breaks D-058 and produces inconsistent navigation.
  - *Render the time grid in a horizontally-scrollable container on mobile.* Forces horizontal scroll, which is hostile UX for a calendar that should be glanceable. Also conflicts with the vertical scroll already needed for 24 hours.

---

### D-100 — `@dnd-kit/core` loaded only via a React.lazy() shell
- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Session 5 locked-decision #13 mandates `@dnd-kit/core` + `@dnd-kit/modifiers` for drag/resize on the dashboard calendar. The libraries add ~43 kB minified (~14 kB gzipped) of code that only runs on the calendar route. Loading them into the main bundle would tax every dashboard page (bookings list, settings, billing, customer directory) for code they never execute.
- **Decision**: `resources/js/components/calendar/dnd-calendar-shell.tsx` is the only module that statically imports `@dnd-kit/*`. The calendar page loads it via `React.lazy(() => import('@/components/calendar/dnd-calendar-shell'))`, wrapped in `<Suspense>` with a non-interactive fallback that renders the same view component without drag affordance. A companion `dnd-context.tsx` (no dnd-kit imports) provides a shared React context that the shell fills with `DraggableBooking` + `ResizeHandle` component factories; `CalendarEvent` reads them from context and falls back to pass-through when the shell hasn't resolved yet (or when outside the calendar route).
- **Consequences**:
  - Vite splits the shell into `dnd-calendar-shell-*.js` (43.24 kB raw / 14.33 kB gzipped) — under the 50 kB gzipped budget set in the plan.
  - A companion `dnd-context-*.js` chunk (317 kB raw / ~100 kB gzipped) carries the calendar page code and shared calendar components. This is a reshuffling of modules that were previously in the main bundle — not a net-new dependency. The main `app-*.js` bundle dropped from ~999 kB to ~689 kB.
  - `CalendarEvent` lives in the main graph but never synchronously references dnd-kit — drag is opt-in via context. On routes that never mount `<DndCalendarShell>`, the context keeps its pass-through defaults and dnd-kit stays unloaded.
  - A future calendar widget embedded elsewhere (e.g., a dashboard home "today" strip) inherits the same behaviour for free.
- **Rejected alternatives**:
  - *Static import in `calendar.tsx`.* Pushes dnd-kit into the main bundle (eager page glob at `app.tsx` line 13 imports every page).
  - *Per-component `React.lazy()` at `CalendarEvent` level.* Hundreds of Suspense boundaries, no clean Provider ownership.
  - *Module federation / separate Vite entry.* Overengineering for one library.

---

### D-101 — Drag preview uses `DragOverlay` with a faded source card
- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: `@dnd-kit/core` offers two drag-preview strategies: the source card visually translates with the cursor, or a `<DragOverlay>` element mirrors the card. The source-moves approach fights CSS grid positioning during partial moves and misrenders when the drag crosses days (the card tries to exist in two grid rows at once). The overlay approach is the conventional choice and matches Google Calendar.
- **Decision**: `DndCalendarShell` renders a `<DragOverlay dropAnimation={null}>` containing a compact preview card (service name + primary-ring highlight). The source card drops to `opacity: 0.4` while being dragged (via `useDraggable`'s `isDragging`). Cross-day drags track cleanly because the overlay lives outside the grid.
- **Consequences**: Two cards render during a drag — the source (faded) and the overlay (following cursor). The overlay is lightweight (no hover-card, no resize handle). Transition is visually honest about the in-progress operation.
- **Rejected alternatives**: *Source-moves*: misrenders across day boundaries. *Ghost-only (source hidden, overlay shown)*: loses the "this is where it was" anchor, making drop-location judgement harder.

---

### D-102 — Click-to-create does not seed `providerId` in MVP
- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Session 5 scope asks for "admin clicking a cell in another provider's column pre-selects that provider in the dialog". But the current dashboard calendar is a *combined* view — bookings from all visible providers render in the same time grid, color-coded by provider (D-059). There is no per-provider column, so a click has no column-level provider signal.
- **Decision**: Click-to-create seeds `{ date, time? }` only. The dialog's existing auto-assign branch handles provider selection. When per-provider columns land post-MVP (not scheduled), the click handler gains a `providerId` from the column's `data` attribute; the dialog already accepts a `providerId` seed through `ManualBookingDialogSeed`.
- **Consequences**: Admin and staff see the same click-to-create flow today. The dialog's auto-assign step remains useful.

---

### D-103 — Staff can create manual bookings via click-to-create (no header CTA)
- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Pre-MVPC-5, `ManualBookingDialog` was rendered only when `isAdmin` (dashboard/calendar.tsx:113). But the backend always accepted staff submissions: `dashboard.bookings.store` is inside the `role:admin,staff` outer group (routes/web.php:132), and `StoreManualBookingRequest::authorize()` is `tenant()->has()` (no role scope). The dialog gate was purely frontend-cosmetic.
- **Decision**: Drop the `isAdmin` guard on `<ManualBookingDialog>`. The header's "New booking" CTA (`CalendarHeader`) stays admin-only — staff's entry point is click-to-create on empty cells. Extends MVPC-4's self-service spirit (staff manage their own Account + Availability; now they can also log walk-ins for themselves).
- **Consequences**: Staff get a discoverable path to create manual bookings (on their own calendar view, which is already scoped to their own bookings server-side). The header CTA remains admin-only to keep the admin power-user experience intact. No server-side change — only the frontend guard was removed.

---

### D-107 — Resize handle is always visible at the event's bottom edge
- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Session 5 adds resize-to-change-duration on bookings in week + day views. Two handle UX options: hover-only (a thin bar revealed on mouse-over), or always-visible (a low-opacity strip that becomes prominent on hover).
- **Decision**: Always-visible, 8 px tall, low opacity at rest (`opacity-0 group-hover:opacity-60 hover:opacity-100`). Touch-friendly without a hover signal. Suppressed on the "tight" variant (< 4 grid rows ≈ 20 minutes) — the card is already smaller than the handle would be comfortable to grab, and the tight variant uses a click-to-popover pattern instead.
- **Consequences**: Resize is discoverable on touch devices (iPad, touch-screen laptops). Visual noise is bounded by the low default opacity. Tight events stay clean.
- **Rejected alternatives**: *Hover-only*: invisible on touch. *Always-full-opacity*: visual noise on the calendar grid.

---

### D-108 — Reschedule notification includes a manage-booking link
- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Session 5 locked-decision #16 requires a customer-facing notification when a booking is rescheduled from the dashboard. Two copy shapes considered: a minimal "time changed" note, or a mirror of `BookingConfirmedNotification` including the signed `bookings.show` management link.
- **Decision**: `BookingRescheduledNotification` mirrors `BookingConfirmedNotification`'s shape (Markdown panel with service / provider / previous / new date + time) and includes the signed management URL so the customer can cancel from the new time if it doesn't work for them. Subject: *"Your booking has been moved — {business}"*.
- **Consequences**: Consistent with the rest of the booking-lifecycle emails. Customers can self-manage without contacting the business. English-only at MVP; the existing `__()` pipeline handles i18n pre-launch.
- **Cross-reference**: Suppression guard lives at the dispatch site via `Booking::shouldSuppressCustomerNotifications()` (D-088). External `source = google_calendar` bookings cannot be rescheduled from the dashboard (D-105 refuses with 422), so the suppression guard is defence-in-depth.
