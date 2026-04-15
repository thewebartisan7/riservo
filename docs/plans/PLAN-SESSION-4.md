# Session 4 Plan — Frontend Foundation (Inertia + React + COSS UI)

**Goal:** Install Inertia.js + React + TypeScript + COSS UI, create layout shells (sidebar dashboard + centered guest), placeholder pages, and the `useTrans()` translation hook (resolving P-002).

## Prerequisites

- 110 backend tests passing (verified)
- Tailwind CSS v4 already installed and configured
- COSS UI skill at `.agents/skills/coss/`

## Scope

**In:** Inertia server adapter, React 19, TypeScript, COSS UI init + 6 components (Button, Sidebar, Avatar, Separator, Input, Card), two layout components, 4 placeholder pages (`/`, `/login`, `/register`, `/dashboard`), `useTrans()` hook, shared Inertia props, routes

**Out:** Auth logic (Session 5), real page content, additional COSS components

## Implementation Phases

1. **Server-side Inertia** — composer install, root Blade template, HandleInertiaRequests middleware (shared props: auth, flash, locale, translations), register middleware
2. **Frontend packages** — npm install React, TypeScript, Inertia React, Base UI, Vite React plugin
3. **COSS UI** — `npx shadcn@latest init @coss/style` + `npx shadcn@latest add @coss/button @coss/sidebar @coss/avatar @coss/separator @coss/input @coss/card`
4. **TypeScript + Vite config** — tsconfig with `@/*` path alias, Vite React plugin, `.tsx`/`.ts` Tailwind sources
5. **React entry point + types** — `app.tsx` with `createInertiaApp`, `PageProps` type definitions
6. **Translation hook (P-002)** — `useTrans()` returns `t(key, replacements?)` mirroring `__()`
7. **Layouts** — `GuestLayout` (centered card) + `AuthenticatedLayout` (COSS Sidebar)
8. **Placeholder pages** — Welcome, Login, Register, Dashboard
9. **Routes + verification** — Inertia routes, Pint, tests, build, type check, manual browser test

## New Decisions

- **D-033:** i18n uses `useTrans()` hook with Laravel JSON translations via Inertia props (resolves P-002)
- **D-034:** COSS UI via shadcn CLI, components copied into `resources/js/components/ui/`

## Testing Plan

- All 110 backend tests must remain green
- `npm run build` succeeds
- `npx tsc --noEmit` passes
- All 4 pages render in browser
- Vite HMR works
- Inertia SPA navigation works between pages
