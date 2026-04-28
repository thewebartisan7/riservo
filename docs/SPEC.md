# riservo.ch — Product Specification (MVP)

> Version: 0.1 — Draft  
> Status: In Progress  
> Language: English  

---

## 1. Overview

**riservo.ch** is a SaaS platform that allows service-based businesses to manage appointments online. It provides a business-facing dashboard for managing staff, services, schedules, and bookings, and a public-facing booking interface where customers can discover and reserve appointments.

The platform is designed to be **business-type agnostic**: any professional who offers time-based appointments (hairdressers, coaches, therapists, consultants, etc.) can use it without customization. Vertical-specific extensions may be introduced post-MVP.

---

## 2. Business Model

- **SaaS subscription** for businesses: monthly or annual billing
- **No commission** on individual bookings — when the business enables online customer-to-professional payments, 100% of the charge lands on the professional's Stripe Connect Express account (direct charges); riservo's only revenue is the SaaS subscription
- **Indefinite free trial** at launch to drive adoption and retention
- Paid plans will unlock higher usage limits or advanced features (to be defined pre-launch)
- Customers (end users who book appointments) use the platform **for free**, always

---

## 3. Core Concepts

### Business
A registered organization on riservo.ch. Has one or more admins, staff members, providers, services, and a public booking page. Each business is identified by a **unique slug** (e.g., `riservo.ch/salone-mario`).

### Staff (dashboard access)
A person with dashboard access to a Business — one row per person in the `business_members` pivot, with role `admin` or `staff` (D-061). Role governs permission only, not bookability. An admin can do everything; a staff member sees their own calendar and bookings. A staff membership does not, by itself, make a person bookable.

### Provider (bookable person)
A bookable person within a Business — one row in the `providers` table (D-061). A Provider has its own weekly schedule, exceptions, service attachments, and bookings. A Provider typically links to a User (the same person who logs in as admin or staff), but the schema allows a subcontractor-without-login variant for future use. Customers may book with a specific provider, or let the system assign one automatically — configurable per Business via `allow_provider_choice`.

A single person may be both a staff member (dashboard access) and a provider (bookable). An admin opting in as a provider is the normal path for a solo business (D-062).

### Service
A bookable offering defined by the Business. Each service has:
- Name and description
- Duration (fixed, in minutes)
- Price (can be 0 / "on request")
- `buffer_before` (minutes, default 0): time blocked before the appointment starts — e.g., travel time or setup preparation
- `buffer_after` (minutes, default 0): time blocked after the appointment ends — e.g., cleanup or transition time
- `slot_interval` (minutes, e.g., 15 or 30): controls how frequently start times are offered for this service — a 60-minute service with a 15-minute interval offers slots at 09:00, 09:15, 09:30, etc.
- Both buffers are invisible to the customer; they only affect slot availability calculation
- Which providers can perform it (all or a subset)
- Active/inactive status

Multiple-service bookings (booking more than one service in a single session) are **out of scope for MVP**. Combined services should be defined as a single service (e.g., "Haircut + Beard").

### Booking
A confirmed appointment between a Customer and a Provider for a specific Service at a specific time. A booking has a status lifecycle:

- `pending` — created, awaiting confirmation (if manual confirmation is enabled)
- `confirmed` — confirmed by the business or auto-confirmed
- `cancelled` — cancelled by either party
- `completed` — past the end time (automated transition via scheduled job)
- `no_show` — marked manually by the business

### Customer
A person who books an appointment. Can be:
- **Guest**: books without an account; email and phone number are required
- **Registered**: has an account on riservo.ch; can view booking history and manage upcoming bookings

### Availability
The set of rules and exceptions that determine when a Provider (and therefore the Business) can accept bookings. Composed of:
- **Recurring weekly schedule**: working hours per day of the week, including breaks
- **Exceptions**: one-off modifications such as absences, holidays, or extra availability — applicable at both Business and Provider level

---

## 4. Architecture Decisions

### Tech Stack
- **Backend**: Laravel 13 (PHP 8.3)
- **Frontend**: Inertia.js + React + TypeScript
- **Database**: Postgres 16 (all environments, managed on Laravel Cloud in production)
- **Billing**: Laravel Cashier (Stripe) on the `Business` model
- **Scheduling**: custom implementation using Laravel + Carbon (no third-party scheduling package in MVP)

