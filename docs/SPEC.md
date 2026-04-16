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
- **No commission** on individual bookings
- **Indefinite free trial** at launch to drive adoption and retention
- Paid plans will unlock higher usage limits or advanced features (to be defined pre-launch)
- Customers (end users who book appointments) use the platform **for free**, always

---

## 3. Core Concepts

### Business
A registered organization on riservo.ch. Has one or more admins, collaborators, services, and a public booking page. Each business is identified by a **unique slug** (e.g., `riservo.ch/salone-mario`).

### Collaborator
A staff member who belongs to a Business. Has their own calendar, schedule, and exceptions. Customers may book with a specific collaborator, or let the system assign one automatically — configurable per Business.

### Service
A bookable offering defined by the Business. Each service has:
- Name and description
- Duration (fixed, in minutes)
- Price (can be 0 / "on request")
- `buffer_before` (minutes, default 0): time blocked before the appointment starts — e.g., travel time or setup preparation
- `buffer_after` (minutes, default 0): time blocked after the appointment ends — e.g., cleanup or transition time
- `slot_interval` (minutes, e.g., 15 or 30): controls how frequently start times are offered for this service — a 60-minute service with a 15-minute interval offers slots at 09:00, 09:15, 09:30, etc.
- Both buffers are invisible to the customer; they only affect slot availability calculation
- Which collaborators can perform it (all or a subset)
- Active/inactive status

Multiple-service bookings (booking more than one service in a single session) are **out of scope for MVP**. Combined services should be defined as a single service (e.g., "Haircut + Beard").

### Booking
A confirmed appointment between a Customer and a Collaborator for a specific Service at a specific time. A booking has a status lifecycle:

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
The set of rules and exceptions that determine when a Collaborator (and therefore the Business) can accept bookings. Composed of:
- **Recurring weekly schedule**: working hours per day of the week, including breaks
- **Exceptions**: one-off modifications such as absences, holidays, or extra availability — applicable at both Business and Collaborator level

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

This is the most critical component of the platform. It must correctly calculate open time slots for a given Service + Collaborator + Date combination, taking into account all availability rules and exceptions.

### 5.1 Recurring Weekly Schedule

Each Collaborator has a set of weekly availability rules:

```
Monday:    09:00–13:00, 14:00–18:00
Tuesday:   09:00–13:00, 14:00–18:00
Wednesday: closed
...
```

- Multiple time windows per day are supported (e.g., morning + afternoon)
- The Business can also define **business-level hours**, which act as outer bounds — a collaborator cannot be available outside of business hours

### 5.2 Exceptions

Exceptions override the recurring schedule for a specific date range. They apply at two levels:

**Business-level exceptions** (affects all collaborators):
- Holiday closures
- Unexpected closures
- Special extended hours

