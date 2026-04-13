# Session 5 Plan — Authentication

## Context

Sessions 1-4 built the data layer, scheduling engine, and frontend foundation. The project has placeholder auth pages but no auth logic. This session implements the full custom authentication system (no Fortify, no Jetstream per D-001) covering three user types: business owners/admins, collaborators, and customers.

## Goal

Implement complete authentication with registration, login, magic links, email verification, password reset, collaborator invites, role middleware, and guest booking management — all with React/COSS UI pages.

## Prerequisites

- All 110 tests pass (verified)
- Session 4 complete: Inertia + React + COSS UI foundation in place
- Placeholder pages exist for `/login`, `/register`, `/dashboard`

## Scope

**Included:**
- Business owner registration (creates User + minimal Business + admin pivot)
- Email + password login/logout
- Magic link login (business users + customers)
- Email verification (required for dashboard)
- Password reset via email
- Collaborator invite flow (invitations table, accept page)
- Customer password registration
- Role middleware: admin, collaborator, customer
- Guest booking management via cancellation_token URL
- Minimal "My Bookings" page for authenticated customers
- All auth pages in React with COSS UI

**Not included:**
- Onboarding wizard (Session 6)
- Admin UI for sending invites (Session 9 — we build the backend + acceptance page only)
- Notification templates/styling (Session 10)
- Rate limiting on public booking routes (Session 7)

## New Decisions

- **D-035**: Same `web` guard for all user types with role-based middleware
- **D-036**: `business_invitations` table for collaborator invites (no pre-created users)
- **D-037**: Magic link one-time use via `magic_link_token` column on users table
- **D-038**: Email verification required for business dashboard access
- **D-039**: Reserved slug blocklist for business registration

---

## Implementation Steps

### Phase 1: Migrations

**Step 1.1** — Make password nullable on users table
- File: `database/migrations/2026_04_13_000001_make_password_nullable_on_users_table.php`
- `$table->string('password')->nullable()->change()`
- Critical for: magic-link-only customers, collaborators before accepting invite

**Step 1.2** — Add magic_link_token to users table
- File: `database/migrations/2026_04_13_000002_add_magic_link_token_to_users_table.php`
- `$table->string('magic_link_token')->nullable()` after `remember_token`

**Step 1.3** — Create business_invitations table
- File: `database/migrations/2026_04_13_000003_create_business_invitations_table.php`
- Schema: id, business_id (FK cascade), email (string), role (string, default 'collaborator'), token (string, unique), expires_at (datetime), accepted_at (datetime nullable), timestamps
- Index on `['business_id', 'email']`

### Phase 2: Models & Factories

**Step 2.1** — Update User model (`app/Models/User.php`)
- Implement `MustVerifyEmail` interface
- Add `magic_link_token` to `#[Fillable]`
- Add helper: `hasBusinessRole(string ...$roles): bool` — checks BusinessUser pivot
- Add helper: `isCustomer(): bool` — checks Customer record exists
- Add helper: `currentBusiness(): ?Business` — returns first business (MVP: users have one business)

**Step 2.2** — Create BusinessInvitation model
- File: `app/Models/BusinessInvitation.php`
- Fillable: business_id, email, role, token, expires_at, accepted_at
- Casts: role → BusinessUserRole, expires_at → datetime, accepted_at → datetime
- Relationship: `business()` BelongsTo
- Helpers: `isExpired()`, `isAccepted()`, `isPending()`

**Step 2.3** — Create BusinessInvitationFactory
- File: `database/factories/BusinessInvitationFactory.php`
- Default: pending, token via Str::random(64), expires_at = 48h from now

**Step 2.4** — Add `withoutPassword()` state to UserFactory
- File: `database/factories/UserFactory.php`

### Phase 3: SlugService

**Step 3.1** — Create SlugService
- File: `app/Services/SlugService.php`
- Method: `generateUniqueSlug(string $name): string`
  - Base: `Str::slug($name)`
  - Check against reserved slugs constant
  - Check uniqueness against `businesses` table
  - If taken: append `-2`, `-3`, etc.
