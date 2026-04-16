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

---

### D-063 — Explicit tenant context per request
- **Date**: 2026-04-16
- **Status**: accepted
- **Supersedes**: implicitly the undocumented "first attached business" convention used by the removed `User::currentBusiness()` helper.
- **Context**: `User::currentBusiness()` returned `$this->businesses()->first()` — non-deterministic when a user belonged to more than one business, and not session-pinned. Role middleware authorised against "any active membership with this role" rather than "this role in the currently-active business", so a user who was admin in Business A and staff in Business B passed `role:admin` checks regardless of which business the subsequent controller was writing to. Shared Inertia props inherited the same "first" semantics. Multi-business membership is a plausible post-MVP product capability (a freelancer at two salons), so enforcing one-business-per-user would close off a real scenario.
- **Decision**: Introduce `App\Support\TenantContext` as the authoritative per-request source for the active business and the user's role within it. `App\Http\Middleware\ResolveTenantContext` populates it after `auth` from a session-pinned `current_business_id`, self-healing to the user's oldest active membership (ordered by `business_members.created_at`, then `id`) when the session value is missing or stale. `User::currentBusiness()` and `User::currentBusinessRole()` are removed. A `tenant()` global helper exposes the context to controllers, Form Requests, and views. `EnsureUserHasRole` authorises against `tenant()->role()`, not "any business". `EnsureOnboardingComplete` reads `tenant()->business()` for its redirect decision. Shared Inertia props (`auth.business`, `auth.role`) resolve through `tenant()`. `LoginController::store`, `MagicLinkController::verify`, `RegisterController::store`, and `InvitationController::accept` pin the session's `current_business_id` explicitly.
- **Consequences**:
  - Authorization and scoping share the same tenant, eliminating the divergence risk.
  - Users with multiple memberships are correctly scoped to whichever business their session pins.
  - Multi-business membership remains a **data-model capability** but is not yet reachable through any product flow; the business-switcher UX is tracked as the R-2B follow-up.
  - Every controller that read `$user->currentBusiness()` is migrated to `tenant()->business()`. The name change makes cross-tenant leakage a compile-error-visible concern in future work.
  - Deploy-day existing sessions are safe: they lack `current_business_id`, and the middleware self-heals by writing the user's oldest active membership into session on first post-deploy request.

---

### D-064 — `BelongsToCurrentBusiness` validation rule for tenant-scoped foreign keys
- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: Foreign-key validation to business-owned data was implemented as eight near-identical closures inside Form Requests, plus two plain `exists:` rules that trusted the client (`StoreInvitationsRequest` for `invitations.*.service_ids.*`; `StoreManualBookingRequest` for `service_id`). The pattern was duplicated, easy to regress, and two bypass paths remained.
- **Decision**: Introduce `App\Rules\BelongsToCurrentBusiness` — a reusable `ValidationRule` implementation that takes a model class (class-string), queries it scoped to `tenant()->businessId()`, and respects the model's own `SoftDeletes` scope by default. All Form Requests that name a business-owned FK now use this rule, with the only exception being `StorePublicBookingRequest` (public, unauthenticated, business resolved from URL slug — keeps its inline closure). Controllers that persist FK arrays (`business_invitations.service_ids` written by both `StaffController::invite` and `OnboardingController::storeInvitations`) re-filter the IDs through the owning business's Eloquent relation before writing, as defense-in-depth.
- **Consequences**:
  - The cross-tenant FK surface shrinks to one class plus one slug-scoped closure for the public endpoint.
  - New Form Requests adopting the pattern is a one-line import.
  - Soft-deleted providers / services are invisible to the rule by default — matching the "cannot attach a deactivated provider to a new booking" behaviour we already want.
  - The rule hard-depends on `TenantContext` being populated, which is guaranteed by `ResolveTenantContext` running before route validation on every authenticated request. When called outside that context (raw `Validator::make` in a unit test that has not pinned the tenant), the rule fails safely with a distinct "invalid tenant" message.

---

### D-072 — Auth-recovery POSTs are throttled per-email AND per-IP via FormRequest, configurable in `config/auth.php`

- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: Pre-R-11, `POST /magic-link` and `POST /forgot-password` had no rate limiting. Both send an email on every call and both return a generic success response to resist user enumeration (D-037 preserves the magic-link enumeration hardening). That combination means every request has a delivery cost (mailer invocation, queued job) with no defensive cap — trivially abusable for email spam and noisy account probing. Login (`POST /login`) was already throttled, but via an in-`LoginRequest` pattern keyed on `strtolower(email).'|'.ip()` with a 5-attempt cap (`LoginRequest::throttleKey()`), not via route middleware. Two precedents for throttling exist in the codebase: the LoginRequest FormRequest pattern, and the route middleware `throttle:` pattern (`POST /email/verification-notification` uses `throttle:6,1`, and `RateLimiter::for('booking-api')` / `booking-create` are registered in `AppServiceProvider::boot()` for the public booking flow).
- **Decision**:
  1. **Pattern — FormRequest-internal, matching LoginRequest.** Two new FormRequests, `SendMagicLinkRequest` and `SendPasswordResetRequest`, each with an `ensureIsNotRateLimited()` hook called early in the controller's `store()` method. This extends the existing auth-throttle precedent rather than introducing a third pattern (middleware `throttle:` + `RateLimiter::for` + FormRequest-internal would be three distinct patterns for three auth-adjacent endpoints; we keep it at two by choosing FormRequest-internal for the auth-recovery endpoints).
  2. **Segmentation — independent per-email and per-IP buckets.** Each request checks both `RateLimiter::tooManyAttempts($perEmailKey, $maxPerEmail)` AND `RateLimiter::tooManyAttempts($perIpKey, $maxPerIp)`. EITHER bucket exceeding the limit throws lockout. This closes both attack axes that a combined `email|ip` key leaves open: IP-rotating attackers can't flood one email (per-email bucket caps), and email-rotating attackers can't flood from one IP (per-IP bucket caps). The two buckets are orthogonal and additive — a legitimate user at a shared office NAT hitting the per-IP bucket with unrelated emails is accepted as a tradeoff.
  3. **Values — configurable via `config/auth.php`.** Defaults: per-email 5 attempts, per-IP 20 attempts, 15-minute decay. Rationale: 5/email/15min matches ROADMAP-REVIEW §R-11's suggestion and is permissive enough for legitimate retries (user typos + retry + check spam + retry) while tight enough that volume abuse becomes uneconomic. Per-IP 20 is four times the per-email limit — allowing a shared-IP customer-facing office to cover several different distinct users within the decay window. Values live in `config/auth.php` under `auth.throttle.{magic_link|password_reset}.{max_per_email,max_per_ip,decay_minutes}`, readable via environment variables (`THROTTLE_MAGIC_LINK_EMAIL`, etc.) so ops can tune post-launch without a deploy.
  4. **Keys** — deterministic, scoped, and independent across endpoints:
     - `magic-link:email:{strtolower(email)}`
     - `magic-link:ip:{ip}`
     - `password-reset:email:{strtolower(email)}`
     - `password-reset:ip:{ip}`
     Namespace prefix prevents the two endpoints from sharing counters (a burst against `/magic-link` does not also lock out `/forgot-password` and vice versa).
  5. **Increment semantics — always hit on invocation.** Unlike login's pattern (`RateLimiter::hit` only on failed auth + `RateLimiter::clear` on success), auth-recovery throttles hit unconditionally. The recovery endpoints always return generic success to resist enumeration (D-037); there's no "success" signal to clear on, and the limit is about volume, not failure.
  6. **Response shape** — on lockout, throw `ValidationException::withMessages(['email' => __('throttle.too_many_requests', ['minutes' => ceil($seconds / 60)])])`. A new translation key `throttle.too_many_requests` is added (not reusing `auth.throttle`, which hardcodes "Too many login attempts"). The response is Inertia-native 302-back with a validation error, identical to LoginRequest's UX. No separate `Retry-After` header is set (the translated message carries the duration).
  7. **Lockout event** — do not emit `Illuminate\Auth\Events\Lockout` for these endpoints. `Lockout` is semantically an authentication-lockout event; auth-recovery throttle is an abuse-prevention cap, not an auth lockout.
  8. **Applies to** — only `POST /magic-link` and `POST /forgot-password`. The `GET` routes for both endpoints are untouched (they render static Inertia pages, no email, no cost). The `GET /reset-password/{token}` and `POST /reset-password` (password update with token) are untouched — per-token signed URLs are the abuse guard for those.
