# Handoff

**Session**: 5 — Authentication  
**Date**: 2026-04-13  
**Status**: Complete

---

## What Was Built

Session 5 implemented the complete custom authentication system (no Fortify, no Jetstream) covering three user types: business admins, collaborators, and customers.

### Backend

**3 migrations:**
- `make_password_nullable_on_users_table` — allows magic-link-only users
- `add_magic_link_token_to_users_table` — one-time-use magic link enforcement
- `create_business_invitations_table` — collaborator invite storage

**Models:**
- `BusinessInvitation` — new model with factory, helpers: `isExpired()`, `isAccepted()`, `isPending()`
- `User` updated — implements `MustVerifyEmail`, added `magic_link_token` to fillable/hidden, added helpers: `hasBusinessRole()`, `isCustomer()`, `currentBusiness()`, `currentBusinessRole()`

**9 controllers:**
- `Auth\RegisterController` — business owner registration (creates User + Business + admin pivot)
- `Auth\LoginController` — email/password login, logout, role-based redirect
- `Auth\MagicLinkController` — magic link request + verify (for business users and customers, auto-creates User for guest customers)
- `Auth\EmailVerificationController` — notice, verify, resend
- `Auth\PasswordResetController` — forgot password + reset password
- `Auth\InvitationController` — collaborator invite acceptance (creates User + BusinessUser pivot)
- `Auth\CustomerRegisterController` — customer password registration (links to existing Customer record)
- `Booking\BookingManagementController` — guest booking view/cancel via cancellation_token
- `Customer\BookingController` — authenticated customer bookings list + cancel

**4 form requests** with validation and rate limiting:
- `RegisterRequest`, `LoginRequest` (with rate limiting), `AcceptInvitationRequest`, `CustomerRegisterRequest`

**1 middleware:**
- `EnsureUserHasRole` — checks admin/collaborator via BusinessUser pivot, customer via Customer record

**1 service:**
- `SlugService` — generates unique business slugs with reserved slug blocklist

**2 notifications:**
- `MagicLinkNotification`, `InvitationNotification` — plain email (Session 10 adds templates)

### Frontend

**10 React pages** (2 rewrites + 8 new) using COSS UI + Inertia `useForm()`:
- `auth/register.tsx` — business registration with business_name field
- `auth/login.tsx` — email/password with remember me, links to magic-link and forgot-password
- `auth/magic-link.tsx` — email input for magic link request
- `auth/verify-email.tsx` — verification notice with resend button
- `auth/forgot-password.tsx` — password reset request
- `auth/reset-password.tsx` — new password form
- `auth/accept-invitation.tsx` — collaborator invite acceptance
- `auth/customer-register.tsx` — customer password registration
- `bookings/show.tsx` — guest booking details + cancel button
- `customer/bookings.tsx` — authenticated customer bookings list (upcoming + past)

**Updated:**
- `authenticated-layout.tsx` — added logout button
- `types/index.d.ts` — added Business, BookingDetail, BookingSummary, InvitationData interfaces
- `HandleInertiaRequests` — shares `auth.role`, `auth.business`, `auth.email_verified`
- `lang/en.json` — 60+ new translation keys
- `components/input-error.tsx` — reusable validation error display

### Routes (25 total)

| Route | Purpose |
|-------|---------|
| GET/POST `/register` | Business registration |
| GET/POST `/login` | Login |
| POST `/logout` | Logout |
| GET/POST `/magic-link` | Magic link request |
| GET `/magic-link/verify/{user}` | Magic link verification (signed) |
| GET/POST `/forgot-password` | Password reset request |
| GET `/reset-password/{token}`, POST `/reset-password` | Password reset |
| GET `/email/verify` | Verification notice |
| GET `/email/verify/{id}/{hash}` | Verify email (signed) |
| POST `/email/verification-notification` | Resend verification |
| GET/POST `/invite/{token}` | Collaborator invite acceptance |
| GET/POST `/customer/register` | Customer registration |
| GET `/dashboard` | Dashboard (auth + verified + role:admin,collaborator) |
| GET `/my-bookings`, POST `/my-bookings/{booking}/cancel` | Customer bookings |
| GET `/bookings/{token}`, POST `/bookings/{token}/cancel` | Guest booking management |

---

## Current Project State

- **Backend**: 17 migrations, 11 models, 3 services, 1 DTO, 9 controllers, 4 form requests, 2 notifications, 2 custom middleware
- **Frontend**: 14 pages, 2 layouts, 55 COSS UI components, 1 helper component
- **Tests**: 163 passing (349 assertions)
- **Build**: `npm run build` succeeds, `npx tsc --noEmit` clean, `vendor/bin/pint` clean

---

## Key Conventions Established

- **Auth controllers** in `App\Http\Controllers\Auth\` namespace
- **Form requests** in `App\Http\Requests\Auth\` namespace
- **Notifications** in `App\Notifications\` namespace
- **Role middleware**: `role:admin,collaborator` on dashboard routes, `role:customer` on customer routes
- **Email verification**: `verified` middleware on dashboard routes; collaborators verified on invite acceptance, customers verified via magic link or on password registration
- **Magic link one-time use**: Token stored on User, cleared after use, new request invalidates old
- **Business creation at registration**: minimal Business (name + slug) — onboarding wizard (Session 6) completes the profile
- **Reserved slugs**: maintained in `SlugService::RESERVED_SLUGS` constant
- **InputError component**: `resources/js/components/input-error.tsx` for displaying validation errors
- **Inertia useForm**: all auth forms use `useForm()` with typed form data

---

## What Session 6 Needs to Know

Session 6 implements the business onboarding wizard shown after registration.

- **After registration**, users are redirected to `/email/verify`. After verification, they go to `/dashboard`. Session 6 should intercept unboarded users and redirect to the wizard instead.
- **Minimal Business created at registration**: only `name` and `slug` are set. The wizard must fill in: description, logo, contact info (phone, email, address), timezone (if not Europe/Zurich).
- **Business hours**: the wizard sets up weekly working hours (BusinessHour model already exists from Session 2).
- **First service**: wizard creates at least one Service (model exists from Session 2).
- **Invite collaborators**: wizard uses the `BusinessInvitation` model and `InvitationNotification` built in Session 5.
- **Slug is already generated**: the wizard's slug field should allow editing with live availability check. Use `SlugService` for validation.
- **Auth context**: `auth.user`, `auth.role` ('admin'), `auth.business` are available via Inertia shared props.
- **User is always the admin**: the wizard runs in the context of a verified admin user.

---

## Decisions Recorded

- **D-035**: Same `web` guard for all user types with role-based middleware
- **D-036**: `business_invitations` table for collaborator invites (no pre-created users)
- **D-037**: Magic link one-time use via `magic_link_token` column on users table
- **D-038**: Email verification required for business dashboard access
- **D-039**: Reserved slug blocklist for business registration

---

## Open Questions / Deferred Items

- **Chunk size warning**: Vite build produces a 607 KB JS bundle. Not a problem for MVP but code splitting can be added later.
- **Customer bookings page layout**: currently uses `GuestLayout` (centered card). Could benefit from a dedicated `CustomerLayout` in a future session.
- **Admin UI for sending invites**: backend is built (model, notification, factory) but no admin-facing UI. Session 9 builds the collaborator management settings.
- **Custom email templates**: all notifications use plain Laravel mail. Session 10 replaces with branded templates.
