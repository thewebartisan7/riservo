# Architecture Summary

This is the shortest current-state architecture overview for riservo.ch. Read this after `docs/README.md` when you need a quick mental model before diving into `docs/SPEC.md` or the topical decision files.

## Platform

- Laravel 13 backend on PHP 8.3
- Inertia v3 + React 19 + TypeScript frontend
- Tailwind CSS v4 with local COSS UI primitives
- Postgres 16 across all environments, managed on Laravel Cloud in production
- Laravel Cashier on the `Business` model for SaaS billing

## Core Domain

- A `Business` owns services, collaborators, business hours, booking settings, and the public slug
- Staff access is modeled through the `business_user` pivot with `admin` and `collaborator` roles
- Customers are stored separately from users and may or may not have linked auth accounts
- Bookings bind a customer, service, collaborator, and time window, with `pending` / `confirmed` states blocking availability

## Booking and Scheduling

- Public booking pages are served at `/{slug}`
- All stored datetimes are UTC; scheduling logic runs in the business timezone
- Availability is determined by business hours, collaborator rules, exceptions, buffers, and existing bookings
- Public booking uses a single Inertia page with JSON-powered step data loading
- Manual bookings reuse the same availability engine from the dashboard

## Frontend Structure

- App pages live under `resources/js/pages`
- Shared layouts live under `resources/js/layouts`
- COSS UI primitives live under `resources/js/components/ui`
- Brand-specific UI deviations are documented in `docs/UI-CUSTOMIZATIONS.md`

## Current Documentation Layout

- `docs/ROADMAP.md` is the primary roadmap
- `docs/DECISIONS.md` is the decision index, with detailed decisions split across `docs/decisions/`
- `docs/reviews/` contains the active remediation workflow
- `docs/archive/plans/` contains completed plan history