- Reserved slugs: `login, register, dashboard, bookings, my-bookings, api, admin, settings, billing, invite, forgot-password, reset-password, email, customer, magic-link, logout, up, health, embed, widget, about, help, terms, privacy, pricing`

### Phase 4: Notifications

**Step 4.1** — Create MagicLinkNotification
- File: `app/Notifications/MagicLinkNotification.php`
- Receives signed URL in constructor
- Plain email: subject "Your login link", body with link + 15-min expiry note

**Step 4.2** — Create InvitationNotification
- File: `app/Notifications/InvitationNotification.php`
- Receives BusinessInvitation + business name
- Plain email: subject "Join {business} on riservo", body with accept link

Note: VerifyEmail and ResetPassword use Laravel defaults. Session 10 customizes templates.

### Phase 5: Form Requests

**Step 5.1** — RegisterRequest (`app/Http/Requests/Auth/RegisterRequest.php`)
- Rules: name (required|string|max:255), email (required|email|unique:users), password (required|min:8|confirmed), business_name (required|string|max:255)

**Step 5.2** — LoginRequest (`app/Http/Requests/Auth/LoginRequest.php`)
- Rules: email (required|email), password (required|string)
- Method: `authenticate()` — calls Auth::attempt with rate limiting (5 per minute per email+IP)
- Method: `ensureIsNotRateLimited()` — uses RateLimiter facade

**Step 5.3** — AcceptInvitationRequest (`app/Http/Requests/Auth/AcceptInvitationRequest.php`)
- Rules: name (required|string|max:255), password (required|min:8|confirmed)

**Step 5.4** — CustomerRegisterRequest (`app/Http/Requests/Auth/CustomerRegisterRequest.php`)
- Rules: name (required|string|max:255), email (required|email|exists:customers,email), password (required|min:8|confirmed)
- Note: `exists:customers,email` ensures customer has previously booked

### Phase 6: Middleware

**Step 6.1** — Create EnsureUserHasRole middleware
- File: `app/Http/Middleware/EnsureUserHasRole.php`
- `handle(Request, Closure, string ...$roles)`
- For `admin`/`collaborator`: checks `$user->hasBusinessRole($role)`
- For `customer`: checks `$user->isCustomer()`
- Any match passes; abort 403 if none match

**Step 6.2** — Register middleware in `bootstrap/app.php`
- Add alias: `'role' => EnsureUserHasRole::class`
- Laravel's built-in `auth`, `guest`, `verified`, `signed`, `throttle` are used as-is

### Phase 7: Controllers

**Step 7.1** — RegisterController (`app/Http/Controllers/Auth/RegisterController.php`)
- `create()`: render `auth/register`
- `store(RegisterRequest)`:
  1. Create User (hashed password)
  2. Create Business (name + slug via SlugService, timezone Europe/Zurich defaults)
  3. Attach User as admin via BusinessUser pivot
  4. Auth::login($user)
  5. Trigger verification notification
  6. Redirect to `verification.notice`

**Step 7.2** — LoginController (`app/Http/Controllers/Auth/LoginController.php`)
- `create()`: render `auth/login`
- `store(LoginRequest)`:
  1. $request->authenticate() (handles rate limiting)
  2. $request->session()->regenerate()
  3. Redirect: business users → `/dashboard`, customers → `/my-bookings`
- `destroy(Request)`: logout, invalidate session, regenerate token, redirect `/`

**Step 7.3** — MagicLinkController (`app/Http/Controllers/Auth/MagicLinkController.php`)
- `create()`: render `auth/magic-link`
- `store(Request)`:
  1. Validate email
  2. Find User by email — OR find Customer by email → auto-create User (null password) if customer.user_id is null, link to Customer
  3. If found: generate random token, store on user, create temporarySignedRoute (15 min), send MagicLinkNotification
  4. Always redirect with same success message (prevent email enumeration)
- `verify(Request, User $user)`:
  1. Route has `signed` middleware (handles signature + expiry)
  2. Check $user->magic_link_token === $request->query('token') and not null
  3. Clear magic_link_token
  4. Mark email as verified if not already
  5. Auth::login($user, remember: true)
  6. Session regenerate
  7. Redirect based on role

