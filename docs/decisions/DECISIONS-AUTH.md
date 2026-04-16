# Auth Decisions

This file contains live decisions about auth boundaries, roles, invitations, verification, and identity modeling.

---

### D-004 — Separate `customers` table (not merged with `users`)
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: Customers (people who book appointments) may or may not have a user account. Storing them in the `users` table would require nullable passwords, complicate Laravel auth, and blur the line between "authenticated user" and "booking contact".
- **Decision**: `customers` table is separate. `customer.user_id` is a nullable FK to `users`. Guest customers have `user_id = null`. When a guest registers, their `User` is linked to the existing `Customer` via `user_id`.
- **Consequences**: A small join is needed when resolving a logged-in customer's booking history, but the model is clean and the auth system is not polluted with non-auth records.

---

### D-006 — Magic links as default customer auth
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: Customers booking appointments do not need to remember yet another password. Reducing friction in the booking flow increases conversion.
- **Decision**: Customer authentication uses magic links by default (signed URL, one-time use, 15–30 min expiry via `URL::temporarySignedRoute()`). Password-based registration is available as an opt-in alternative. Business owners and collaborators use password auth with magic link as an alternative option.
- **Consequences**: Customers need access to their email to authenticate. Social login (Laravel Socialite) deferred to v2.

---

### D-014 — Three roles in MVP: admin, collaborator, customer
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: SPEC listed `owner`, `admin`, and `collaborator` but never defined what distinguishes owner from admin. Maintaining a separate owner role adds complexity without MVP value. Registered customers also need an auth role.
- **Decision**: MVP has three roles: `admin` (full business access), `collaborator` (own calendar/bookings only), and `customer` (separate auth context, can only access own bookings). Admin and collaborator are business-scoped via the `BusinessUser` pivot. Customer auth is entirely separate from the business dashboard. A separate `owner` role with distinct permissions is deferred to v2.
- **Consequences**: Role middleware covers all three auth contexts. Customer sessions are isolated from business sessions.

---

### D-022 — Avatar field on User model, not BusinessUser pivot
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: SPEC §13 lists avatar as being "on BusinessUser pivot or User profile." Multi-business collaborators needing different avatars per business is unlikely for MVP.
- **Decision**: `avatar` is a nullable string column on the `users` table. One avatar per person across all businesses.
- **Consequences**: Simpler model, no pivot complexity. If per-business avatars are needed post-MVP, a migration can move the field.

---

### D-035 — Same web guard for all user types, role-based middleware
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: D-014 established three roles (admin, collaborator, customer). The question was whether customers should use a separate auth guard with their own session cookie, or share the `web` guard with business users.
- **Decision**: All user types share the single `web` guard. A custom `EnsureUserHasRole` middleware checks the user's role (admin/collaborator via BusinessUser pivot, customer via Customer record). After login, users are redirected based on role: business users → `/dashboard`, customers → `/my-bookings`. A user can satisfy multiple roles simultaneously (e.g., a business admin who also has a Customer record).
- **Consequences**: Simpler implementation — one guard, one login page, one session. A user who is both a business admin and a customer gets redirected to the dashboard (business takes priority) but can navigate to `/my-bookings` manually.

---

### D-036 — business_invitations table for collaborator invites
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: Collaborators are invited by email. The invite must be stored until accepted. Two approaches were considered: (a) pre-create a User record with null password, or (b) use a dedicated invitations table.
- **Decision**: A `business_invitations` table stores pending invites (business_id, email, role, token, expires_at, accepted_at). No User record is created until the collaborator accepts the invite and sets their password. Invitations expire after 48 hours.
- **Consequences**: No orphan User records for unaccepted invites. The acceptance flow creates both the User and BusinessUser pivot atomically. Session 9 builds the admin UI for sending invites; Session 5 builds the backend and acceptance page.

---

### D-037 — Magic link one-time use via token column on users table
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: D-006 requires magic links to be one-time use. `URL::temporarySignedRoute()` handles expiry and tamper protection but does not enforce single use. A mechanism is needed to invalidate a link after it's been clicked.
- **Decision**: A `magic_link_token` nullable string column on the users table. When a magic link is requested, a random token is generated, stored on the user, and included as a parameter in the signed URL. On verification, the controller checks the token matches, then clears it. Requesting a new magic link overwrites the old token, invalidating previous links.
- **Consequences**: One active magic link per user at a time. Simple and stateless — no extra table needed. The signed URL handles expiry (15 min) and integrity; the token column handles one-time use.

