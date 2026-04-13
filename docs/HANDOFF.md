# Handoff

**Session**: 4 — Frontend Foundation (Inertia + React + COSS UI)  
**Date**: 2026-04-13  
**Status**: Complete

---

## What Was Built

Session 4 set up the complete frontend foundation: Inertia.js, React 19, TypeScript 6, and COSS UI with all 55 primitives.

### Server-Side

- **Inertia Laravel adapter** (`inertiajs/inertia-laravel` v3) installed
- **Root Blade template** (`resources/views/app.blade.php`) with `@viteReactRefresh`, `@vite()`, `@inertia`
- **HandleInertiaRequests middleware** sharing: `auth.user`, `flash.success/error`, `locale`, `translations` (lazy-loaded from `lang/{locale}.json`)
- **Middleware registered** in `bootstrap/app.php` (appended to web group)
- **Base translation file** (`lang/en.json`) with initial English strings

### Frontend Stack

| Package | Version | Purpose |
|---------|---------|---------|
| `react` + `react-dom` | ^19 | UI framework |
| `@inertiajs/react` | ^2 | Inertia React adapter |
| `@base-ui-components/react` | * | Base UI primitives (COSS dependency) |
| `typescript` | ^6.0 | Type checking |
| `@vitejs/plugin-react` | * | Vite React/JSX support |
| `@fontsource-variable/inter` | * | Inter font (COSS default) |

### COSS UI (`resources/js/components/ui/`)

All 55 COSS primitives installed via `npx shadcn@latest init @coss/style --template laravel`. Includes: Button, Sidebar, Avatar, Card, Input, Dialog, Select, Form, Table, Toast, and 45 more. Configuration in `components.json`.

### Translation Hook — `useTrans()` (`resources/js/hooks/use-trans.ts`)

Resolves P-002. `const { t } = useTrans()` mirrors PHP's `__()`:
- Reads translations from Inertia shared props
- Falls back to the key itself when no translation found
- Supports `:placeholder` replacements: `t('Welcome to :app', { app: 'riservo' })`

### Layouts

- **GuestLayout** (`resources/js/layouts/guest-layout.tsx`): centered card layout with riservo logo. Used for `/login`, `/register`.
- **AuthenticatedLayout** (`resources/js/layouts/authenticated-layout.tsx`): COSS Sidebar with navigation, user avatar in footer, SidebarTrigger in header. Used for `/dashboard`.

### Pages

| Route | Page Component | Layout |
|-------|---------------|--------|
| `/` | `pages/welcome.tsx` | None (full-page) |
| `/login` | `pages/auth/login.tsx` | GuestLayout |
| `/register` | `pages/auth/register.tsx` | GuestLayout |
| `/dashboard` | `pages/dashboard.tsx` | AuthenticatedLayout |

### Routes (`routes/web.php`)

4 Inertia routes using `Inertia::render()`. Named routes: `login`, `register`, `dashboard`.

---

## Current Project State

- **Backend**: 14 migrations, 10 models, 2 services (AvailabilityService, SlotGeneratorService), 1 DTO (TimeWindow)
- **Frontend**: Inertia + React 19 + TypeScript 6 + COSS UI (55 components) + Tailwind CSS v4
- **Tests**: 110 passing (236 assertions)
- **Build**: `npm run build` succeeds, `npx tsc --noEmit` passes
- **All 4 routes** return HTTP 200

---

## Key Conventions Established

- **TypeScript config**: single `tsconfig.json` with `@/*` path alias to `./resources/js/*`. No `baseUrl` (deprecated in TS 6).
- **Page resolution**: `import.meta.glob('./pages/**/*.tsx', { eager: true })` — page name in `Inertia::render('auth/login')` maps to `resources/js/pages/auth/login.tsx`
- **Translations**: `useTrans()` hook in React, `__()` in PHP. Keys stored in `lang/en.json`.
- **Layouts**: components that wrap page content. Each page imports its layout and renders as children.
- **COSS UI**: uses `render` prop for composition (not `asChild`). Example: `<Button render={<Link href="/login" />}>`
- **Vite**: React plugin + Tailwind v4 plugin + Laravel plugin. Entry: `resources/js/app.tsx`.
- **Fonts**: Inter via `@fontsource-variable/inter`. Geist Mono removed (Next.js only package). Monospace falls back to system fonts.
- **`withoutVite()`**: required in feature tests that hit routes rendering Inertia pages (Vite manifest not present in test environment)

---

## What Session 5 Needs to Know

Session 5 implements authentication. Key things:

- **Routes exist** for `/login`, `/register`, `/dashboard` but have no auth logic — they're placeholder Inertia renders
- **HandleInertiaRequests** already shares `auth.user` (currently always null since no auth yet)
- **GuestLayout** is ready for login/register forms
- **AuthenticatedLayout** shows user avatar/name from `auth.user` prop
- **No auth middleware** on `/dashboard` yet — Session 5 must add it
- **Form handling**: Inertia's `useForm()` hook should be used for auth forms. The placeholder pages currently use plain `<Input>` without form state.
- **COSS Form/Field primitives** are installed and available for building proper form fields with labels and validation errors
- **Named routes**: `login` is already named (Laravel's default redirect target for unauthenticated users)

---

## Decisions Recorded

- **D-033**: React i18n uses `useTrans()` hook with Laravel JSON translations via Inertia props (resolves P-002)
- **D-034**: COSS UI initialized via shadcn CLI, all 55 primitives copied into project

---

## Open Questions / Deferred Items

- **Geist Mono font**: removed because the npm package is Next.js-only. If monospace font styling matters, consider adding Geist Mono via CDN or `@fontsource/geist-mono` in a future session.
- **Code splitting**: Vite build warning about chunk size (582 KB JS). Not a problem for MVP but `import.meta.glob` with `eager: false` + `resolveComponent` can be used later for lazy loading.
- **Dark mode**: COSS theme includes `.dark` variant CSS. No toggle implemented yet — all pages render in light mode.
