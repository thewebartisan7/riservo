# riservo.ch — Roadmap (MVP)

> Version: 0.1 — Draft  
> Status: In Progress  
> Each session represents a focused, reviewable unit of work with a clear deliverable.  
> Implementation details are defined per-session by the agent at the start of each session.

---

## Session 1 — Laravel 13 Fresh Install & Project Setup

Starting point: fresh Laravel 13 installation (no starter kit) provided by the developer.

- [x] Configure `.env` for local development (SQLite, mail, app settings)
- [x] Set up directory structure conventions (Services, DTOs, Enums, etc.)
- [x] Configure `composer.json` with useful dev dependencies (Pint, Larastan, etc.)
- [x] Set up Laravel Pint for code style
- [x] Configure PHPUnit / Pest for testing
- [x] Commit baseline with CI-ready structure

---

## Session 2 — Data Layer (Models + Migrations + Seeders)

- [x] Create migrations, models, and factories for all core models (D-026: used artisan instead of Blueprint):
  - `Business` (include `timezone` field, default `Europe/Zurich`), `User` (modified), `BusinessUser` (pivot with roles)
  - `BusinessHour` (business-level weekly open/close schedule)
  - `Service` (include `slug` field), `CollaboratorService` (pivot)
  - `Customer`, `Booking`
  - `AvailabilityRule`, `AvailabilityException`
  - `CalendarIntegration`
- [x] Review and adjust migrations (indexes, constraints, enums)
- [x] Write seeders:
  - 1 Business ("Salone Bella") with realistic data and unique slug
  - 4 Staff (1 admin + 3 collaborators) with different weekly schedules
  - 5 Services with varying durations, prices, buffer_before/after
  - Business-level and collaborator-level availability rules and exceptions
  - 10 Bookings across all statuses (confirmed, pending, completed, cancelled, no_show, manual)
- [x] Verify all relationships and factories work correctly (57 tests passing)
- [x] Confirm SQLite compatibility for all migrations

---

## Session 3 — Scheduling Engine (TDD)

Core service that calculates available booking slots. Built test-first.

- [x] Write unit tests covering:
  - Basic slot generation from a weekly schedule
  - Correct application of `buffer_before`, `buffer_after`, and per-service `slot_interval`
  - Business-level exception blocks full day and partial day
  - Collaborator-level exception blocks full day and partial time window
  - Collaborator-level open exception extends availability
  - Existing bookings (with buffers) correctly block slots
  - Slot calculation respects business timezone (UTC storage, local display)
  - Edge cases: back-to-back bookings, midnight boundary, slot at exact closing time, DST transitions
  - Automatic collaborator assignment (first-available and round-robin strategies)
- [x] Implement `AvailabilityService` until all tests pass
- [x] Implement `SlotGeneratorService` (takes rules + exceptions + bookings → returns available slots)
- [x] Write integration test: full slot calculation for a realistic business scenario using seeded data
- [x] No UI in this session — engine only

---

## Session 4 — Frontend Foundation (Inertia + React + COSS UI)

- [x] Install and configure Inertia.js (server-side Laravel adapter)
- [x] Install and configure React + TypeScript
- [x] Install COSS UI skill: `pnpm dlx skills add cosscom/coss` (already installed — `.agents/skills/coss` + `.agents/skills/coss-particles`)
- [x] Set up Tailwind CSS v4 (required by COSS UI)
- [x] Copy initial COSS UI components into the project (via `npx shadcn@latest init @coss/style` — all 55 primitives installed)
- [x] Set up Vite with HMR for local development
- [x] Create root layout shell (authenticated and guest variants) using COSS UI components
- [x] Set up shared Inertia page props (auth user, flash messages, etc.)
- [x] Create placeholder pages: `/`, `/login`, `/register`, `/dashboard`
- [x] Confirm Inertia routing works end-to-end with a basic page render

---

## Session 5 — Authentication

Custom auth implementation — **no Laravel Fortify, no Jetstream**.

- [ ] Business owner registration (name, email, password, business name) + email verification
- [ ] Email + password login and logout for business owners and collaborators
- [ ] Magic link login for business owners and collaborators (alternative to password, `URL::temporarySignedRoute()`, one-time use, 15–30 min expiry)
- [ ] Magic link as default auth for customers (no password required)
- [ ] Optional password-based registration for customers who prefer it
- [ ] Password reset via email
- [ ] Collaborator invite flow: owner sends invite link → collaborator sets password on first login
- [ ] Role middleware: `admin`, `collaborator` (business-scoped), `customer` (separate auth context, own bookings only) — owner/admin distinction deferred to v2
- [ ] Guest booking management via signed URL token (no login required)
- [ ] Route protection and redirect logic per role
- [ ] All auth pages built in React with COSS UI components