**Collaborator-level exceptions**:
- Sick day (full day absence)
- Partial absence (e.g., 10:00–11:00 unavailable — doctor's appointment)
- Extra availability outside normal hours

Exception types:
- `block` — marks time as unavailable
- `open` — adds availability outside normal hours

### 5.3 Slot Calculation

When a customer selects a Service and a date, the system:

1. Retrieves the collaborator's recurring weekly schedule for that weekday
2. Applies any business-level exceptions for that date
3. Applies any collaborator-level exceptions for that date
4. Subtracts existing confirmed/pending bookings, including their `buffer_before` and `buffer_after` windows
5. Generates available start times based on the service's total occupied time (`buffer_before` + `duration` + `buffer_after`) and the service's configured slot interval (e.g., every 15 or 30 minutes)

The customer always sees the clean appointment time (e.g., 10:00–11:00). Buffers are an internal scheduling concern only.

### 5.4 Automatic Collaborator Assignment

When the Business disables collaborator selection for customers, the system assigns the first available collaborator who can perform the requested service. The assignment strategy is configurable (round-robin or first-available).

---

## 6. Public Booking Flow

The public booking page is the customer-facing interface at `riservo.ch/{slug}`.

### Step-by-step flow:

1. **Landing**: Customer arrives at the business page — sees business name, description, and list of services
2. **Select Service**: Customer picks a service (shows name, duration, price)
3. **Select Collaborator** *(optional)*: If the Business allows it, the customer can choose a specific collaborator or select "Any available"
4. **Select Date**: Calendar showing available dates (days with no slots are greyed out)
5. **Select Time**: Available time slots for the selected date
6. **Enter Details**: Name, email, phone number (required); optional notes
7. **Confirm**: Summary screen → customer confirms booking
8. **Confirmation**: Booking is created; confirmation email sent to customer; notification sent to business/collaborator

### Guest vs Registered Flow
- Guest customers enter their details each time; receive a unique booking URL to manage their appointment
- Registered customers have their details pre-filled and can view all their bookings in a personal dashboard

### Booking Management (Customer)
Via their unique booking link or account, a customer can:
- View booking details
- Cancel a booking (up to a configurable cancellation window before the appointment)

---

## 7. Business Dashboard

The dashboard is the authenticated area for business owners and collaborators.

### 7.1 Roles & Permissions

| Role | Access |
|------|--------|
| **Admin** | Full access: settings, billing, all collaborators, all bookings |
| **Collaborator** | Own calendar and bookings only; no access to settings or billing |

> **Note**: MVP has three roles: `admin`, `collaborator`, and `customer`. Admin and collaborator are business-scoped (via `BusinessUser` pivot). Customer is a separate auth context — registered customers authenticate via magic link or password but can only access their own bookings, completely outside the business dashboard. A separate `owner` role (distinct from admin) is a v2 concern.

### 7.2 Calendar View

- **Admin**: sees all collaborators' bookings in a unified calendar; can filter by collaborator
- **Collaborator**: sees only their own bookings
- Views: day, week, month
- Can create manual bookings directly from the calendar (for phone/walk-in bookings)

### 7.3 Booking Management

- List and calendar view of all bookings
- Filter by date, collaborator, service, status
- View booking details
- Change booking status (confirm, cancel, mark as no-show, mark as completed)
- Add internal notes to a booking

### 7.4 Manual Booking Creation

Business staff can create bookings manually from the dashboard (e.g., for bookings received via phone or WhatsApp). Required fields: customer name, email or phone, service, collaborator, date/time.

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
- Allow customer to choose collaborator: yes/no
- Payment mode: `offline` (pay on-site), `online` (pay at booking), `customer_choice` — online payment requires Stripe integration (v2)
- Cancellation policy (minimum notice period) — enforced on customer-side cancellations only; admins can always cancel from the dashboard without restrictions
- Business-level working hours

**Service Management:**
- Create, edit, deactivate services
- Set name, description, duration, price, slot interval, assigned collaborators

**Collaborator Management:**
- Invite collaborators by email
- Set collaborator's weekly schedule
- Add collaborator exceptions (absences, extra availability)

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
| Booking confirmed | Customer + Collaborator |
| Booking cancelled (by customer) | Business + Collaborator |
| Booking cancelled (by business) | Customer |
| Booking reminder | Customer (configurable: 24h / 1h before) |
| New booking received | Business + Collaborator |

Emails are sent via a transactional email provider (e.g., Mailgun, Postmark, or Laravel's default mail driver).

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
- **2FA (TOTP)**: explicitly out of scope for MVP — noted as v2 priority for business accounts

### Collaborator Auth
- Invited via email link by the business owner
- Sets password on first login via invite link
- Magic link login available as alternative (same as business owners)
- Access scoped to their own business only

### Customer Auth
- **Magic link by default**: customers receive a signed URL via email to access their booking management area — no password required
- Optional account registration (email + password) for customers who prefer it
- Guest booking requires no account at all — booking managed via unique signed URL sent in confirmation email
- Social login (Google via Laravel Socialite) — v2

### Implementation Notes
- Magic links implemented with Laravel's `URL::temporarySignedRoute()` — no third-party package needed
- All magic links are one-time use and short-lived (15–30 minutes)
- Standard Laravel session-based auth for the dashboard (business + collaborators)
- Role-based access control (RBAC) via middleware: `admin`, `collaborator` (business-scoped), `customer` (separate auth context, own bookings only)
- Customer sessions are separate from business sessions

---

## 11. Integrations

### Google Calendar Sync (MVP — final feature)

Google Calendar sync enables two-way synchronization between riservo.ch bookings and a collaborator's Google Calendar. This is a first-class feature, not a simple export.

**Sync behavior:**
- **riservo.ch → Google Calendar**: when a booking is created, updated, or cancelled on riservo.ch, the corresponding event is created/updated/deleted in the collaborator's Google Calendar
- **Google Calendar → riservo.ch**: when a collaborator creates or modifies an event in their Google Calendar (e.g., a phone booking or personal appointment), it appears as a booking in riservo.ch with `source: google_calendar`, visible in the dashboard like any other appointment
- External events without a customer are shown as **"External Booking"** with no customer details, but they correctly block availability for public booking

**Technical requirements:**
- OAuth 2.0 flow per collaborator (each connects their own Google account)
- Google Push Notifications (webhooks) to receive real-time updates from Google Calendar
- Conflict resolution strategy for simultaneous changes
- Calendar events store a `riservo_booking_id` in extended properties to enable sync tracking

**Provider abstraction:**
- The integration is built behind a `CalendarProvider` interface
- Google Calendar is the first implementation
- Future providers (Outlook, Apple Calendar via CalDAV) can be added without changes to the core booking logic

**Scope:**
- One Google Calendar per collaborator
- Collaborators can connect/disconnect their calendar from their profile settings

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
| Online payments / Stripe processing | `payment_mode` field is MVP; actual Stripe charge flow is v2 |
| Business-type extensions / vertical features | Generic platform only |
| Public reviews / ratings | Post-launch |
| Customer-facing account dashboard | Guest flow sufficient for MVP; registered accounts are basic |
| Multi-location businesses | Single location per business in MVP |
| Waiting list | Post-MVP |
| Recurring bookings | Post-MVP |

---

## 13. Data Model (Overview)

Key entities and their primary relationships:

```
User (admin/collaborator)
  └── belongs to Business (via BusinessUser pivot with role: admin|collaborator)

Business
  ├── has many Users (via BusinessUser: admin, collaborator roles)
  ├── has many BusinessHours (weekly open/close schedule)
  ├── has many Services
  ├── has many AvailabilityExceptions (business-level)
  ├── has many Bookings
  └── has one Subscription (via Laravel Cashier)
  — fields: name, slug, description, logo, phone, email, address,
            timezone, payment_mode, confirmation_mode,
            allow_collaborator_choice, cancellation_window_hours,
            reminder_hours (JSON array, e.g. [24, 1])

Service
  ├── belongs to Business
  ├── has many Collaborators (pivot: collaborator_services)
  — fields: name, slug, description, duration_minutes, price (nullable decimal, null = "on request"),
            buffer_before, buffer_after, slot_interval_minutes, is_active

Collaborator (User in context of a Business)
  ├── has many AvailabilityRules (weekly schedule)
  ├── has many AvailabilityExceptions (collaborator-level)
  ├── has many Bookings
  └── has one CalendarIntegration (Google Calendar)
  — fields (on BusinessUser pivot or User profile): avatar (nullable, image path)

BusinessHour
  └── belongs to Business
  — fields: day_of_week, open_time, close_time
  — Purpose: simple open/close constraint checked first in slot calculation
    (is the business open at all on this day/time?)

AvailabilityRule
  └── belongs to Collaborator (User)
  — fields: day_of_week, start_time, end_time
  — Purpose: granular collaborator availability checked second
    (is this specific collaborator free within business hours?)

AvailabilityException
  └── belongs to Collaborator (User) or Business
  — fields: start_date, end_date, start_time, end_time, type (block|open), reason
  — A single-day exception has start_date == end_date

Customer  ← always created, regardless of guest or registered
  ├── name, email (unique), phone
  └── user_id (nullable FK → Users): populated when/if the customer creates an account

  Guest flow:   Customer record created at booking time, user_id = null
  Registered:   Customer record exists; User account created separately and linked via user_id
  Merge:        If a guest books with an email that later registers, the User is linked
                to the existing Customer record (one-to-one)

Booking
  ├── belongs to Business
  ├── belongs to Collaborator (User)
  ├── belongs to Service
  ├── belongs to Customer
  — fields: starts_at, ends_at, status (pending|confirmed|cancelled|completed|no_show),
            source (riservo|google_calendar|manual), external_calendar_id,
            payment_status, notes, cancellation_token

CalendarIntegration
  └── belongs to User (Collaborator)
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
- Public booking routes (`/{slug}`, slot availability API, booking creation) are protected with Laravel's built-in rate limiter
- Booking form includes a server-side honeypot field to block naive bots
- Authenticated dashboard routes have separate, more permissive rate limits

### Public Slot UX (no availability)
- Calendar days with no available slots are shown greyed out, not hidden
- When no slots exist for the selected week: "No availability this week — try the next week" with forward navigation shortcut
- When a specific collaborator has no slots but others do: "No availability for [Name] — view other collaborators" prompt

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
