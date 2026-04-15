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
