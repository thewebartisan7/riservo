# riservo.ch — Roadmap (MVP)

> Version: 0.1 — Draft  
> Status: In Progress  
> Each session represents a focused, reviewable unit of work with a clear deliverable.  
> Implementation details are defined per-session by the agent at the start of each session.

---

## Session 1 — Laravel 13 Fresh Install & Project Setup

Starting point: fresh Laravel 13 installation (no starter kit) provided by the developer.

- [x] Configure `.env` for local development (Postgres, mail, app settings)
- [x] Set up directory structure conventions (Services, DTOs, Enums, etc.)
- [x] Configure `composer.json` with useful dev dependencies (Pint, Larastan, etc.)
- [x] Set up Laravel Pint for code style
- [x] Configure PHPUnit / Pest for testing
- [x] Commit baseline with CI-ready structure

---

## Session 2 — Data Layer (Models + Migrations + Seeders)

- [x] Create migrations, models, and factories for all core models (D-026: used artisan instead of Blueprint):
  - `Business` (include `timezone` field, default `Europe/Zurich`), `User` (modified), `BusinessMember` (pivot with roles — renamed from `BusinessUser` by D-061)
  - `BusinessHour` (business-level weekly open/close schedule)
  - `Service` (include `slug` field), `ProviderService` (pivot — renamed from `CollaboratorService` by D-061)
  - `Provider` (first-class bookable entity, added by D-061)
  - `Customer`, `Booking`
  - `AvailabilityRule`, `AvailabilityException`
  - `CalendarIntegration`
- [x] Review and adjust migrations (indexes, constraints, enums)
- [x] Write seeders:
  - 1 Business ("Salone Bella") with realistic data and unique slug
  - 4 Staff members (1 admin + 3 staff) with different weekly schedules, each also attached as a Provider
  - 5 Services with varying durations, prices, buffer_before/after
  - Business-level and provider-level availability rules and exceptions
  - 10 Bookings across all statuses (confirmed, pending, completed, cancelled, no_show) and sources (riservo, manual)
- [x] Verify all relationships and factories work correctly (57 tests passing)
- [x] Confirm Postgres compatibility for all migrations

---

## Session 3 — Scheduling Engine (TDD)

Core service that calculates available booking slots. Built test-first.

- [x] Write unit tests covering:
  - Basic slot generation from a weekly schedule
  - Correct application of `buffer_before`, `buffer_after`, and per-service `slot_interval`
  - Business-level exception blocks full day and partial day
  - Provider-level exception blocks full day and partial time window
  - Provider-level open exception extends availability
  - Existing bookings (with buffers) correctly block slots
  - Slot calculation respects business timezone (UTC storage, local display)
  - Edge cases: back-to-back bookings, midnight boundary, slot at exact closing time, DST transitions
  - Automatic provider assignment (first-available and round-robin strategies)
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

- [x] Business owner registration (name, email, password, business name) + email verification
- [x] Email + password login and logout for admins and staff
- [x] Magic link login for admins and staff (alternative to password, `URL::temporarySignedRoute()`, one-time use, 15 min expiry)
- [x] Magic link as default auth for customers (no password required)
- [x] Optional password-based registration for customers (open, no prior-booking requirement per D-074)
- [x] Password reset via email
- [x] Staff invite flow: admin sends invite link → invitee sets password on first login (48h lifetime, centralised in `BusinessInvitation::EXPIRY_HOURS`)
- [x] Role middleware: `admin`, `staff` (business-scoped via `business_members`, per D-061), `customer` (separate auth context, own bookings only) — owner/admin distinction deferred to v2
- [x] Per-request tenant context via `App\Support\TenantContext` (D-063); FK validation via `BelongsToCurrentBusiness` (D-064)
- [x] Auth-recovery POSTs throttled per-email and per-IP via FormRequest (D-072)
- [x] Guest booking management via signed URL token (no login required)
- [x] Route protection and redirect logic per role
- [x] All auth pages built in React with COSS UI components

---

## Session 6 — Business Onboarding Wizard

Distraction-free wizard shown to a business owner after registration. Completed before the dashboard is accessible for the first time.

- [x] Step 1 — Business profile: name, description, logo upload, contact info, slug (with live availability check)
- [x] Step 2 — Working hours: set weekly schedule (days + time windows) with a clean visual interface
- [x] Step 3 — First service: add at least one service (name, duration, price, buffers, slot interval) + admin opts in as provider with own schedule (D-062)
- [x] Step 4 — Invite staff (optional, skippable): invite by email, assign to service (48h lifetime)
- [x] Step 5 — Summary & launch: show public booking URL, copy link, go to dashboard. Launch is blocked if any active service has zero providers (D-062).
- [x] Wizard state persisted server-side (resumable if interrupted)
- [x] Progress indicator (step X of Y)
- [x] Smooth transitions between steps, no distractions (no main nav visible)
- [x] On completion, redirect to dashboard with a welcome state

---

## Session 7 — Public Booking Flow

The customer-facing booking experience at `riservo.ch/{slug}`.