**Step 7.4** — EmailVerificationController (`app/Http/Controllers/Auth/EmailVerificationController.php`)
- `notice()`: render `auth/verify-email` (if not verified; if verified, redirect to dashboard)
- `verify(EmailVerificationRequest)`: $request->fulfill(), redirect to dashboard
- `resend(Request)`: send notification, redirect back with flash, throttle 6 per minute

**Step 7.5** — PasswordResetController (`app/Http/Controllers/Auth/PasswordResetController.php`)
- `create()`: render `auth/forgot-password`
- `store(Request)`: validate email, Password::sendResetLink(), redirect back with status
- `edit(string $token)`: render `auth/reset-password` with token + email from query
- `update(Request)`: validate, Password::reset(), redirect to login

**Step 7.6** — InvitationController (`app/Http/Controllers/Auth/InvitationController.php`)
- `show(string $token)`: find invitation, abort 404/410, render `auth/accept-invitation`
- `accept(AcceptInvitationRequest, string $token)`:
  1. Find invitation, validate pending
  2. Create User (name from request, email from invitation, password, email_verified_at = now)
  3. Create BusinessUser pivot with invitation role
  4. Mark invitation accepted
  5. Auth::login, redirect to dashboard

**Step 7.7** — CustomerRegisterController (`app/Http/Controllers/Auth/CustomerRegisterController.php`)
- `create()`: render `auth/customer-register`
- `store(CustomerRegisterRequest)`:
  1. Find Customer by email
  2. If customer.user_id already set: redirect to login with error
  3. Create User (name, email, password, email_verified_at = now)
  4. Link Customer.user_id
  5. Auth::login, redirect to `/my-bookings`

**Step 7.8** — BookingManagementController (`app/Http/Controllers/Booking/BookingManagementController.php`)
- `show(string $token)`: find Booking by cancellation_token, load relations, render `bookings/show`
- `cancel(string $token)`:
  1. Find booking
  2. Check status is pending or confirmed
  3. Check cancellation window: if `starts_at - now < business.cancellation_window_hours` → abort 403
  4. Update status to cancelled, redirect back with success

**Step 7.9** — CustomerBookingController (`app/Http/Controllers/Customer/BookingController.php`)
- `index()`: find Customer by auth user, load bookings with relations, separate upcoming/past, render `customer/bookings`
- `cancel(Booking $booking)`: verify ownership, check window, cancel, redirect back

### Phase 8: Routes

**Step 8.1** — Rewrite `routes/web.php`

```
// Public
GET  /                          → welcome page

// Guest-only (redirect if authenticated)
GET  /register                  → RegisterController@create
POST /register                  → RegisterController@store
GET  /login                     → LoginController@create
POST /login                     → LoginController@store
GET  /magic-link                → MagicLinkController@create
POST /magic-link                → MagicLinkController@store
GET  /forgot-password           → PasswordResetController@create
POST /forgot-password           → PasswordResetController@store
GET  /reset-password/{token}    → PasswordResetController@edit
POST /reset-password            → PasswordResetController@update
GET  /invite/{token}            → InvitationController@show
POST /invite/{token}            → InvitationController@accept
GET  /customer/register         → CustomerRegisterController@create
POST /customer/register         → CustomerRegisterController@store

// Magic link verify (works for any user, signed)
GET  /magic-link/verify/{user}  → MagicLinkController@verify [signed]

// Authenticated
POST /logout                    → LoginController@destroy
GET  /email/verify              → EmailVerificationController@notice [verification.notice]
GET  /email/verify/{id}/{hash}  → EmailVerificationController@verify [signed]
POST /email/verification-notification → EmailVerificationController@resend [throttle:6,1]

// Dashboard (auth + verified + role:admin,collaborator)
GET  /dashboard                 → dashboard page

// Customer area (auth + role:customer)
GET  /my-bookings               → CustomerBookingController@index
POST /my-bookings/{booking}/cancel → CustomerBookingController@cancel

// Guest booking management (no auth)
GET  /bookings/{token}          → BookingManagementController@show
POST /bookings/{token}/cancel   → BookingManagementController@cancel
```