### Multi-Tenancy
- **MVP**: single domain, path-based routing — `riservo.ch/{slug}`
- Each Business has a unique, human-readable slug set at registration
- The slug is the stable identifier used in all public URLs
- Architecture is **prepared for subdomain routing** (`{slug}.riservo.ch`) as a future upgrade without data model changes
- No multi-tenancy package (e.g., tenancy-for-laravel) in MVP — standard Laravel with `business_id` scoping on all queries

### URL Structure
```
riservo.ch/                          → Marketing / landing page
riservo.ch/register                  → Business registration
riservo.ch/login                     → Business / user login
riservo.ch/dashboard                 → Business dashboard (authenticated)
riservo.ch/{slug}                    → Public booking page (catch-all route)
riservo.ch/{slug}/{service-slug}     → Public booking page pre-filtered by service
riservo.ch/bookings/{token}          → Customer booking confirmation / management
```

The `{slug}` catch-all route is registered last in the router to avoid conflicts with application routes. A reserved slug blocklist prevents businesses from registering slugs that collide with system routes (e.g., `login`, `register`, `dashboard`, `bookings`, `api`, etc.).

---

## 5. Availability Engine

This is the most critical component of the platform. It must correctly calculate open time slots for a given Service + Provider + Date combination, taking into account all availability rules and exceptions.

### 5.1 Recurring Weekly Schedule

Each Provider has a set of weekly availability rules:

```
Monday:    09:00–13:00, 14:00–18:00
Tuesday:   09:00–13:00, 14:00–18:00
Wednesday: closed
...
```

- Multiple time windows per day are supported (e.g., morning + afternoon)
- The Business can also define **business-level hours**, which act as outer bounds — a provider cannot be available outside of business hours

### 5.2 Exceptions

Exceptions override the recurring schedule for a specific date range. They apply at two levels:

**Business-level exceptions** (affects all providers):
- Holiday closures
- Unexpected closures
- Special extended hours