> **UI hint — COSS UI calendar particles**: check https://coss.com/ui/particles?tags=calendar for ready-made calendar and time slot components. Particularly useful:
> - `pnpm dlx shadcn@latest add @coss/p-calendar-19` — calendar with integrated time slot picker (good fit for date + time selection in one view)
> - `pnpm dlx shadcn@latest add @coss/p-calendar-24` — calendar with customizable day buttons (could show free slot count per day for instant visibility)
> The agent should evaluate these particles and pick the best UX — these are suggestions, not requirements.

- [x] Business public page: name, description, list of active services (services without an eligible provider are hidden, per D-062)
- [x] Service selection step
- [x] Provider selection step (shown only if `allow_provider_choice = true`; "Any available" option; provider avatar displayed if set). Server-side enforcement ignores a submitted `provider_id` when `allow_provider_choice = false` (R-7).
- [x] Date picker: calendar showing available dates (days without slots greyed out, not hidden)
- [x] Time slot picker: available slots for selected date, generated by `AvailabilityService` in business timezone
- [x] UX for no availability: "No availability this week" message with forward navigation; suggest other providers if applicable
- [x] Customer details form: name, email, phone, optional notes; server-side honeypot field
- [x] Booking summary and confirmation step
- [x] Booking creation: guest customer record created via `Customer::firstOrCreate(['email' => ...])`; overlap protected by Postgres `EXCLUDE USING GIST` constraint (D-065)
- [x] Booking confirmation page with details and unique management link
- [x] Customer booking management via token: view details, cancel (within cancellation window)
- [x] Pre-filter by service via URL path segment (`/{slug}/{service-slug}`, canonical form per D-070)
- [x] Rate limiting on public routes: slot availability API (`booking-api`), booking creation endpoint (`booking-create`)
- [x] All strings use `__()` helper (English base, translated pre-launch)

---

## Session 8 — Business Dashboard

The main authenticated view for admins and staff (per D-061). Calendar view is handled separately in Session 11.

> **UI guidelines** (suggestions — the agent should evaluate and adapt during planning):
> - **Dashboard home**: cards/list for today's appointments and stats — low volume, no pagination needed.
> - **Bookings list & Customer Directory**: COSS UI Table component. Server-side sorting (query params via Inertia `<Link>`), server-side pagination (`->paginate()`), server-side filters — all via Inertia partial reloads. TanStack Table is likely overkill here; evaluate during planning.
> - **Booking detail**: slide-over or modal panel.
> - The UI does not need to be pixel-perfect at this stage — the visual refinement pass will happen later with Pencil.dev. Focus on the right component foundations and data flow.

- [x] Dashboard home: today's appointments summary, quick stats (bookings this week, upcoming)
- [x] Bookings list view with filters (date range, provider, service, status)
  - Server-side sorting and pagination for past bookings
- [x] Booking detail panel (slide-over or modal): full booking info, status actions
- [x] Change booking status: confirm, cancel, no-show, complete
- [x] Add internal note to a booking
- [x] Manual booking creation from dashboard:
  - Search and select existing customer or create new
  - Select service, provider, date, time slot (using AvailabilityService)
  - Confirm and notify customer
- [x] Customer Directory (CRM):
  - List all customers with at least one booking in this business (scoped via `whereHas('bookings', fn ($q) => $q->where('business_id', …))`)
  - Search by name, email, phone
  - Server-side search and pagination
  - Customer detail: contact info, booking history, stats (total visits, last visit)

---

## Session 9 — Business Settings

Full settings area for managing the business configuration.

- [x] Business profile editing (name, description, logo with null-on-remove + physical delete per D-076, contact info, slug)
- [x] Booking settings: confirmation mode, `allow_provider_choice` toggle, cancellation window, payment mode
- [x] Business-level working hours editor (update after onboarding)
- [x] Business-level exceptions: add/edit/delete closures and special hours
- [x] Service management: create, edit, deactivate services (name, duration, price, buffer_before, buffer_after, slot_interval, assigned providers)
- [x] Staff & Provider management (per D-061):
  - `settings/staff`: view all staff members, invite by email, soft-delete
  - `settings/providers/{provider}`: edit a provider's weekly schedule, exceptions, service attachments
  - `settings/account`: admin opts in/out as their own provider (D-062)
  - Avatar upload (optional, fallback to generated initials)
- [x] Embed & Share settings:
  - `?embed=1` param: strips navigation, adapts layout for iframe embedding
  - Popup embed: JS snippet (`<script>`) that opens booking form in a modal overlay on the business's own website
  - Both embed modes support service pre-filter via URL path segment (D-070)
  - Copy buttons for iframe snippet and JS popup snippet
  - Live preview of the embedded form

---

## Session 10 — Notifications (Email)

Transactional email system for all booking lifecycle events.