- **Consequences**:
  - An abusive actor cannot use either endpoint as an email-bomb delivery mechanism: at 5 requests per email per 15 minutes, a burst attempt sends 5 mails and then silently rate-limits for 15 minutes.
  - Legitimate users hitting the per-email bucket (typo + retry loop) see a familiar-looking validation error on the email field with a minute count — no new UI surface to learn.
  - Legitimate users on shared IP (office NAT, mobile carrier NAT) hit the per-IP bucket last; the 20-request window covers common cases. When they do hit it, the same validation error pattern fires.
  - Cache driver dependency: Laravel's `RateLimiter` uses the default cache store; the app runs on `database` in prod and `array` in tests (already verified, no change needed).
  - The two new FormRequest files mirror `LoginRequest` in shape, making future auth-throttle additions a copy-paste (while noting that the roadmap does NOT currently demand a third such endpoint).
  - Config-driven values let ops tune without redeploying. Post-launch, if abuse telemetry shows 5/email is too tight, the env variable flip is immediate.
- **Rejected alternatives**:
  - *Route middleware `throttle:<limiter-name>` with a named `RateLimiter::for`.* Works and is tidier at the route level; rejected because it diverges from the login UX (Laravel's default `ThrottleRequests` returns a 429 JSON for JSON requests, a 429 HTML page otherwise — Inertia surfaces this as a dialog-error rather than a field-level validation error). Extending LoginRequest's pattern keeps all auth-endpoint throttle UX identical.
  - *Single combined `email|ip` bucket.* What login uses; rejected here because auth recovery's attack surface is different. Login's key works because login's success path clears the counter — one legitimate success resets the state. Auth-recovery has no success signal to clear on (generic success response resists enumeration), so a combined key means an attacker rotating either axis slips through.
  - *Only per-IP.* Rejected — caps volume but not targeted user probing.
  - *Only per-email.* Rejected — caps targeted probing but not aggregate volume from a rotating-email attacker.
  - *Putting values in a new `config/throttle.php`.* Adjacent and reasonable, but pre-existing rate-limiter registrations (`booking-api`, `booking-create`) live as hardcoded values in `AppServiceProvider`. A new config file just for two new keys creates a second convention. `config/auth.php` is auth-themed and already loaded — cleaner for now. Future consolidation into `config/throttle.php` is captured in §10.
  - *Emitting `Illuminate\Auth\Events\Lockout`.* The event listener surface is for auth-lockout reactions (logging, user notification, admin alert). Rate-limit-exceeded on a magic-link request isn't an auth lockout in the domain sense; emitting the event would add noise.
  - *Returning 429 with a friendly page.* Would require a new error template / Inertia page. Validation-error shape reuses the existing auth form rendering.

---

### D-074 — Customer registration is open; a prior booking is not required
- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: Pre-R-13, `CustomerRegisterRequest` line 23 enforced
  `email => exists:customers,email` and `CustomerRegisterController::store`
  assumed a Customer record existed for the submitted email. The page
  copy ("Register with the email you used when booking …") and error
  message ("No bookings found for this email address. You must have at
  least one booking to register.") matched the implementation. SPEC §10
  describes optional account registration as a peer offering of magic
  link, with no narrowing clause — the implementation tightened beyond
  the spec. REVIEW-1 §#14 surfaced the gap; ROADMAP-REVIEW §R-13 asked
  the planner to either widen the flow or formalise the narrowing.
- **Decision**: Customer registration is open. Any email may register
  without a prior booking. Registration creates a `User` row plus a
  `Customer` row (or links to an existing `Customer` if one exists for
  that email from a prior booking) and links them via `customer.user_id`.
  Subsequent bookings reuse the existing Customer via the
  `Customer::firstOrCreate(['email' => …])` pattern already used by
  `Dashboard\BookingController::store` and `PublicBookingController::store`.
  Validation gates: `email => unique:users,email` (no double registration)
  + standard email/password rules.
- **Consequences**:
  - SPEC §10 needs no update — the existing wording matches the new
    behaviour. The narrow-rule clause never made it into SPEC.
  - The `customers` table now permits Customer rows with zero bookings
    (a registered visitor who never books). These are invisible to
    per-business CRM (`Dashboard\CustomerController` filters via
    `whereHas('bookings', fn ($q) => $q->where('business_id', …))`),
    so no CRM noise.
  - `MagicLinkController::resolveUser` step 1 (`User::where('email',
    $email)->first()`) naturally finds users that registered without
    booking. No separate change to magic-link required.
  - `CustomerRegisterController::store` is rewritten to handle three
    cases: existing User (caught by validation `unique:users,email`),
    existing Customer no User (link Customer to new User), neither
    (create both).
  - The data model has zero migration footprint. `customers.email`
    remains globally unique (per migration `2026_04_16_100004_create_customers_table.php`).
- **Rejected alternative — Option B (keep narrow + update SPEC)**:
  - Would require adding a sentence to SPEC §10 codifying "registration
    requires prior booking", which has no product justification beyond
    the historical implementation shortcut.
  - Frustrates a class of users who want to set up an account before
    their first appointment — a normal SaaS flow.
  - Trivial implementation (copy + SPEC update only) but trades long-
    term product fit for short-term implementation savings; not worth
    it.

---

### D-075 — `MagicLinkNotification` and `InvitationNotification` dispatch via closure-after-response, not the queue
- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: Pre-R-14, both notifications used the `Queueable` trait
  but did NOT implement `ShouldQueue`, so they ran synchronously on
  the request path. SMTP latency (or, in local dev, log-driver write
  latency) sat in front of the user response for two interactive
  flows: magic-link request and staff invitation. The four
  `Booking*Notification` classes already implement `ShouldQueue` +
  `afterCommit()`; ROADMAP-REVIEW §R-14 carved them out — they stay
  on the real queue because they benefit from worker-based retries
  and async system processing. The interactive pair is different:
  user-triggered, immediate-UX, and idempotent at the user-recourse
  layer ("request another magic link", "ask the inviter to resend").
  Laravel 13 surface for "after response" was audited:
  `Notification::sendAfterResponse()` does NOT exist;
  `Job::dispatchAfterResponse()` and the closure form
  `dispatch(fn () => …)->afterResponse()` do exist (as of
  `Illuminate\Foundation\Bus\Dispatchable::dispatchAfterResponse`).
  `app()->terminating()` exists as a kernel-level alternative.
- **Decision**: Both notifications are dispatched via Laravel's
  closure-after-response pattern:
  ```php
  dispatch(function () use ($user, $url) {
      $user->notify(new MagicLinkNotification($url));
  })->afterResponse();
  ```
  Same shape applied at the four call sites
  (`MagicLinkController::store`, `Dashboard\Settings\StaffController::invite`,
  `Dashboard\Settings\StaffController::resendInvitation`,
  `OnboardingController::storeInvitations`).
- **Consequences**:
  - User-perceived response latency for these two flows drops to the
    Laravel response time alone; mail send happens after the response
    is flushed.
  - No new files (no dedicated job classes); no new abstractions.
  - No worker dependency for local dev. The mail driver (`log` per
    `.env`) writes immediately after the response cycle ends.
  - In production (Laravel Cloud), the closure runs in the web process
    after response flush, before the request worker is recycled. The
    SMTP / Postmark call happens inline within the web process.
  - Failure mode: if the closure throws, Laravel reports it via the
    default exception handler (logged) but the user has already
    received a generic-success response. For magic-link, D-037's
    enumeration-resistant generic response is already the user's
    contract — silent failure is consistent. For invitation, an admin
    can resend if mail doesn't arrive.
  - No retry semantics. Acceptable because (a) magic-link is
    re-requestable in 15 min, (b) invitation is re-sendable from the
    staff page.
  - `Booking*Notification` classes are NOT changed — they keep
    `ShouldQueue` + `afterCommit()`. ROADMAP-REVIEW §R-14 explicitly
    carves them out.
- **Rejected alternatives**:
  - *`implements ShouldQueue` (real queue)* — adds queue infrastructure
    dependency for two notifications that don't need retries. Local
    dev breaks for any contributor not running `queue:listen` (notifications
    accumulate in the `jobs` table). The user-recourse story (resend)
    already covers what queue retries would provide.
  - *Dedicated job classes via `JobClass::dispatchAfterResponse`* —
    same mechanism with extra ceremony. Two new job files for two
    notifications is YAGNI for a one-line wrapper.
  - *`app()->terminating(fn () => …)`* — bypasses `Bus`/`Notification`
    fakes; harder to test. Conventionally used for cleanup, not work.
  - *Switching only these two to the `sync` queue driver* — keeps
    `ShouldQueue` interface but means notifications still fire
    in-request. No latency improvement.
- **Branding fixes ride along (no D-NNN)**: `config/app.php` `name`
  default → `'riservo.ch'`; `.env` and `.env.example` `APP_NAME=riservo.ch`;
  `MAIL_FROM_ADDRESS="hello@riservo.ch"`. Vendor mail templates
  already read `config('app.name')` — setting the value cascades
  through layout, header, and footer templates with no per-template
  edit.