---

## Session 6 — Business Onboarding Wizard

Distraction-free wizard shown to a business owner after registration. Completed before the dashboard is accessible for the first time.

- [ ] Step 1 — Business profile: name, description, logo upload, contact info, slug (with live availability check)
- [ ] Step 2 — Working hours: set weekly schedule (days + time windows) with a clean visual interface
- [ ] Step 3 — First service: add at least one service (name, duration, price, buffers, slot interval)
- [ ] Step 4 — Invite collaborators (optional, skippable): invite by email, assign to service
- [ ] Step 5 — Summary & launch: show public booking URL, copy link, go to dashboard
- [ ] Wizard state persisted server-side (resumable if interrupted)
- [ ] Progress indicator (step X of Y)
- [ ] Smooth transitions between steps, no distractions (no main nav visible)
- [ ] On completion, redirect to dashboard with a welcome state

---

## Session 7 — Public Booking Flow

The customer-facing booking experience at `riservo.ch/{slug}`.

- [ ] Business public page: name, description, list of active services
- [ ] Service selection step
- [ ] Collaborator selection step (shown only if business allows it; "Any available" option; collaborator avatar displayed if set)
- [ ] Date picker: calendar showing available dates (days without slots greyed out, not hidden)
- [ ] Time slot picker: available slots for selected date, generated by `AvailabilityService` in business timezone
- [ ] UX for no availability: "No availability this week" message with forward navigation; suggest other collaborators if applicable
- [ ] Customer details form: name, email, phone, optional notes; server-side honeypot field
- [ ] Booking summary and confirmation step
- [ ] Booking creation: guest customer record created, basic queued confirmation email sent (placeholder — no template or styling; Session 10 replaces with the real implementation)
- [ ] Booking confirmation page with details and unique management link
- [ ] Customer booking management via token: view details, cancel (within cancellation window)
- [ ] Pre-filter by service via URL param (`/{slug}/{service-slug}`)
- [ ] Rate limiting on public routes: slot availability API, booking creation endpoint
- [ ] All strings use `__()` helper (English base, translated pre-launch)

---

## Session 8 — Business Dashboard

The main authenticated view for business owners and collaborators.

- [ ] Dashboard home: today's appointments summary, quick stats (bookings this week, upcoming)
- [ ] Calendar view (day / week / month) showing bookings
  - Admin: all collaborators, with filter by collaborator
  - Collaborator: own bookings only
- [ ] Booking detail panel (slide-over or modal): full booking info, status actions
- [ ] Change booking status: confirm, cancel, no-show, complete
- [ ] Add internal note to a booking
- [ ] Bookings list view with filters (date range, collaborator, service, status)
- [ ] Manual booking creation from dashboard:
  - Search and select existing customer or create new
  - Select service, collaborator, date, time slot (using AvailabilityService)
  - Confirm and notify customer
- [ ] Customer Directory (CRM):
  - List all customers with at least one booking
  - Search by name, email, phone
  - Customer detail: contact info, booking history, stats (total visits, last visit)

---

## Session 9 — Business Settings

Full settings area for managing the business configuration.

- [ ] Business profile editing (name, description, logo, contact info, slug)
- [ ] Booking settings: confirmation mode, collaborator selection toggle, cancellation window, payment mode
- [ ] Business-level working hours editor (update after onboarding)
- [ ] Business-level exceptions: add/edit/delete closures and special hours
- [ ] Service management: create, edit, deactivate services (name, duration, price, buffer_before, buffer_after, slot_interval, assigned collaborators)
- [ ] Collaborator management:
  - View all collaborators
  - Invite new collaborator by email
  - Edit collaborator's weekly schedule
  - Add/edit/delete collaborator exceptions (absences, partial blocks, extra availability)
  - Collaborator avatar upload (optional, with fallback to generated initials)
  - Deactivate collaborator
- [ ] Embed & Share settings:
  - `?embed=1` param: strips navigation, adapts layout for iframe embedding
  - Popup embed: JS snippet (`<script>`) that opens booking form in a modal overlay on the business's own website
  - Both embed modes support service pre-filter via URL param
  - Copy buttons for iframe snippet and JS popup snippet
  - Live preview of the embedded form