### Phase 9: HandleInertiaRequests Update

**Step 9.1** — Expand shared props in `app/Http/Middleware/HandleInertiaRequests.php`

Add to auth shared data:
- `auth.role`: resolve from BusinessUser pivot (admin/collaborator) or customer check
- `auth.business`: {id, name, slug} from user's business (if business user)
- `auth.email_verified`: boolean

### Phase 10: TypeScript Types

**Step 10.1** — Update `resources/js/types/index.d.ts`

```typescript
interface User { id, name, email, avatar }
interface Business { id, name, slug }
interface PageProps {
  auth: {
    user: User | null
    role: 'admin' | 'collaborator' | 'customer' | null
    business: Business | null
    email_verified: boolean
  }
  flash: { success, error }
  locale, translations
}
```

Add interfaces for page-specific props: BookingDetail, CustomerBookingList, InvitationData.

### Phase 11: Frontend Pages

All use GuestLayout (except customer/bookings), `useTrans()` hook, Inertia `useForm()`, COSS UI components (Button, Input, Field, FieldLabel, FieldError, Card, Checkbox, Alert, Separator).

**Step 11.1** — Rewrite `resources/js/pages/auth/register.tsx`
- Fields: name, email, business_name, password, password_confirmation
- `form.post('/register')` on submit
- Validation errors via form.errors
- Link to login

**Step 11.2** — Rewrite `resources/js/pages/auth/login.tsx`
- Fields: email, password, remember (checkbox)
- `form.post('/login')`
- Links to: register, forgot-password, magic-link

**Step 11.3** — Create `resources/js/pages/auth/magic-link.tsx`
- Field: email
- `form.post('/magic-link')`
- Success message after submission
- Link back to login

**Step 11.4** — Create `resources/js/pages/auth/verify-email.tsx`
- "Check your email" message
- Resend button: `form.post('/email/verification-notification')`
- Logout link

**Step 11.5** — Create `resources/js/pages/auth/forgot-password.tsx`
- Field: email
- `form.post('/forgot-password')`
- Success message
- Link to login

**Step 11.6** — Create `resources/js/pages/auth/reset-password.tsx`
- Props: token, email
- Fields: email (readonly), password, password_confirmation + hidden token
- `form.post('/reset-password')`

**Step 11.7** — Create `resources/js/pages/auth/accept-invitation.tsx`
- Props: invitation (business_name, email, role, token)
- Fields: name, password, password_confirmation (email shown readonly)
- `form.post('/invite/{token}')`

**Step 11.8** — Create `resources/js/pages/auth/customer-register.tsx`
- Fields: name, email, password, password_confirmation
- `form.post('/customer/register')`
- Link to magic-link

**Step 11.9** — Create `resources/js/pages/bookings/show.tsx`
- Props: booking (with service, collaborator, business, canCancel)
- Display booking details
- Cancel button (if canCancel)
- `form.post('/bookings/{token}/cancel')`

**Step 11.10** — Create `resources/js/pages/customer/bookings.tsx`
- Props: bookings (upcoming + past arrays)
- List of bookings with details
- Cancel button on upcoming cancellable bookings
- Uses AuthenticatedLayout or simple standalone layout

**Step 11.11** — Update `resources/js/layouts/authenticated-layout.tsx`
- Add logout button (POST form to /logout)
- Update navigation based on role

### Phase 12: Translations

**Step 12.1** — Add keys to `lang/en.json`
- All new user-facing strings: form labels, buttons, messages, errors, page titles

### Phase 13: Testing

Feature tests in Pest. Each test file uses RefreshDatabase and $this->withoutVite().