---

### D-038 — Email verification required for business dashboard access
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: Business owners register with email + password. The question was whether email verification should be required before accessing the dashboard or just encouraged.
- **Decision**: Email verification is required. The `verified` middleware is applied to all dashboard routes. Unverified users are redirected to a "verify your email" page with a resend button. Collaborators who accept an invite are automatically marked as verified (they proved email ownership by clicking the invite link). Customers authenticated via magic link are also auto-verified. Customer routes (`/my-bookings`) do not require email verification.
- **Consequences**: Prevents fake signups from accessing business features. Adds a verification step to the registration flow but is standard SaaS practice.

---

### D-039 — Reserved slug blocklist for business registration
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: D-003 and D-013 established catch-all `/{slug}` routing. Business slugs must not collide with system routes. A blocklist is needed to prevent registration of slugs like `login`, `dashboard`, `api`, etc.
- **Decision**: A `SlugService` maintains a constant array of reserved slugs (all current and planned system route prefixes). Business registration generates a slug from the business name via `Str::slug()`, checks against the blocklist and existing slugs, and appends an incrementing number if taken.
- **Consequences**: The blocklist must be maintained as new routes are added. Slug generation is centralized in `SlugService` — used by registration (Session 5) and business settings (Session 9).

---

### D-041 — Service pre-assignment via service_ids JSON on business_invitations
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: During onboarding step 4, the admin invites collaborators and can select which services they should be assigned to. However, collaborators don't exist as Users until they accept the invite (D-036). The `collaborator_service` pivot requires a `user_id`.
- **Decision**: A `service_ids` nullable JSON column on `business_invitations` stores an array of service IDs that should be auto-assigned when the collaborator accepts. `InvitationController@accept` reads this field and creates `collaborator_service` records for valid service IDs that still exist.
- **Consequences**: If a service is deleted between invitation and acceptance, the orphaned ID is silently ignored. Service assignment can also happen later in Session 9's collaborator management UI.

---

### D-061 — Provider is a first-class entity; role governs dashboard access only
- **Date**: 2026-04-16
- **Status**: accepted
- **Supersedes**: the "collaborator" half of D-014, D-036, D-041.
- **Context**: D-014 defined three roles (`admin`, `collaborator`, `customer`). The role model conflated "dashboard permissions" and "customer can book this person". Eligibility queries branched on role string across the slot engine, public booking, manual booking, settings, and calendar. Solo-business owners could not be providers; admins were excluded from schedules and service assignment. REVIEW-1 flagged this as the root of the "unbookable business" failure mode.
- **Decision**:
  - The `business_user` pivot is renamed to `business_members`; the `collaborator` role value is retired and replaced with `staff`. The role now names a permission level only (`admin`, `staff`), not a bookability capability.
  - `providers` becomes a first-class table: one row per bookable person per business, with soft-delete, and its own schedule, exceptions, service attachments, and bookings. `providers.user_id` is kept nullable-capable in schema (for a future subcontractor-without-login case) but enforced `NOT NULL` in application logic for MVP.
  - `collaborator_service` is renamed to `provider_service`. `bookings.collaborator_id`, `availability_rules.collaborator_id`, and `availability_exceptions.collaborator_id` are repointed to `provider_id` (FK → providers).
  - The `is_active` pivot flag is replaced with `SoftDeletes` on both `business_members` and `providers`. Soft-delete is authoritative for deactivation.
  - `businesses.allow_collaborator_choice` is renamed `allow_provider_choice`.
- **Consequences**:
  - Role-based authorization uses `business_members.role`; bookability uses `Business::providers()`. They no longer share a column.
  - Admin-as-provider becomes a data-model-supported state. R-1B builds the onboarding opt-in, Settings → Account toggle, step-5 launch gate, and public-page service filtering on top of this foundation.
  - The legacy term "collaborator" is fully removed. Identifiers that carried the word are renamed throughout: relations, enum values, middleware role strings, route segments, Inertia props, frontend types, translation keys, and file names.
  - `collaborator_id` ceases to exist as an application column. Form Requests, URLs, and API payloads use `provider_id`.
  - `$provider->delete()` makes a provider unbookable without losing history; `$provider->restore()` brings them back. Historical bookings reference a soft-deleted provider row.
  - The unique index on providers is `(business_id, user_id, deleted_at)`, permitting one active row plus any number of soft-deleted rows per (business, user).
