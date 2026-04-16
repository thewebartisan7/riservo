# Architecture Summary

This is the shortest current-state architecture overview for riservo.ch. Read this after `docs/README.md` when you need a quick mental model before diving into `docs/SPEC.md` or the topical decision files.

## Platform

- Laravel 13 backend on PHP 8.3
- Inertia v3 + React 19 + TypeScript frontend
- Tailwind CSS v4 with local COSS UI primitives
- Postgres 16 across all environments, managed on Laravel Cloud in production
- Laravel Cashier on the `Business` model for SaaS billing (planned; not yet installed)

## Core Domain

- A `Business` owns services, providers, staff memberships, business hours, booking settings, and the public slug.
- **Staff** (dashboard access) is modelled through the `business_members` pivot with `admin` and `staff` roles (D-061). Role governs permission only, not bookability.
- **Providers** are a first-class entity (`providers` table, soft-deleted) — one row per bookable person per business. A staff member becomes a provider by opting in; an admin can be their own first provider (D-061, D-062).
- Customers are stored separately from users. A Customer row is globally unique by email; registration (D-074) creates or links a Customer to a User without requiring a prior booking.
- Bookings bind a customer, service, provider, and time window, with `pending` / `confirmed` states blocking availability. A Postgres `EXCLUDE USING GIST` constraint (D-065) prevents overlapping confirmed/pending bookings on the same provider.

## Booking and Scheduling

- Public booking pages are served at `/{slug}`; service pre-filter via a URL path segment (D-070): `/{slug}/{service-slug}`.
- All stored datetimes are UTC. Slot calculation and customer-facing rendering run in the business timezone.
- Reminder eligibility is calculated in the business's **wall-clock local time** (D-071), not fixed UTC offsets — DST-safe by construction.
- Availability is determined by business hours, provider rules, business- and provider-level exceptions, buffers, and existing bookings.
- Public booking uses a single Inertia page with JSON-powered step data loading.
- Manual bookings reuse the same availability engine from the dashboard.

## Auth and Tenancy

- Custom auth — no Fortify, no Jetstream (D-001).
- Magic links via `URL::temporarySignedRoute()` with one-time-use token column (D-037). Interactive notifications (`MagicLinkNotification`, `InvitationNotification`) dispatch via closure-after-response (D-075) — off the request path but with no queue dependency.
- Auth-recovery endpoints (`POST /magic-link`, `POST /forgot-password`) are throttled per-email AND per-IP via FormRequest (D-072).
- Per-request tenant context via `App\Support\TenantContext` (D-063). Foreign-key validation to business-owned data goes through the `BelongsToCurrentBusiness` rule (D-064).

## Frontend Structure

- App pages live under `resources/js/pages`.
- Shared layouts live under `resources/js/layouts`.
- COSS UI primitives live under `resources/js/components/ui`.
- Brand-specific UI deviations are documented in `docs/UI-CUSTOMIZATIONS.md`.
- Frontend→backend links use Wayfinder (imports from `@/actions/...` or `@/routes/...`); raw path strings are avoided.

## Current Documentation Layout

- `docs/ROADMAP.md` is the primary delivery roadmap.
- `docs/DECISIONS.md` is the decision index, with detailed decisions split across `docs/decisions/`.
- `docs/reviews/` holds the active review round only (empty between rounds). Closed rounds and their remediation roadmaps live under `docs/archive/reviews/`.
- `docs/archive/plans/` contains completed plan history.