- [x] Configure Laravel Mail for MVP (credentials via .env — provider chosen at deploy time on Laravel Cloud; swappable with no code changes)
- [x] Email templates in Blade markdown (consistent riservo.ch branding — `config/app.php` name, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`):
  - Booking confirmed (to customer)
  - Booking confirmed (to provider)
  - New booking received (to business/provider)
  - Booking cancelled by customer (to business + provider)
  - Booking cancelled by business (to customer)
  - Booking reminder (to customer — 24h before and/or 1h before, configurable; eligibility in business-local wall-clock time per D-071)
- [x] `Booking*Notification` classes: `implements ShouldQueue` + `afterCommit()` (queued via database driver)
- [x] Interactive notifications (`MagicLinkNotification`, `InvitationNotification`): closure-after-response dispatch (D-075) — off the request path with no queue dependency
- [x] Reminder scheduling via Laravel queues + scheduled jobs; idempotent via `booking_reminders` unique `(booking_id, hours_before)` constraint (D-071)
- [x] Scheduled job: automatically transition confirmed bookings to `completed` status after their end time has passed
- [x] Test all email flows end-to-end using seeded data
- [x] Document platform requirements in `docs/DEPLOYMENT.md` (Laravel Cloud-managed scheduler, queue, mail)

---

## Session 11 — Calendar View

Custom calendar component for the business dashboard. Built with TailwindPlus templates as design reference.

> **Architecture hint**: consider a server-driven approach where the controller generates the calendar grid and bookings via Carbon, served as Inertia props. Navigation between weeks/months could use Inertia partial reloads (`only: ['calendar']`) to keep it fast. `date-fns` (already a transitive dependency via `react-day-picker`) is available for any client-side date math needed for rendering. The agent should evaluate this approach against alternatives during planning.

- [x] Month view: grid showing bookings per day, navigation between months
- [x] Week view: time-based grid showing bookings with proportional height
- [x] Day view: detailed hour-by-hour view
- [x] View switcher (day / week / month) and date navigation (prev / today / next)
- [x] Admin: sees all providers' bookings in a single combined view
    - Provider filter (toggle list with color indicators — multi-select, default all visible)
    - Bookings color-coded by provider
    - When multiple providers have bookings at the same time, handle overlap with a simple approach (e.g., split cell width, stacked entries)
- [x] Staff (non-admin, when linked to a Provider): sees only their own bookings
- [x] Click on booking to open detail panel (built in Session 8)
- [x] Current time indicator
- [x] Responsive behavior for smaller screens

> **Scope notes**:
> - A single provider can never have overlapping bookings (enforced by the Postgres `EXCLUDE USING GIST` constraint, D-065). Cross-provider overlap in the combined admin view needs simple handling — the agent should find an effective UI approach during planning.
> - Full parallel column view (one dedicated column per provider, Google Calendar style) is post-MVP — it requires a more complex layout engine. The MVP combined view with filtering should be designed so it can evolve toward this in the future.

---

## Session 12 — Google Calendar Sync

Two-way synchronization between riservo.ch bookings and a Provider's Google Calendar. Built behind a `CalendarProvider` interface (integration pattern, not the domain Provider entity) for future extensibility. Synced events are displayed in the calendar view built in Session 11.

- [ ] Define `CalendarProvider` interface (connect, disconnect, push event, delete event, handle incoming webhook)
- [ ] Google Calendar OAuth 2.0 flow per Provider, authenticated via the Provider's linked User (connect / disconnect from `settings/account` and `settings/providers/{provider}`)
- [ ] Push sync: booking created/updated/cancelled on riservo → event created/updated/deleted on Google Calendar
- [ ] Google Calendar events store `riservo_booking_id` in extended properties for sync tracking
- [ ] Google Push Notifications (webhooks) setup: subscribe to calendar changes per Provider
- [ ] Pull sync: incoming webhook from Google → parse event → create or update Booking on riservo with `source: google_calendar`
- [ ] External bookings (no riservo customer): displayed in dashboard as "External Booking" with source indicator
- [ ] Conflict resolution: handle simultaneous changes gracefully
- [ ] Webhook channel renewal (Google webhooks expire after ~7 days — auto-renew via scheduled job)
- [ ] Connect/disconnect UI in the Provider's settings page
- [ ] Sync status indicator in dashboard (connected, last synced, error state)
- [ ] Add any relevant info about calendar sync deployments on server in the `docs/DEPLOYMENT.md`

---

## Session 13 — Billing (Laravel Cashier)

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
- [ ] Plan limits enforcement (if applicable for MVP — e.g., max staff members / max providers per plan)
- [ ] Test billing flows in Stripe test mode

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
- Migrate transactional email to Mailtrap Sending (or equivalent dedicated provider) when the Laravel Cloud-provisioned SMTP integration is outgrown

---

## Pre-Launch (planned separately, not agent sessions)

- Landing page at `riservo.ch/`
- Translation files: Italian, German, French
- GDPR / nLPD compliance (cookie consent, privacy policy, data deletion flow)
- Super-admin panel (business management, metrics)
- Security audit and rate limiting review
- Stripe live mode switch

---

*This roadmap defines the WHAT and WHEN — it is a checklist of outcomes, not a recipe. Every agent is expected to reason about the HOW during the planning step. If a better technical approach exists than what is implied by the roadmap wording, propose it before implementing. Active session plans live in `docs/plans/`; completed plans move to `docs/archive/plans/` once they are no longer part of the current working set.*