| Test File | Key Cases |
|-----------|-----------|
| `tests/Feature/Auth/RegisterTest.php` | Valid registration creates User+Business+pivot, validation errors, slug generation, reserved slugs blocked, email sent |
| `tests/Feature/Auth/LoginTest.php` | Valid login, invalid credentials, rate limiting, logout, role-based redirect |
| `tests/Feature/Auth/MagicLinkTest.php` | Request sends email, valid link logs in, expired rejected, one-time use, customer auto-creates User |
| `tests/Feature/Auth/EmailVerificationTest.php` | Unverified blocked from dashboard, verify via link, resend works, throttled |
| `tests/Feature/Auth/PasswordResetTest.php` | Request link, reset with valid token, invalid/expired token rejected |
| `tests/Feature/Auth/InvitationTest.php` | Accept creates User+pivot, expired rejected, already-accepted rejected, email verified on accept |
| `tests/Feature/Auth/CustomerRegisterTest.php` | Links to existing Customer, fails if already linked, validation |
| `tests/Feature/Auth/MiddlewareTest.php` | Admin/collaborator/customer access correct routes, wrong role 403, guest redirected |
| `tests/Feature/Booking/BookingManagementTest.php` | View by token, cancel, cancellation window enforced, invalid token 404 |
| `tests/Feature/Customer/BookingTest.php` | See own bookings, cancel, can't cancel others', upcoming/past separation |
| `tests/Unit/Services/SlugServiceTest.php` | Slug generation, uniqueness, reserved slugs |

### Phase 14: Verification

1. Run all existing tests (110 must still pass)
2. Run all new tests
3. `npm run build` succeeds
4. `npx tsc --noEmit` passes
5. `vendor/bin/pint --dirty --format agent`
6. Manual browser test of: register → verify email → login → dashboard → logout → magic link → password reset

---

## File List

### New Files (~35)
- `database/migrations/2026_04_13_*` (3 migrations)
- `app/Models/BusinessInvitation.php`
- `database/factories/BusinessInvitationFactory.php`
- `app/Services/SlugService.php`
- `app/Notifications/MagicLinkNotification.php`
- `app/Notifications/InvitationNotification.php`
- `app/Http/Requests/Auth/RegisterRequest.php`
- `app/Http/Requests/Auth/LoginRequest.php`
- `app/Http/Requests/Auth/AcceptInvitationRequest.php`
- `app/Http/Requests/Auth/CustomerRegisterRequest.php`
- `app/Http/Middleware/EnsureUserHasRole.php`
- `app/Http/Controllers/Auth/RegisterController.php`
- `app/Http/Controllers/Auth/LoginController.php`
- `app/Http/Controllers/Auth/MagicLinkController.php`
- `app/Http/Controllers/Auth/EmailVerificationController.php`
- `app/Http/Controllers/Auth/PasswordResetController.php`
- `app/Http/Controllers/Auth/InvitationController.php`
- `app/Http/Controllers/Auth/CustomerRegisterController.php`
- `app/Http/Controllers/Booking/BookingManagementController.php`
- `app/Http/Controllers/Customer/BookingController.php`
- `resources/js/pages/auth/magic-link.tsx`
- `resources/js/pages/auth/verify-email.tsx`
- `resources/js/pages/auth/forgot-password.tsx`
- `resources/js/pages/auth/reset-password.tsx`
- `resources/js/pages/auth/accept-invitation.tsx`
- `resources/js/pages/auth/customer-register.tsx`
- `resources/js/pages/bookings/show.tsx`
- `resources/js/pages/customer/bookings.tsx`
- `tests/Feature/Auth/*.php` (8 test files)
- `tests/Feature/Booking/BookingManagementTest.php`
- `tests/Feature/Customer/BookingTest.php`
- `tests/Unit/Services/SlugServiceTest.php`

### Modified Files (~8)
- `app/Models/User.php` — MustVerifyEmail, magic_link_token, helper methods
- `database/factories/UserFactory.php` — withoutPassword state
- `routes/web.php` — full rewrite
- `bootstrap/app.php` — middleware alias
- `app/Http/Middleware/HandleInertiaRequests.php` — expanded shared props
- `resources/js/types/index.d.ts` — new types
- `resources/js/pages/auth/login.tsx` — rewrite with form logic
- `resources/js/pages/auth/register.tsx` — rewrite with form logic
- `resources/js/layouts/authenticated-layout.tsx` — logout + role nav
- `lang/en.json` — new translation keys