**Provider-level exceptions**:
- Sick day (full day absence)
- Partial absence (e.g., 10:00–11:00 unavailable — doctor's appointment)
- Extra availability outside normal hours

Exception types:
- `block` — marks time as unavailable
- `open` — adds availability outside normal hours

### 5.3 Slot Calculation

When a customer selects a Service and a date, the system:

1. Retrieves the provider's recurring weekly schedule for that weekday
2. Applies any business-level exceptions for that date
3. Applies any provider-level exceptions for that date
4. Subtracts existing confirmed/pending bookings, including their `buffer_before` and `buffer_after` windows
5. Generates available start times based on the service's total occupied time (`buffer_before` + `duration` + `buffer_after`) and the service's configured slot interval (e.g., every 15 or 30 minutes)

The customer always sees the clean appointment time (e.g., 10:00–11:00). Buffers are an internal scheduling concern only.

Overlapping confirmed/pending bookings on the same provider are prevented at the database layer by a Postgres `EXCLUDE USING GIST` constraint (D-065). Application-level availability checking remains the fast-path, with the DB constraint as the race-safe backstop.

### 5.4 Automatic Provider Assignment

When the Business disables provider selection for customers (`allow_provider_choice = false`), the system assigns the first available provider who can perform the requested service. The assignment strategy is configurable (round-robin or first-available). A submitted `provider_id` is ignored server-side in this mode (R-7).

---

## 6. Public Booking Flow

The public booking page is the customer-facing interface at `riservo.ch/{slug}`.

### Step-by-step flow:

1. **Landing**: Customer arrives at the business page — sees business name, description, and list of services
2. **Select Service**: Customer picks a service (shows name, duration, price)
3. **Select Provider** *(optional)*: If the Business allows it, the customer can choose a specific provider or select "Any available"
4. **Select Date**: Calendar showing available dates (days with no slots are greyed out)
5. **Select Time**: Available time slots for the selected date
6. **Enter Details**: Name, email, phone number (required); optional notes
7. **Confirm**: Summary screen → customer confirms booking
8. **Confirmation**: Booking is created; confirmation email sent to customer; notification sent to business/provider

### Guest vs Registered Flow
- Guest customers enter their details each time; receive a unique booking URL to manage their appointment
- Registered customers have their details pre-filled and can view all their bookings in a personal dashboard

### Booking Management (Customer)
Via their unique booking link or account, a customer can:
- View booking details
- Cancel a booking (up to a configurable cancellation window before the appointment)

---

## 7. Business Dashboard

The dashboard is the authenticated area for business admins and staff (per D-061).

### 7.1 Roles & Permissions

| Role | Access |
|------|--------|
| **Admin** | Full access: settings, billing, all providers, all staff, all bookings |
| **Staff** | Own calendar and bookings only (when linked to a Provider); no access to settings or billing |

> **Note**: MVP has three roles — `admin` and `staff` for dashboard access (business-scoped via the `business_members` pivot, per D-061), plus `customer` as a separate auth context. Registered customers authenticate via magic link or password but can only access their own bookings, completely outside the business dashboard. "Bookability" is separate from "dashboard role": a person becomes bookable by having a `providers` row attached to the business (D-061). A separate `owner` role (distinct from admin) is a v2 concern.

### 7.2 Calendar View

- **Admin**: sees all providers' bookings in a unified calendar; can filter by provider
- **Staff** (non-admin): sees only their own bookings (when linked to a Provider)
- Views: day, week, month
- Can create manual bookings directly from the calendar (for phone/walk-in bookings)

### 7.3 Booking Management

- List and calendar view of all bookings
- Filter by date, provider, service, status
- View booking details
- Change booking status (confirm, cancel, mark as no-show, mark as completed)
- Add internal notes to a booking

### 7.4 Manual Booking Creation

Business staff can create bookings manually from the dashboard (e.g., for bookings received via phone or WhatsApp). Required fields: customer name, email or phone, service, provider, date/time.

### 7.5 Customer Directory (CRM)

Accessible to admins only. Lists all customers who have made at least one booking with the business.

For each customer:
- Name, email, phone
- Total number of bookings
- Last booking date
- Full booking history with that business

Customers can be searched by name, email, or phone. When creating a manual booking from the dashboard, staff can search and select an existing customer instead of re-entering their details.

### 7.6 Settings

**Business Settings:**
- Business name, description, logo, contact info
- Booking slug
- Booking confirmation mode: auto-confirm or manual confirmation
- Allow customer to choose provider (`allow_provider_choice`): yes/no
- Payment mode: `offline` (pay on-site), `online` (pay at booking via Stripe Checkout — card + TWINT for CH), `customer_choice` (customer picks online or pay-on-site at checkout). `online` and `customer_choice` require the Business to have connected a verified Stripe Connect Express account from Settings → Connected Account — the Settings → Booking select gates non-offline options on `Business::canAcceptOnlinePayments()` (verified caps + country in `config('payments.supported_countries')`, currently `['CH']`). When enabled, customers are redirected to hosted Stripe Checkout; the Business receives funds directly on their connected account (direct charges, zero riservo commission)
- Cancellation policy (minimum notice period) — enforced on customer-side cancellations only; admins can always cancel from the dashboard without restrictions
- Business-level working hours

**Service Management:**
- Create, edit, deactivate services
- Set name, description, duration, price, slot interval, assigned providers

**Staff & Provider Management:**
- Invite staff members by email (adds a `business_members` row with role `admin` or `staff`)
- Toggle a staff member as a bookable provider (adds / soft-deletes a `providers` row for that business + user)
- Set a provider's weekly schedule
- Add provider exceptions (absences, extra availability)
- Attach providers to services
- An admin can also be their own first provider (D-062) — the "be bookable" toggle lives under Settings → Account

---

## 8. Widget & Embed

The booking form can be embedded directly on a business's own website in two modes, allowing customers to book without leaving the business's site.

### Iframe Embed
Any page at `riservo.ch/{slug}` accepts an `?embed=1` query parameter. When present:
- Navigation header and footer are hidden
- Layout adapts to fit inside an iframe
- The business copies a standard `<iframe>` snippet from their dashboard settings

```html
<iframe src="https://riservo.ch/salone-mario?embed=1" width="100%" height="700" frameborder="0"></iframe>
```

### Popup Embed
A lightweight JS snippet that the business copies onto their site. When a trigger element is clicked (a button, link, or custom selector), the booking form opens in a modal overlay on top of the business's existing page.

```html
<script src="https://riservo.ch/embed.js" data-slug="salone-mario"></script>
<button data-riservo-open>Book Now</button>
```

### Service Pre-filter
Both embed modes support pre-filtering to a specific service via a path segment:

```
/{slug}/{service-slug}?embed=1
```

For the popup embed, add `data-riservo-service="<service-slug>"` to the trigger element:

```html
<script src="https://riservo.ch/embed.js" data-slug="salone-mario"></script>
<button data-riservo-open data-riservo-service="taglio-capelli">Book haircut</button>
```

Multiple buttons can share one script tag, each with its own service (or none).

### Dashboard Embed Settings
The business dashboard includes an **Embed & Share** section with:
- Copy buttons for iframe snippet and JS popup snippet
- Live preview of the embedded form
- Pre-filtered snippets per service (path form, per D-070)

---

## 9. Notifications

### MVP (Email only)

| Event | Recipient |
|-------|-----------|
| Booking confirmed | Customer + Provider |
| Booking cancelled (by customer) | Business + Provider |
| Booking cancelled (by business) | Customer |
| Booking reminder | Customer (configurable: 24h / 1h before; eligibility evaluated in business-local wall-clock time per D-071) |
| New booking received | Business + Provider |

Emails are sent via a transactional email provider (configured at deploy time on Laravel Cloud). Booking notifications are queued with `afterCommit()` semantics; interactive notifications (magic link, staff invitation) dispatch via closure-after-response (D-075) to keep SMTP latency off the request path without introducing a worker dependency.

### Post-MVP
- SMS notifications
- WhatsApp notifications
- Push notifications (when mobile app is available)

---

## 10. Authentication & Authorization

Authentication is implemented with custom Laravel controllers — **no Laravel Fortify, no Jetstream**. These packages add unnecessary abstraction and complicate a straightforward auth flow.

### Business Owner & Admin Auth
- Email + password registration and login
- Magic link login available as alternative (signed URL, 15-minute expiry, one-time use)
- Email verification on registration
- Password reset via email
- Auth-recovery endpoints (`POST /magic-link`, `POST /forgot-password`) are throttled per-email and per-IP via FormRequest (D-072)
- **2FA (TOTP)**: explicitly out of scope for MVP — noted as v2 priority for business accounts

### Staff Auth
- Invited via email link by an admin; invitation expiry centralised in `BusinessInvitation::EXPIRY_HOURS` (48h)
- Sets password on first login via invite link (creates the `User` and the `business_members` row with role `staff`)
- Magic link login available as alternative (same as admins)
- Access scoped to their own business only via per-request tenant context (D-063)

### Customer Auth
- **Magic link by default**: customers receive a signed URL via email to access their booking management area — no password required
- Optional account registration (email + password) — **does not require a prior booking** (D-074). Registration creates or links a Customer row via email and creates a User.
- Guest booking requires no account at all — booking managed via unique signed URL sent in confirmation email
- Social login (Google via Laravel Socialite) — v2

### Implementation Notes
- Magic links implemented with Laravel's `URL::temporarySignedRoute()` — no third-party package needed. One-time use enforced via a `magic_link_token` column (D-037).
- All magic links are one-time use and short-lived (15 minutes).
- Standard Laravel session-based auth for the dashboard (admins + staff).
- Role-based access control (RBAC) via middleware: `admin`, `staff` (both business-scoped via `business_members`), `customer` (separate auth context, own bookings only).
- Per-request tenant context via `App\Support\TenantContext` (D-063); `EnsureUserHasRole` middleware authorises against the current tenant's role, not "any attached business."
- Customer sessions are separate from business sessions.

---

## 11. Integrations

### Google Calendar Sync (MVP — final feature)

Google Calendar sync enables two-way synchronization between riservo.ch bookings and a provider's Google Calendar. This is a first-class feature, not a simple export.

**Sync behavior:**
- **riservo.ch → Google Calendar**: when a booking is created, updated, or cancelled on riservo.ch, the corresponding event is created/updated/deleted in the provider's Google Calendar
- **Google Calendar → riservo.ch**: when a provider creates or modifies an event in their Google Calendar (e.g., a phone booking or personal appointment), it appears as a booking in riservo.ch with `source: google_calendar`, visible in the dashboard like any other appointment
- External events without a customer are shown as **"External Booking"** with no customer details, but they correctly block availability for public booking

**Technical requirements:**
- OAuth 2.0 flow per provider (each connects their own Google account via the linked User)
- Google Push Notifications (webhooks) to receive real-time updates from Google Calendar
- Conflict resolution strategy for simultaneous changes
- Calendar events store a `riservo_booking_id` in extended properties to enable sync tracking

**Provider abstraction (integration pattern, not the domain Provider):**
- The integration is built behind a `CalendarProvider` interface (generic, reusable)
- Google Calendar is the first implementation
- Future providers (Outlook, Apple Calendar via CalDAV) can be added without changes to the core booking logic

**Scope:**
- One Google Calendar per bookable Provider
- Providers can connect/disconnect their calendar from their profile settings

---

## 12. Out of Scope for MVP

The following features are explicitly deferred to v2 or later:

| Feature | Notes |
|---------|-------|
| Multiple services per booking | Use combined services (e.g., "Haircut + Beard") as workaround |
| SMS / WhatsApp notifications | Email only in MVP |
| Subdomain routing (`{slug}.riservo.ch`) | Path-based routing in MVP; architecture supports upgrade |
| Outlook / Apple Calendar sync | Google Calendar only in MVP |
| Mobile app | Web-only for MVP |
| 2FA (TOTP) for business accounts | Security priority for v2 |
| Social login (Google, Apple via Socialite) | Magic link covers the low-friction use case for MVP |
| Business-type extensions / vertical features | Generic platform only |
| Public reviews / ratings | Post-launch |
| Customer-facing account dashboard | Guest flow sufficient for MVP; registered accounts are basic |
| Multi-location businesses | Single location per business in MVP |
| Waiting list | Post-MVP |
| Recurring bookings | Post-MVP |

---

## 13. Data Model (Overview)

Key entities and their primary relationships (per D-061 separation of staff and providers):

```
User (shared auth identity)
  └── may belong to one or more Businesses (via BusinessMember pivot, role: admin|staff)
  └── optionally has a Provider row in a Business (bookable representation)
  — fields: name, email (unique), password (nullable — null for magic-link-only users),
            magic_link_token (nullable), email_verified_at, avatar (nullable)

Business
  ├── has many Users (via BusinessMember: admin, staff roles)
  ├── has many Providers (bookable people; one row per bookable person per business, soft-deleted)
  ├── has many BusinessHours (weekly open/close schedule)
  ├── has many Services
  ├── has many AvailabilityExceptions (business-level)
  ├── has many Bookings
  ├── has many BusinessInvitations (pending staff invites)
  └── has one Subscription (via Laravel Cashier, when adopted)
  — fields: name, slug, description, logo, phone, email, address,
            timezone, payment_mode, confirmation_mode,
            allow_provider_choice, cancellation_window_hours,
            reminder_hours (JSON array, e.g. [24, 1]),
            onboarding_step, onboarding_completed_at

BusinessMember (pivot — dashboard access only)
  — fields: business_id, user_id, role (admin|staff), deleted_at (soft-deleted)
  — Unique on (business_id, user_id, deleted_at) — one active row per (business, user);
    soft-deleted rows may coexist.

Service
  ├── belongs to Business
  ├── has many Providers (pivot: provider_services)
  — fields: name, slug, description, duration_minutes, price (nullable decimal, null = "on request"),
            buffer_before, buffer_after, slot_interval_minutes, is_active

Provider (first-class; a bookable person within a Business)
  ├── belongs to Business
  ├── belongs to User (nullable FK in schema; NOT NULL enforced in application for MVP)
  ├── has many AvailabilityRules (weekly schedule)
  ├── has many AvailabilityExceptions (provider-level)
  ├── has many Bookings (historical bookings stay linked even after soft-delete, D-067)
  ├── has many Services (via provider_services)
  └── has one CalendarIntegration (Google Calendar)
  — soft-deleted (`deleted_at`); soft-delete is the authoritative deactivation signal.
  — Unique on (business_id, user_id, deleted_at).

BusinessInvitation
  └── belongs to Business
  — fields: email, role (admin|staff), token, service_ids (JSON — for later pivot auto-assignment),
            expires_at, accepted_at
  — Lifetime centralised in `BusinessInvitation::EXPIRY_HOURS` (48h).

BusinessHour
  └── belongs to Business
  — fields: day_of_week, open_time, close_time
  — Purpose: simple open/close constraint checked first in slot calculation
    (is the business open at all on this day/time?)

AvailabilityRule
  └── belongs to Provider
  — fields: day_of_week, start_time, end_time
  — Purpose: granular provider availability checked second
    (is this specific provider free within business hours?)

AvailabilityException
  └── belongs to Provider or Business
  — fields: start_date, end_date, start_time, end_time, type (block|open), reason
  — A single-day exception has start_date == end_date

Customer  ← always created for every booking; globally unique by email
  ├── name, email (unique, global — not scoped to business), phone
  └── user_id (nullable FK → Users): populated when/if the customer creates an account

  Guest flow:    Customer record created at booking time, user_id = null
  Registration:  Creates a User, then links to an existing Customer by email (or creates one).
                 Per D-074, registration no longer requires a prior booking.
  Re-use:        A guest booking with an existing Customer email re-uses the row
                 (`Customer::firstOrCreate(['email' => ...])`).

Booking
  ├── belongs to Business
  ├── belongs to Provider
  ├── belongs to Service
  ├── belongs to Customer
  — fields: starts_at, ends_at, status (pending|confirmed|cancelled|completed|no_show),
            source (riservo|google_calendar|manual), external_calendar_id,
            payment_status, notes, cancellation_token
  — Overlapping confirmed/pending bookings on the same provider are prevented by a Postgres
    `EXCLUDE USING GIST` constraint (D-065).

BookingReminder
  └── belongs to Booking
  — fields: booking_id, hours_before, sent_at
  — Unique on (booking_id, hours_before) — enforces reminder idempotency (D-071).

CalendarIntegration
  └── belongs to Provider (via the Provider's User when OAuth'd)
  — fields: provider (google|...), access_token, refresh_token,
            calendar_id, webhook_channel_id, webhook_expiry
```

---

## 14. Cross-Cutting Concerns

### Timezone
- Every `Business` has a `timezone` field (e.g., `Europe/Zurich`), set during onboarding
- All datetimes are stored in UTC in the database
- All display, slot calculation, and reminder scheduling is performed in the business's local timezone
- The business timezone is the single source of truth — cross-timezone online appointments are out of scope for MVP

### Internationalisation (i18n)
- All user-facing strings in PHP and React use the Laravel translation helper `__('key')` from day one
- Base language is **English** — this is the development language for all string keys
- Translation files for **Italian, German, and French** will be completed pre-launch
- No translation work is done during feature development sessions — strings are written in English and replaced at the end

### File Storage
- Laravel Storage with `local` driver in development
- Production: Laravel Cloud's managed object storage via the `public` disk
- Architecture uses Laravel's Storage facade throughout — the driver is swapped via config, no code changes required

### Rate Limiting
- Public booking routes (`/{slug}`, slot availability API, booking creation) are protected by named rate limiters registered in `AppServiceProvider` (`booking-api`, `booking-create`).
- Auth-recovery POSTs (`/magic-link`, `/forgot-password`) are throttled per-email AND per-IP via FormRequest (D-072). Values live under `auth.throttle.*` in `config/auth.php`, env-tunable.
- Booking form includes a server-side honeypot field to block naive bots.
- Authenticated dashboard routes have separate, more permissive rate limits.

### Public Slot UX (no availability)
- Calendar days with no available slots are shown greyed out, not hidden.
- When no slots exist for the selected week: "No availability this week — try the next week" with forward navigation shortcut.
- When a specific provider has no slots but others do: "No availability for [Name] — view other providers" prompt.

---

## 15. Pre-Launch Checklist

Activities to complete before public launch. Not part of the development roadmap sessions — planned separately.

- **Landing page**: marketing page at `riservo.ch/` explaining the product, pricing, and CTA to register
- **Translations**: complete IT / DE / FR translation files; review all email templates in each language
- **GDPR / nLPD compliance**:
  - Cookie consent banner
  - Privacy policy and terms of service pages
  - Customer data deletion flow (request and process)
  - Data processing agreements if applicable
- **Super-admin panel**: internal tool to manage businesses, suspend accounts, view platform metrics
- **Security review**: rate limiting audit, signed URL expiry review, dependency audit
- **Transactional email review**: test all email flows in all languages
- **Stripe go-live**: switch from test mode to live keys, verify webhook endpoints

---

*This document defines the WHAT of riservo.ch MVP. Implementation details, database migrations, and API design are defined per-session in dedicated planning documents.*