---

## Session 10 — Notifications (Email)

Transactional email system for all booking lifecycle events.

- [ ] Configure transactional email provider (Mailgun or Postmark via Laravel Mail)
- [ ] Email templates in React Email or Blade (consistent branding):
  - Booking confirmed (to customer)
  - Booking confirmed (to collaborator)
  - New booking received (to business/collaborator)
  - Booking cancelled by customer (to business + collaborator)
  - Booking cancelled by business (to customer)
  - Booking reminder (to customer — 24h before and/or 1h before, configurable)
- [ ] Reminder scheduling via Laravel queues + scheduled jobs
- [ ] Queue setup for async email delivery
- [ ] Scheduled job: automatically transition confirmed bookings to `completed` status after their end time has passed
- [ ] Test all email flows end-to-end using seeded data

---

## Session 11 — Billing (Laravel Cashier)

SaaS subscription management for business accounts.

- [ ] Install and configure Laravel Cashier (Stripe) on the `Business` model
- [ ] Define subscription plans in config (monthly, annual)
- [ ] Trial mode: new businesses start on indefinite trial (no card required at signup)
- [ ] Billing portal page in dashboard:
  - Current plan and trial status
  - Upgrade / subscribe flow (Stripe Checkout)
  - Manage payment method
  - Download invoices (PDF via Cashier)
  - Cancel subscription
- [ ] Stripe webhook handling (payment succeeded, failed, subscription cancelled, etc.)
- [ ] Plan limits enforcement (if applicable for MVP — e.g., max collaborators per plan)
- [ ] Test billing flows in Stripe test mode

---

## Session 12 — Google Calendar Sync

Two-way synchronization between riservo.ch bookings and collaborator Google Calendars. Built behind a `CalendarProvider` interface for future extensibility.

- [ ] Define `CalendarProvider` interface (connect, disconnect, push event, delete event, handle incoming webhook)
- [ ] Google Calendar OAuth 2.0 flow per collaborator (connect / disconnect from profile settings)
- [ ] Push sync: booking created/updated/cancelled on riservo → event created/updated/deleted on Google Calendar
- [ ] Google Calendar events store `riservo_booking_id` in extended properties for sync tracking
- [ ] Google Push Notifications (webhooks) setup: subscribe to calendar changes per collaborator
- [ ] Pull sync: incoming webhook from Google → parse event → create or update Booking on riservo with `source: google_calendar`
- [ ] External bookings (no riservo customer): displayed in dashboard as "External Booking" with source indicator
- [ ] Conflict resolution: handle simultaneous changes gracefully
- [ ] Webhook channel renewal (Google webhooks expire after ~7 days — auto-renew via scheduled job)
- [ ] Connect/disconnect UI in collaborator profile settings
- [ ] Sync status indicator in dashboard (connected, last synced, error state)

---

## Post-MVP Backlog (v2)

Features explicitly out of scope for MVP, to be planned separately:

- Subdomain routing (`{slug}.riservo.ch`)
- SMS / WhatsApp notifications
- Stripe Connect for online payment processing
- Outlook / Apple Calendar sync (via CalendarProvider interface)
- Multiple services per booking
- Mobile app (iOS / Android)
- 2FA (TOTP) for business accounts
- Social login (Google, Apple via Laravel Socialite)
- Multi-location businesses
- Waiting list
- Recurring bookings
- Public reviews and ratings
- Owner vs Admin role distinction (separate permissions)
- Vertical-specific extensions
- S3 / Laravel Cloud file storage migration (when Hostpoint outgrown)

---

## Pre-Launch (planned separately, not agent sessions)

- Landing page at `riservo.ch/`
- Translation files: Italian, German, French
- GDPR / nLPD compliance (cookie consent, privacy policy, data deletion flow)
- Super-admin panel (business management, metrics)
- Security audit and rate limiting review
- Stripe live mode switch

---

*This roadmap defines the WHAT and WHEN — it is a checklist of outcomes, not a recipe. Every agent is expected to reason about the HOW during the planning step. If a better technical approach exists than what is implied by the roadmap wording, propose it before implementing. Each session's plan (e.g., `docs/PLAN-SESSION-1.md`, `docs/PLAN-SESSION-2.md`) is where the how gets decided and approved.*
