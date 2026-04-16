# PLAN — R-12 + R-13 + R-14 + R-15: Pre-Launch Polish

> **Sources** — REVIEW-1 §#13 (welcome links + invite copy), §#14 (customer
> registration scope), §#15 (notification delivery + branding), §#16
> (dependency + URL-generation drift), §#17 (logo removal). ROADMAP-REVIEW
> §R-12, §R-13, §R-14, §R-15 (the "polish, can be one session" bundle on
> line 379).
>
> **Status** — DRAFT. Awaiting developer approval before any
> implementation.
>
> **Type** — pre-launch polish. Spans four loosely related concerns that
> the roadmap groups together because each is small, none is on the
> critical path, and all should land before customers see the product.
>
> **Decisions claimed** — D-074 (R-13), D-075 (R-14), D-076 (R-15
> storage URL). R-12 carries no D-NNN; R-15 sub-items beyond storage URL
> are mechanical. Reserved range was D-073 – D-076; this plan uses three
> of the four slots and leaves D-073 unclaimed (R-12 is mechanical, see
> §3.1).
>
> **Implementation split** — §5 recommends three sequential sessions, not
> one. Planning fits in one document; implementation does not.

---

## §1 Context

Per task brief, §1 is split strictly per-R-NN. The four items are
audited independently because their files, layers, and risk profiles do
not overlap meaningfully — the only thing they share is the "must land
before launch" deadline.

### §1.1 R-12 — Dashboard welcome links and invite-copy drift

**ROADMAP-REVIEW scope** (§R-12, lines 261-274): replace dead welcome
links with real Wayfinder routes; align all invite-expiry copy to
derive from one source.

**Files audited**

- `resources/js/pages/dashboard/welcome.tsx` (180 lines).
- `resources/js/components/settings/staff-invite-dialog.tsx` (135 lines).
- `app/Notifications/InvitationNotification.php` (40 lines).
- `app/Models/BusinessInvitation.php` (55 lines).
- `app/Http/Controllers/Dashboard/Settings/StaffController.php` (lines
  187-219 — `invite()` and `resendInvitation()`).
- `app/Http/Controllers/OnboardingController.php` (line 427 —
  `storeInvitations()`).
- `routes/web.php` (settings sub-tree).
- `tests/Feature/Onboarding/WelcomePageTest.php` (33 lines).

**Findings**

1. **Welcome-page dead-link drift is partial, not total.**
   `welcome.tsx` lines 37-62 hardcode three "next steps" anchors:
   - `/dashboard/settings/services` — **route exists**
     (`settings.services`, `dashboard/settings/services`).
   - `/dashboard/settings/staff` — **route exists**
     (`settings.staff`, `dashboard/settings/staff`). Audit drift
     vs REVIEW-1, which named `/dashboard/settings/team` — that
     URL was renamed to `/staff` (consistent with the "staff"
     terminology landed by D-061). The link survived the rename;
     REVIEW-1 was reading the post-D-061 codebase and the URL
     listed in #13 ("/dashboard/settings/team") never existed in
     the audited code. So one of the two REVIEW-1 dead links is
     actually a misreading; only the second one is real.
   - `/dashboard/settings/notifications` — **route does NOT exist**
     (`php artisan route:list --path=dashboard/settings` confirms 37
     routes; none match `notifications`). Dead.

2. **All three anchors are hardcoded strings, not Wayfinder route
   functions.** `wayfinder-development` skill mandates Wayfinder for
   all controller-targeted links; the welcome page violates this by
   inlining string paths. This compounds the drift problem — a future
   route rename does not fail to compile.

3. **Invite-expiry copy drift is real and three-way.**
   - `StaffController::invite` line 193: `now()->addHours(48)`.
   - `StaffController::resendInvitation` line 212: `now()->addHours(48)`.
   - `OnboardingController::storeInvitations` line 427:
     `now()->addHours(48)`.
   - `InvitationNotification::toMail` line 38:
     `__('This invitation expires in 48 hours.')` — hardcoded literal.
   - `staff-invite-dialog.tsx` line 58:
     `__('They receive an email to set up a password. The invite expires in 7 days.')`
     — **wrong by 5 days**. The user reads "7 days" in the UI, the
     email reads "48 hours", and the row expires after 48 hours.
   - `BusinessInvitation` model has no `EXPIRY_HOURS` constant or
     any centralised lifetime accessor; the magic number `48` is
     duplicated four times.

4. **No Inertia prop carries the lifetime value to the dialog.** The
   staff page (`settings/staff`) is rendered by `StaffController::index`
   (file lines 21-65 audited but not read in full — confirmed via
   route map). The dialog gets `services` only; the "expires in 7
   days" string is a frontend literal. Backend has no escape hatch to
   correct it without a frontend change.

**Decision implication.** Two changes: (a) replace dead/hardcoded links
with Wayfinder route functions, (b) extract the lifetime literal to
one source-of-truth and reference it from controller, model accessor,
notification, and the dialog. Both are mechanical. The choice "model
constant vs config key" is small — model constant is used. **No D-NNN
claimed for R-12.**

### §1.2 R-13 — Customer registration scope

**ROADMAP-REVIEW scope** (§R-13, lines 277-294): widen the flow so a
customer can register without a prior booking, OR keep the current
"existing customer only" rule and update SPEC + UI copy to match.

**Files audited**

- `app/Http/Controllers/Auth/CustomerRegisterController.php` (44 lines).
- `app/Http/Requests/Auth/CustomerRegisterRequest.php` (37 lines).
- `app/Http/Controllers/Auth/MagicLinkController.php` (122 lines).
- `app/Models/Customer.php` (33 lines).
- `database/migrations/2026_04_16_100004_create_customers_table.php`
  (25 lines).
- `resources/js/pages/auth/customer-register.tsx` (85 lines).
- `docs/SPEC.md` §10 (lines 314-343, "Authentication & Authorization").

**Findings**

1. **The narrowing is enforced at the FormRequest layer.**
   `CustomerRegisterRequest::rules` line 23 has
   `'email' => ['required', 'string', 'email', 'exists:customers,email']`.
   The `exists:customers,email` rule is the gate.

2. **The error message is on-brand for the narrow flow.**
   Line 34: "No bookings found for this email address. You must have
   at least one booking to register." Direct and clear; not accidental
   wording.

3. **The page copy reinforces the narrow flow.**
   `customer-register.tsx` line 20: "Register with the email you used
   when booking to manage all your appointments." The copy assumes a
   prior booking.

4. **The controller is minimal and assumes the rule held.**
   `CustomerRegisterController::store` line 23 calls `Customer::where(…)->first()`
   without nullable handling. If the FormRequest rule is removed, this
   line nulls and the next line crashes. **The controller needs a
   rewrite either way** — Option A widens the rule; Option B keeps it
   but the controller is also unsafe if the rule is ever weakened
   (defensive code is missing).

5. **The data model supports either option without migration.**
   `customers.email` is **globally unique** (migration line 14:
   `$table->string('email')->unique()`). No `business_id` column on
   `customers`. So one Customer row represents one email across the
   whole platform; per-business CRM (`Dashboard\CustomerController`
   lines 22, 122) filters via `whereHas('bookings', fn ($q) => $q->where('business_id', $business->id))`.
   Consequence: a Customer row with **zero bookings** is invisible
   to every business CRM and harmless to every booking flow. Option A
   (widen) does not pollute the per-business CRM view.

6. **Magic-link registration is also gated by Customer existence.**
   `MagicLinkController::resolveUser` lines 91-121: returns `null`
   silently when neither User nor Customer exists for the email. This
   is consistent with the current narrow rule (both auth surfaces
   refuse "fresh" emails). Under Option A, magic-link continues to
   work for any registered User by step 1 (`User::where('email', $email)->first()`),
   so the widened registration unlocks magic-link too — no separate
   change needed in `MagicLinkController`.

7. **SPEC §10 reads as widened.** Lines 333-334:
   - "Magic link by default: customers receive a signed URL via email
     to access their booking management area — no password required"
   - "Optional account registration (email + password) for customers
     who prefer it"
   The phrase "Optional account registration … for customers who prefer
   it" reads as a peer to magic-link, not "registration is locked
   behind a prior booking." The current implementation is a tightening
   that is not described by the spec. There is no current sentence in
   SPEC §10 saying "registration requires a prior booking."

**Decision implication.** Genuine product fork. Both options are
viable; SPEC reads more like Option A but the current code says
Option B. **D-074 claimed.** Recommendation argued in §3.2.

### §1.3 R-14 — Notification delivery + branding

**ROADMAP-REVIEW scope** (§R-14, lines 298-313): get
`InvitationNotification` and `MagicLinkNotification` off the request
path via post-response delivery; update `config/app.php` name and
`config/mail.php` from to riservo-branded; purge any "Laravel" branding
from notification views.

**Files audited**

- `app/Notifications/InvitationNotification.php` (40 lines).
- `app/Notifications/MagicLinkNotification.php` (34 lines).
- `app/Notifications/BookingConfirmedNotification.php` (47 lines).
- `app/Notifications/BookingReminderNotification.php` (48 lines).
- `app/Notifications/BookingReceivedNotification.php` (60 lines).
- `app/Notifications/BookingCancelledNotification.php` (56 lines).
- `config/app.php` (127 lines).
- `config/mail.php` lines 1-118 read; relevant block lines 113-116.
- `.env` and `.env.example` (relevant lines: `APP_NAME`, `MAIL_FROM_ADDRESS`,
  `MAIL_FROM_NAME`).
- `resources/views/vendor/mail/html/*.blade.php` and `text/*.blade.php`
  (16 files, all from `php artisan vendor:publish --tag=laravel-mail`).
- `resources/views/mail/*.blade.php` (4 markdown templates: `booking-confirmed`,
  `booking-reminder`, `booking-received`, `booking-cancelled`).
- Vendor source: `vendor/laravel/framework/src/Illuminate/Notifications/`
  (no `sendAfterResponse` method on `Notification` facade — confirmed
  via grep). `dispatchAfterResponse` exists on jobs (`Bus`, `Dispatchable`,
  `PendingDispatch`) — confirmed at
  `vendor/laravel/framework/src/Illuminate/Foundation/Bus/Dispatchable.php:85`.

**Findings**

1. **The two interactive notifications are NOT queued.**
   - `InvitationNotification` extends `Notification` and `use Queueable`,
     but does NOT implement `ShouldQueue`. Runs synchronously on the
     request path. Confirmed at line 11: `class InvitationNotification extends Notification`.
   - `MagicLinkNotification` same shape. Confirmed at line 9.
   The `Queueable` trait is imported but inert without `ShouldQueue`.

2. **The four booking notifications ARE queued + after-commit.**
   All four (`BookingConfirmedNotification`, `BookingReminderNotification`,
   `BookingReceivedNotification`, `BookingCancelledNotification`)
   `implements ShouldQueue` and call `$this->afterCommit()` in the
   constructor. Per ROADMAP-REVIEW §R-14: these stay on the real
   queue. R-14 does not change them.

3. **`config/app.php` line 16 default is still `'Laravel'`.**
   `'name' => env('APP_NAME', 'Laravel')`.

4. **`.env` line 1: `APP_NAME=Laravel`.**
   `.env.example` line 1: `APP_NAME=Laravel`.

5. **`config/mail.php` lines 113-116:**
   ```
   'from' => [
       'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
       'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
   ],
   ```
   Defaults are Laravel boilerplate.

6. **`.env` and `.env.example`:**
   `MAIL_FROM_ADDRESS="hello@example.com"`,
   `MAIL_FROM_NAME="${APP_NAME}"` → "Laravel" via interpolation.
   Confirms REVIEW-1's mail-log finding `From: Laravel <hello@example.com>`.

7. **No "Laravel" string literals in `resources/views/`.** Grep
   `Laravel` against `resources/views` returns zero matches. The
   "Laravel" branding leak is exclusively via `config/app.php` →
   `config/mail.php` from-name → `Illuminate\Mail\Markdown::parse`
   header (`vendor/mail/html/layout.blade.php` line 4 reads
   `{{ config('app.name') }}`). Vendor templates inherit cleanly
   once `app.name` is set; no template surgery required.

8. **No existing `dispatchAfterResponse`, `terminating`, or
   `sendAfterResponse` usage in the app.** Grep across `app/` returns
   zero matches. R-14 introduces a pattern; the codebase has nothing
   to align with.

9. **Laravel 13 surface for "after response":**
   - `Notification::sendAfterResponse()` — **does not exist** (verified
     against `vendor/laravel/framework/src/Illuminate/Notifications/`
     and current Laravel 13 docs).
   - `Job::dispatchAfterResponse()` and the closure form
     `dispatch(fn () => …)->afterResponse()` — **do exist** and are
     the documented Laravel-canonical pattern.
   - `app()->terminating(fn () => …)` — exists (`Application::terminating`),
     fires after the response is sent.

**Decision implication.** A choice between three Laravel-supported
patterns for "send notification after response is flushed." **D-075
claimed.** Decision matrix in §3.3.

The three branding fixes (`config/app.php` name; mail from-address +
from-name; `.env` + `.env.example`) are mechanical and ride along in
R-14 implementation; no D-NNN.

### §1.4 R-15 — Dependency + URL-generation cleanup

**ROADMAP-REVIEW scope** (§R-15, lines 316-329): remove `axios` and
`geist`; remove `php artisan serve` from `composer dev`; standardise
storage URL generation across controllers; reconcile `APP_URL` /
Herd / localhost; fix logo removal so it nulls and deletes the file.

**Files audited**

- `package.json` (39 lines).
- `composer.json` (96 lines).
- `app/Http/Controllers/**` for `asset('storage/')` and
  `Storage::disk('public')->url(…)` (15 occurrences across 8
  controllers — full list below).
- `resources/js/pages/dashboard/settings/profile.tsx` (relevant lines
  138-176, 71-90).
- `resources/js/pages/onboarding/step-1.tsx` (lines 35-72, 174-199).
- `app/Http/Controllers/Dashboard/Settings/ProfileController.php`
  (76 lines).
- `app/Http/Controllers/OnboardingController.php` lines 129-150
  (`uploadLogo`).
- `app/Http/Requests/Dashboard/Settings/UpdateProfileRequest.php`
  (45 lines).
- `app/Http/Requests/Onboarding/StoreProfileRequest.php` (read-grepped).
- `.env` and `.env.example`.

**Findings**

1. **`axios` is in `package.json` line 15** (under `devDependencies`,
   `axios: ">=1.11.0 <=1.14.0"`). Grep across `app/` and `resources/`
   for `import axios|require\('axios'\)|from 'axios'` returns ZERO
   matches. The skill files (`resources/js/CLAUDE.md`,
   `resources/js/AGENTS.md`) only mention axios in a "do not use"
   sentence. Safe to remove.

2. **`geist` is in `package.json` line 31** (under `dependencies`).
   Zero runtime imports across `app/` and `resources/`. Safe to remove.

3. **`composer dev` script line 55 starts `php artisan serve`** as the
   first concurrent process. The user has confirmed in chat that
   `http://localhost:8002/` works (the `serve` output port). The same
   project is also reachable via Laravel Herd at `http://riservo-app.test`
   (per `.env` `APP_URL`). **Both are working** — `php artisan serve`
   on port 8000/8002 AND Herd on `*.test`. The REVIEW-1 finding stands
   (`composer dev` should drop `serve` and let Herd be the canonical
   local URL), but the developer's note clarifies they actively use
   the `serve` URL too. §3.4 plans to remove `serve` from `composer dev`
   AND add a comment / README note documenting "use Herd at
   `http://riservo-ch.test` (or `php artisan serve` on `:8000` if you
   prefer)" so the manual workaround stays available for devs who
   prefer the artisan-served port.

4. **Storage URL helpers split 10 / 5 across 8 controllers.**

   `Storage::disk('public')->url(…)` (10 occurrences):
   - `app/Http/Controllers/OnboardingController.php:148, 158, 275`
   - `app/Http/Controllers/WelcomeController.php:19`
   - `app/Http/Controllers/Dashboard/Settings/StaffController.php:53,
     136, 252`
   - `app/Http/Controllers/Dashboard/Settings/ProfileController.php:24, 53`
   - `app/Http/Controllers/Dashboard/Settings/AccountController.php:89`

   `asset('storage/' . $path)` (5 occurrences):
   - `app/Http/Controllers/Dashboard/BookingController.php:126`
   - `app/Http/Controllers/Dashboard/CalendarController.php:93, 107`
   - `app/Http/Controllers/Booking/PublicBookingController.php:70, 118`

   Pattern: the *settings / onboarding / dashboard-shell* surface
   uses `Storage::disk('public')->url`; the *booking + calendar*
   surface uses `asset('storage/…')`. The split is by feature area,
   not by author chance. Consolidating is straightforward; the
   driver-aware helper is the canonical one (matches D-009 and
   D-065's "the abstraction is what enables the swap to Laravel
   Cloud's managed object storage with no code change"). **D-076
   claimed** for the canonical choice and the audit migration list.

5. **Logo removal — orphan file + empty-string drift.**

   - `resources/js/pages/onboarding/step-1.tsx` lines 185-198 has a
     "Remove" button that calls `setPreviewUrl(null); setLogoPath('')`.
     The form then submits `logo=''` via the hidden input on line 156.
   - `resources/js/pages/dashboard/settings/profile.tsx` lines 138-176
     has only "Replace logo" / "Upload logo". **No Remove button.**
     The settings UI cannot trigger logo removal at all today (audit
     drift vs REVIEW-1 §#17, which implied both surfaces have it —
     only onboarding does).
   - `app/Http/Controllers/OnboardingController.php::storeProfile`
     (line 285) saves whatever the validator passes. The validator
     (`StoreProfileRequest` line 39, mirrored by
     `UpdateProfileRequest` line 39) uses
     `'logo' => ['nullable', 'string', 'max:255']` — accepts empty
     string. So the empty string is persisted as `''`, not normalised
     to `null`.
   - The two `uploadLogo` endpoints (`OnboardingController::uploadLogo`
     line 138 and `ProfileController::uploadLogo` line 44) DO delete
     the previous logo file when a new one is uploaded. They do NOT
     run on the "remove" path — that path goes through the regular
     `update` path which has no `Storage::delete` step.
   - Net result: clicking Remove in onboarding leaves the file in
     `storage/app/public/logos/` and writes `''` to the database. The
     settings UI has no Remove button to test.

6. **`.env` / `.env.example` URL drift.**
   - `.env`: `APP_URL=http://riservo-app.test` (the developer's local
     Herd site).
   - `.env.example`: `APP_URL=http://localhost`.
   - CLAUDE.md / Laravel-Boost guidance ("served by Laravel Herd at
     `https?://[kebab-case-project-dir].test`") implies the convention
     is `http://riservo-ch.test` (project dir is `riservo-ch`). `herd
     sites` confirms three Herd sites with `riservo` in the name
     (`riservo.test`, `riservo-app.test`, `riservo-ch.test`); the
     dir-name convention points at `riservo-ch.test`.
   - **Real drift.** `.env.example` should match the documented
     convention. The developer's note "you can also use
     http://localhost:8002/" confirms the `serve` URL works in
     parallel — the choice for `.env.example` is the URL a *new*
     contributor should see first, which is the Herd convention.

   Per task brief: "If drift exists and there's a fifth decision
   needed, **stop and ask** before claiming D-077." The drift is real
   but the choice is dictated by CLAUDE.md ("Herd at
   kebab-case-project-dir.test"). **No D-077 claimed.** The
   `.env.example` update is mechanical — point it at
   `http://riservo-ch.test`. If the developer disagrees and prefers a
   different canonical URL, that's a 30-second conversation, not a
   D-NNN.

**Decision implication.** **D-076 claimed** for the storage URL
canonical choice. Other R-15 sub-items (axios + geist removal, drop
`php artisan serve`, fix logo removal, update `.env.example` URL) are
mechanical.

### §1.5 Bundle-or-split analysis

**Roadmap recommendation.** ROADMAP-REVIEW line 379 lists R-12 + R-13
+ R-14 + R-15 as one of seven "themes" and notes "polish, can be one
session." That phrasing is permission, not prescription.

**Coupling between the four.**

| Pair | Shared files / layers | Coupling strength |
|---|---|---|
| R-12 ↔ R-13 | None. R-12 = welcome page + invite dialog; R-13 = customer registration. Different controllers, different views, different test surfaces. | Zero |
| R-12 ↔ R-14 | `InvitationNotification.php` is touched by both (R-12 reads the "48 hours" copy from a shared constant, R-14 makes it dispatch after response). | Light — both edit one file. The R-12 change is a one-line `->line(…)` swap; R-14 changes the *call site* that creates the notification (controller layer), not the notification class itself. Order matters but is small. |
| R-12 ↔ R-15 | None. R-12 doesn't touch storage URLs; R-15 doesn't touch invite copy. | Zero |
| R-13 ↔ R-14 | `MagicLinkController` is touched by R-14 (move `MagicLinkNotification` send off request path) and is *adjacent* to R-13 (which widens what `resolveUser` will find — but R-13 doesn't actually edit `MagicLinkController` because the new registered Users naturally appear in step 1 of `resolveUser`). | Adjacent but not overlapping. |
| R-13 ↔ R-15 | None. | Zero |
| R-14 ↔ R-15 | The `dispatchAfterResponse` pattern from R-14 is a closure dispatched from controllers; if R-15 chooses the storage URL helper consolidation as its canonical task, the two areas don't interact. | Zero |

**Risk profile per item.**

- R-12 — small, safe, frontend-led. ~5 files. ~3 new tests.
- R-13 — product decision (Option A vs B). If A: 10-line controller
  rewrite, copy update, ~3 new tests. If B: copy + SPEC update only.
- R-14 — pattern introduction. New file (job class or closure),
  config edits, mail template re-verification, ~3 new tests + manual
  smoke check that mail still arrives in the log driver.
- R-15 — broad audit scope (15 controller call sites). Refactor
  surface is wide but each edit is mechanical. ~2 new tests for logo
  removal; refactor needs no new tests (regression suite already
  exercises every URL).

**Recommendation: split implementation into 3 sessions, not 1.**

Reasons not to bundle into a single session:
- Four R-NNs in one session means four parallel test surfaces. A
  failure in any one stalls the others' completion.
- R-13 is product-decision-dependent. The dev should approve Option A
  vs B before R-13 implementation starts. Bundling it with R-12 + R-14
  + R-15 means stalling three working areas while the product
  question gets answered.
- R-15's storage-URL refactor touches code on the public booking
  path (`PublicBookingController` lines 70, 118) — a regression
  there breaks customer flows. Worth its own session with focused
  pre-merge verification (visit a public page, upload a logo, see the
  rendered URL).
- The `.claude/CLAUDE.md` "Session Done Checklist" runs *per session*.
  Bundling four items extends the green-suite + Pint + build cycle
  unnecessarily across the suite.

Reasons not to fragment into 4 sessions:
- R-12 is too small to justify a standalone session-done checklist.
- R-14's three branding fixes are too small to ride alone; pairing
  with R-12 (the next-smallest) shares a test cycle.

**Net split** is detailed in §5.

---

## §2 Goal and scope

### §2.1 R-12 — In-scope / Out-of-scope

**In-scope.**

- Replace the dead `/dashboard/settings/notifications` link in
  `welcome.tsx` with a real Wayfinder-generated link, OR remove the
  third "next step" entry if no equivalent page exists yet.
- Replace the two surviving hardcoded links (`/dashboard/settings/services`,
  `/dashboard/settings/staff`) with Wayfinder route functions for
  consistency and rename-safety.
- Centralise the invite lifetime: add `BusinessInvitation::EXPIRY_HOURS`
  constant; expose a small accessor `BusinessInvitation::expiresAtFromNow():
  CarbonImmutable`; use it from both `StaffController` invite + resend,
  `OnboardingController::storeInvitations`, and `InvitationNotification::toMail`
  (which formats the "expires in :hours hours" message from the constant).
- Make the dialog copy derive from the constant: pass the lifetime as
  an Inertia prop from `StaffController::index` to `settings/staff` so
  the dialog text reads the actual hours, not "7 days".
- One new test asserting the dialog text matches the configured
  lifetime; one updated test confirming the welcome page renders all
  three links (or two, if we delete the third).

**Out-of-scope.**

- Adding a real `/dashboard/settings/notifications` page (post-MVP
  product).
- Changing the lifetime from 48h to a different number (product
  decision; not in scope here — only sourcing it from one place).
- Refactoring the invite-acceptance flow (`InvitationController::accept`
  is unrelated to R-12's drift).

### §2.2 R-13 — In-scope / Out-of-scope

**In-scope (Option A — recommended).**

- Drop `exists:customers,email` from `CustomerRegisterRequest::rules`.
- Add `unique:users,email` so a registration with an already-registered
  email is caught at validation, not at the controller.
- Rewrite `CustomerRegisterController::store` to handle three cases:
  (a) email already in `users` — redirect to login with status; (b)
  email already in `customers` (no `user_id`) — link existing Customer
  to new User (current behaviour); (c) email is new everywhere —
  create both Customer and User, link them.
- Update `customer-register.tsx` copy: drop "with the email you used
  when booking"; replace with "Create an account to manage all your
  appointments in one place."
- Update `CustomerRegisterRequest` `messages()` accordingly (drop the
  `email.exists` message).
- Three new tests: (1) registration with an unknown email creates
  Customer + User and logs in; (2) registration with an existing
  Customer email links them; (3) registration with an existing User
  email redirects to login.

**In-scope (Option B — fallback if developer rejects A).**

- Update SPEC §10 to add explicit sentence: "Customer registration
  requires the email to match an existing booking."
- Tighten `customer-register.tsx` copy to make the rule visible
  upfront ("To create an account, use the email from a previous
  booking. Don't have one yet? Book first, then come back.")
- One updated test asserting the copy matches.

**Out-of-scope (both options).**

- Cross-business CRM merging (Customer rows are already global by
  email — no change needed).
- Magic-link auto-creation behaviour (`MagicLinkController::resolveUser`
  works with any User; widening registration unlocks magic-link for
  fresh emails too — no separate change).
- Customer email verification flow (already `markEmailAsVerified()`
  on registration; D-038 covers business users only).

### §2.3 R-14 — In-scope / Out-of-scope

**In-scope.**

- Move `InvitationNotification` send off the request path. Two call
  sites: `StaffController::invite` and `StaffController::resendInvitation`,
  plus `OnboardingController::storeInvitations`.
- Move `MagicLinkNotification` send off the request path. One call
  site: `MagicLinkController::store`.
- The chosen pattern (D-075) applies to all four call sites.
- `config/app.php` name default → `'riservo.ch'`.
- `.env` and `.env.example` `APP_NAME=riservo.ch`.
- `.env` and `.env.example` `MAIL_FROM_ADDRESS="hello@riservo.ch"`,
  `MAIL_FROM_NAME="${APP_NAME}"` (interpolation already in place).
- Verify zero "Laravel" string remains in any rendered mail (vendor
  templates already use `config('app.name')`; setting the value is the
  whole fix).
- New tests: the two interactive flows return their HTTP responses
  before the mailer is invoked (assert via `Notification::fake()` +
  `dispatch_after_response` test helper, OR a sentinel timing test).
  Plus an updated test for each of the four call sites confirming the
  notification still fires (just deferred).

**Out-of-scope.**

- Touching `Booking*Notification` classes — the ROADMAP explicitly
  carves them out.
- Per-flow queue tuning (no `connection`/`queue` overrides).
- Replacing `log` mailer for local dev (still `log` per `.env`).
- Adding mail-from override per Business (post-MVP "send from your
  domain" feature).

### §2.4 R-15 — In-scope / Out-of-scope

**In-scope.**

- Remove `axios` from `package.json`; run `npm install` to update
  lockfile; verify nothing imports it.
- Remove `geist` from `package.json`; run `npm install`; verify nothing
  imports it.
- Remove `php artisan serve` from `composer.json` `dev` script. Add a
  note in the script (or in `docs/DEPLOYMENT.md`) that local dev URL
  is the Herd site (`http://riservo-ch.test`), and that
  `php artisan serve` is still available manually.
- Per D-076 (§4), migrate the 5 `asset('storage/…')` call sites to
  `Storage::disk('public')->url(…)`.
- Fix logo removal: backend normalises empty-string `logo` to `null`
  AND deletes the file from `storage/app/public/`. Implement in both
  `OnboardingController::storeProfile` and
  `Dashboard\Settings\ProfileController::update`. Add a Remove
  button to `dashboard/settings/profile.tsx` mirroring the onboarding
  step-1 UI so the settings surface gains the capability that today
  exists only in onboarding.
- Update `.env.example` `APP_URL=http://riservo-ch.test` (matches
  CLAUDE.md Herd convention).
- Two new tests: (1) `POST /dashboard/settings/profile` with empty
  `logo` deletes the file from storage and stores `null`; (2)
  `POST /onboarding/profile` (or step submission) with empty logo
  same behaviour.

**Out-of-scope.**

- Wider URL-generation refactor (e.g., centralising `route()` calls).
  REVIEW-1 §#16 mentions "host / URL generation" but the audit shows
  no real drift beyond `.env.example` — controllers use `route()` and
  `url($business->slug)` already.
- Slug-alias history (post-MVP; flagged in ROADMAP-REVIEW carry-over).
- Object-storage migration (D-009 + D-065 already scoped to deploy
  time; no code change needed).
- Removing other dependencies that ARE used (no `npm dedupe` pass).

---

## §3 Approach

### §3.1 R-12 plan

**Implementation steps.**

1. **Add `BusinessInvitation::EXPIRY_HOURS` constant** (e.g., `public
   const EXPIRY_HOURS = 48;`) and a thin accessor:
   ```php
   public static function defaultExpiresAt(): CarbonImmutable
   {
       return CarbonImmutable::now()->addHours(self::EXPIRY_HOURS);
   }
   ```
2. **Replace three `now()->addHours(48)`** call sites with
   `BusinessInvitation::defaultExpiresAt()`:
   - `app/Http/Controllers/Dashboard/Settings/StaffController.php:193`
   - `app/Http/Controllers/Dashboard/Settings/StaffController.php:212`
   - `app/Http/Controllers/OnboardingController.php:427`
3. **Rewrite `InvitationNotification::toMail`** line 38:
   ```php
   ->line(__('This invitation expires in :hours hours.', [
       'hours' => BusinessInvitation::EXPIRY_HOURS,
   ]));
   ```
4. **Add Inertia prop** to `Dashboard\Settings\StaffController::index`
   exposing `inviteExpiryHours = BusinessInvitation::EXPIRY_HOURS`.
   Pass to the page that renders `StaffInviteDialog`.
5. **Rewrite `staff-invite-dialog.tsx`** line 58:
   ```tsx
   {t('They receive an email to set up a password. The invite expires in :hours hours.', { hours: inviteExpiryHours })}
   ```
6. **Welcome page fix.** Replace lines 44, 53, 60 anchors with
   Wayfinder route imports:
   ```tsx
   import { edit as servicesEdit } from '@/routes/settings/services';
   import { staff } from '@/routes/settings'; // or per-action
   ```
   The dead `/dashboard/settings/notifications` is replaced with the
   nearest semantic equivalent (booking settings — that's where
   reminder cadence config lives, even if not under a "notifications"
   header). If no real equivalent exists for "Tune your reminders",
   the third entry is **deleted** rather than redirected (the welcome
   page's "Next up" list looks fine with two items). Decision: keep
   three by linking to `settings.booking` because the booking-settings
   page exposes the per-business `reminder_hours` field; the user's
   intent ("set when reminder emails go out") matches.
7. **i18n keys.** Add new translation key for the parameterised
   string `"This invitation expires in :hours hours."` (current
   "expires in 48 hours" is removed; current "7 days" is removed).

**No D-NNN claimed for R-12.** The choice between a model constant
and a config key is small; constant wins on simplicity (no env
lookup, no test-config override, no race with `.env` drift). State
this convention here in §3.1 rather than promote it to D-073.
Future change to lifetime is a one-line edit to the constant.

### §3.2 R-13 plan + decision matrix

**Decision matrix — D-074 — Customer registration scope.**

| Criterion | Option A — Widen (open registration) | Option B — Keep narrow (require prior booking) |
|---|---|---|
| Product fit (SPEC §10) | **Direct match.** SPEC says "Optional account registration (email + password) for customers who prefer it" with no narrowing clause. | **Mismatch.** SPEC has no sentence describing the narrowing; B requires SPEC update to describe a tightening. |
| User friction | **Lower.** A new visitor can register and book later, or register without ever booking (e.g., to test the flow). | **Higher.** A new visitor who wants an account first ("I'll plan, then book") gets a hard "no" with a confusing message. |
| Ambiguity on subsequent bookings | **Resolved by data model.** `customers.email` is globally unique; subsequent booking via `Customer::firstOrCreate(['email' => ...])` (already used in `Dashboard\BookingController::store` line 292 and `PublicBookingController` line 274) finds and reuses the row. No business-scoping ambiguity because Customer is global. | **N/A by construction.** A registered customer has, by definition, already booked. |
| Data-model impact | **Minor — orphan tolerance.** A registered Customer with zero bookings is invisible to per-business CRM (filtered via `whereHas('bookings', fn ($q) => $q->where('business_id', …))` — see `Dashboard\CustomerController:22`). No CRM noise. No migration. | **Zero.** No new code paths. |
| Implementation effort | **Small.** ~10-line controller rewrite, drop one rule, add one rule, update copy. | **Trivial.** Update copy + SPEC; add no logic. |
| Test surface | **+3 tests.** New email + booking, new email + never booked, existing email collision. | **+1 test (UI copy regression).** No backend change to test. |
| Failure mode | If A is wrong, we can tighten back later (a CSV of orphan Customers is a cleanup job, not a regression). | If B is wrong, we keep frustrating a class of users we don't observe (they bounce off the register page). |

**Recommendation: Option A.**

The decisive factor is product fit. SPEC §10 reads as a peer offering
of magic-link and password registration, neither of which is gated by
prior bookings in any other appointment-booking SaaS. The "narrowing"
in the current code reads more like an accidental shortcut (the
controller assumed Customer existed and a `exists:customers,email`
rule was the fastest way to enforce that assumption) than a deliberate
product call. Fixing the SPEC to match the code (Option B) would
codify the accident; widening the code to match the SPEC (Option A)
preserves the documented intent.

The orphan-Customer concern is addressed by the data model: per-business
CRM filters by booking presence, so a Customer with zero bookings is
**already invisible** to every CRM view. No special handling needed.

**Implementation steps (Option A).**

1. **Update `CustomerRegisterRequest::rules`:**
   ```php
   'email' => ['required', 'string', 'email', 'unique:users,email'],
   ```
   Drop `exists:customers,email`. Drop the `email.exists` message.
2. **Rewrite `CustomerRegisterController::store`:**
   ```php
   public function store(CustomerRegisterRequest $request): RedirectResponse
   {
       $email = $request->validated('email');

       $customer = Customer::firstOrCreate(
           ['email' => $email],
           ['name' => $request->validated('name')],
       );

       $user = User::create([
           'name' => $request->validated('name'),
           'email' => $email,
           'password' => $request->validated('password'),
       ]);
       $user->markEmailAsVerified();

       $customer->update(['user_id' => $user->id]);

       Auth::login($user);

       return redirect()->route('customer.bookings');
   }
   ```
   The "user already exists" branch is now caught by `unique:users,email`
   at validation; no controller-level redirect needed.
3. **Update `customer-register.tsx`** line 20 description string to:
   "Create an account to manage all your appointments in one place."
4. **No SPEC update needed** under Option A — the spec already
   matches.

**Implementation steps (Option B — only if developer rejects A).**

1. Keep `exists:customers,email`.
2. Update `customer-register.tsx` copy to make the rule visible.
3. Append a sentence to SPEC §10 stating the rule.
4. Tighten the `email.exists` message wording.

### §3.3 R-14 plan + decision matrix

**Decision matrix — D-075 — Post-response dispatch for interactive
notifications.**

| Criterion | Option 1 — `dispatch(fn () => $notify)->afterResponse()` (closure-after-response) | Option 2 — `JobClass::dispatchAfterResponse($args)` (named job after-response) | Option 3 — `app()->terminating(fn () => $notify)` (kernel terminating) | Option 4 — `implements ShouldQueue` (real queue) |
|---|---|---|---|---|
| Time-to-user-response | Same as Option 4 conceptually (response flushes first), but no worker round-trip. | Same as Option 1. | Same as Option 1. | Slowest in local dev if no worker; fastest in prod once dispatched (worker pickup adds latency though). |
| Failure observability | Failures hit the default exception handler **after response** — logged, never reported to user. No retries. | Same as Option 1; plus the named job is greppable / mockable. | Failures in `terminating` are silently swallowed by Laravel unless explicitly logged. **Worse than Option 1.** | First-class — failed jobs table, retries, telemetry (best). |
| Retry semantics | None. | None. | None. | Built-in; configurable via `tries`, `backoff`, `failed()`. |
| Local-dev behaviour (no worker) | **Works out of the box.** Mail goes to log driver, fires after response. | Same. | Same. | If `composer dev` is running its `queue:listen` → fine. If only `npm run dev` + Herd → mail piles up in `jobs` table forever. |
| Prod behaviour (worker running) | **Works out of the box.** Mail goes via SMTP/Postmark, fires after response — typically within milliseconds. | Same. | Same. | Adds queue latency (typically <1s) but provides retries; failures don't lose mail. |
| Code complexity | **Lowest.** One line in the controller — `dispatch(fn () => Notification::route('mail', $email)->notify(new InvitationNotification($invitation, $businessName)))->afterResponse();`. Closure carries the captured args. | One new file per flow (`SendInvitationAfterResponseJob`, `SendMagicLinkAfterResponseJob`); explicit class, slightly more ceremony. Easier to mock in tests. | Two lines (`app()->terminating(fn () => …)`); the callback runs with full container access. | Lowest *call site* (just remove the now-irrelevant Queueable trait juggling and add `implements ShouldQueue`); but the dev-experience cost is real. |
| Testability | `Bus::fake()` captures closure dispatches; assert via `Bus::assertDispatchedAfterResponse` (Laravel 13). Closures can also be unwrapped to verify the inner notification. | Easiest — `Notification::fake()` + assert the named job dispatched after response. | Hardest — `terminating` callbacks bypass `Bus`/`Notification` fakes; usually requires asserting via mail log inspection. | Easiest by far — `Notification::fake()` works as designed. |
| First-party Laravel pattern | Documented Bus pattern. | Documented Bus pattern (Dispatchable trait). | Documented but rarer (Application::terminating); usually used for cleanup, not work. | The "main" pattern for any deferred work. |

**Recommendation: Option 1 (closure `dispatch(fn …)->afterResponse()`).**

Reasons:
- Lowest code overhead. A single-line change at the four call sites.
  No new files, no new abstractions.
- Local-dev story matches the rest of the app. The user's local env
  uses `MAIL_MAILER=log` (`.env` line 4); after-response dispatch
  fires the log write and the mail appears in `storage/logs/laravel.log`
  immediately on the next response cycle — no worker dependency.
- Prod story is fine: web server flushes response → calls
  `terminate()` → runs the closure → invokes the mailer. The single
  request-process keeps running for a few extra ms; Laravel Cloud
  workers are unaffected (these aren't on the queue).
- Failure mode is explicit and acceptable: a deferred notification
  failure logs an exception. For magic-link and invite flows, the
  user's recourse if mail doesn't arrive is "request another link" /
  "ask the inviter to resend" — both flows are idempotent enough that
  silent retry loss is tolerable.
- Test pattern is already familiar in the codebase (`Notification::fake()`
  is used heavily throughout `tests/Feature`). Asserting "notification
  was queued via after-response dispatch" requires `Bus::fake()` and
  one new helper assertion — small.

Rejected:
- **Option 2** is the same pattern with more ceremony. Two new job
  files for two notifications is YAGNI for a one-line wrapper.
- **Option 3** loses the `Bus` fake testability. Lower failure
  observability than Options 1 and 2. No upside for the use case.
- **Option 4** trades user-perceived speed for retry semantics that
  the user-recourse story (resend) covers anyway. Major regression on
  local-dev experience for any contributor not running `queue:listen`.

**Implementation steps.**

1. **Refactor `MagicLinkController::store`** (line 28-50) so the
   final `$user->notify(new MagicLinkNotification($url))` becomes:
   ```php
   dispatch(function () use ($user, $url) {
       $user->notify(new MagicLinkNotification($url));
   })->afterResponse();
   ```
2. **Refactor the three `InvitationNotification` call sites** the same
   way:
   - `Dashboard\Settings\StaffController::invite` (line 196).
   - `Dashboard\Settings\StaffController::resendInvitation` (line 215).
   - `OnboardingController::storeInvitations` (line 430).
3. **Branding fixes** (mechanical, ride along with R-14):
   - `config/app.php` line 16: change default to `'riservo.ch'`.
   - `.env` and `.env.example` line 1: `APP_NAME=riservo.ch`.
   - `.env` and `.env.example` `MAIL_FROM_ADDRESS="hello@riservo.ch"`
     (replacing `hello@example.com`). `MAIL_FROM_NAME="${APP_NAME}"`
     stays — interpolation already pulls from `APP_NAME` which is now
     riservo.ch.
4. **Manual smoke check** after implementation: tail
   `storage/logs/laravel.log` while triggering a magic-link request
   and an invite send; confirm `From: riservo.ch <hello@riservo.ch>`
   appears.

**Other notification classes are NOT touched.** Per ROADMAP-REVIEW
§R-14 line 308: "Booking*Notification remain good candidates for the
real queue." Audit confirms all four `Booking*` classes already
implement `ShouldQueue` + `afterCommit()` — no change.

### §3.4 R-15 plan + decision matrix

**Decision matrix — D-076 — Canonical storage URL helper.**

| Criterion | Option A — `Storage::disk('public')->url($path)` | Option B — `asset('storage/' . $path)` |
|---|---|---|
| Driver awareness | **Yes.** Returns the correct URL whether the disk is `local`, `public`, S3, or Laravel Cloud's managed object storage. Per D-009 + D-065 the production target IS object storage; this helper is the one that works without code change. | **No.** Hardcodes the `public/storage/` symlink path; ignores the disk config. Breaks under object storage. |
| Performance | One config-array lookup (memoised) + string concat. | Same `asset()` resolution + string concat. Negligible difference either way. |
| Readability | Verbose but explicit ("this is a public-disk file"). | Compact ("this is an asset"). |
| Symbolic correctness | **Says what it means.** The file is on disk `public`; ask the disk for its URL. | **Says what it does on default Laravel.** Coincidentally correct on local-symlink setups; semantically wrong about where the file lives. |
| Migration footprint | 5 sites to migrate from B → A. | 10 sites to migrate from A → B. |
| Future-proofing | Compatible with any storage backend swap. | Will break on Laravel Cloud (D-065); future migration mandatory. |

**Recommendation: Option A — `Storage::disk('public')->url(...)` everywhere.**

Aligns with D-009 ("File storage via Laravel Storage facade") and
D-065 (Laravel Cloud managed object storage at deploy time). The 5
`asset('storage/…')` sites are migrated to the canonical helper. No
exceptions.

**Implementation steps.**

R-15 fans out across four sub-areas. Strict ordering does NOT matter
(no inter-dependency); the sub-list is presented in logical grouping.

**Sub-area 1 — Dependency removal.**

1. Remove `axios` from `package.json` line 15 (devDependencies block).
2. Remove `geist` from `package.json` line 31 (dependencies block).
3. Run `npm install` to update lockfile and `node_modules/`.
4. Run `npm run build` — expect green.

**Sub-area 2 — `composer dev` script.**

1. Remove the `"php artisan serve"` clause from line 55 of
   `composer.json`.
2. Concurrently command becomes:
   ```
   npx concurrently -c "#c4b5fd,#fb7185,#fdba74" "php artisan queue:listen --tries=1 --timeout=0" "php artisan pail --timeout=0" "npm run dev" --names=queue,logs,vite --kill-others
   ```
3. Add a one-line note in `composer.json` description (or a comment
   in `docs/DEPLOYMENT.md`) pointing local devs to Herd at
   `http://riservo-ch.test`. Devs who prefer the artisan-served port
   can still run `php artisan serve` in a side terminal — confirmed
   by the developer's note that `http://localhost:8002/` works.

**Sub-area 3 — Storage URL standardisation per D-076.**

Migrate 5 call sites from `asset('storage/'.$path)` to
`Storage::disk('public')->url($path)`:

- `app/Http/Controllers/Dashboard/BookingController.php:126`
- `app/Http/Controllers/Dashboard/CalendarController.php:93`
- `app/Http/Controllers/Dashboard/CalendarController.php:107`
- `app/Http/Controllers/Booking/PublicBookingController.php:70`
- `app/Http/Controllers/Booking/PublicBookingController.php:118`

Each replacement is a 1-line edit, mechanically equivalent in
output for the local + Herd dev environment, and corrected for
prod object-storage.

**Sub-area 4 — Logo removal hygiene.**

1. Add a `Remove` button to `dashboard/settings/profile.tsx` between
   lines 161-176, mirroring the onboarding-step-1 implementation
   (`onboarding/step-1.tsx` lines 185-198). Same `setPreviewUrl(null);
   setLogoPath('')` semantic; same hidden-input form propagation.
2. In `Dashboard\Settings\ProfileController::update` (line 28-34),
   normalise the `logo` field:
   ```php
   $data = $request->validated();
   if (isset($data['logo']) && $data['logo'] === '') {
       if ($business->logo && Storage::disk('public')->exists($business->logo)) {
           Storage::disk('public')->delete($business->logo);
       }
       $data['logo'] = null;
   }
   $business->update($data);
   ```
3. In `OnboardingController::storeProfile` (line 285), apply the same
   normalisation block. Refactor the duplicated logic into a tiny
   private helper (`Business` model method, e.g.,
   `Business::handleLogoMutation(array &$data, ?string $current): void`,
   OR a simple service — pick whichever is lighter at implementation
   time; per CLAUDE.md "Don't create helpers, utilities, or abstractions
   for one-time operations" → since the logic is duplicated across
   two controllers, a single `Business::removeLogoIfCleared(array &$data):
   void` model method is the lightest correct shape).
4. **No model-level mutator.** The `logo` column accepts string|null
   and the empty-string-from-form is form-layer concern, not model
   concern. Keeping the mutation in the controller layer matches
   `D-052` ("Validation rules duplicated rather than shared via a
   service class").

**Sub-area 5 — `.env.example` URL.**

1. Update line 5 of `.env.example`:
   ```
   APP_URL=http://riservo-ch.test
   ```
2. **No change to `.env`** — that's the developer's personal config,
   carries `riservo-app.test` because that's their actual Herd site.

**No D-077 claimed.** The convention is documented in CLAUDE.md
("Herd at kebab-case-project-dir.test"); `.env.example` is just out
of sync with that convention. Mechanical fix.

---

## §4 New decisions

Three D-NNN decisions are claimed, each in the appropriate topical
file per `docs/DECISIONS.md` topic mapping. R-12 carries no decision.

### §4.1 D-074 — Customer registration is open (no prior-booking requirement)

**Target file:** `docs/decisions/DECISIONS-AUTH.md`.

**Body (full text to be written verbatim under
DECISIONS-AUTH.md):**

```md
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
```

### §4.2 D-075 — Interactive notifications dispatch after response via closure

**Target file:** `docs/decisions/DECISIONS-AUTH.md` (the two affected
notifications are auth-adjacent — magic link and invitation are both
auth-flow notifications. Alternatively could land in
`DECISIONS-DASHBOARD-SETTINGS.md` since the invitation flow is a
settings concern. **Lands in DECISIONS-AUTH.md** because the dispatch
pattern is shared by both notifications and the auth flow is the
dominant context).

**Body (full text):**

```md
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
```

### §4.3 D-076 — Canonical storage URL helper is `Storage::disk('public')->url(...)`

**Target file:** `docs/decisions/DECISIONS-FOUNDATIONS.md` (cross-cutting
convention; complements D-009 "File storage via Laravel Storage facade"
and D-065 "Laravel Cloud managed object storage").

**Body (full text):**

```md
### D-076 — Canonical storage URL helper is `Storage::disk('public')->url(...)`
- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: Pre-R-15, controllers split 10/5 between
  `Storage::disk('public')->url($path)` (settings, onboarding,
  dashboard-shell layer) and `asset('storage/'.$path)` (booking +
  calendar layer). Both helpers produce the same URL on a default
  Laravel install with the public symlink in place, so the drift
  was invisible in local dev and CI. Per D-009 + D-065, production
  uses Laravel Cloud's managed object storage — not a public-symlink
  layout. `asset('storage/...')` would generate a wrong URL there;
  `Storage::disk('public')->url(...)` consults the disk config and
  returns the correct CDN/S3 URL. REVIEW-1 §#16 surfaced the drift;
  R-15 requires a single canonical helper across all controllers.
- **Decision**: All controllers and Inertia prop-builders use
  `Storage::disk('public')->url($path)` for any URL pointing to a
  user-uploaded file (logos, avatars). The `asset('storage/...')`
  pattern is removed from the codebase. Future contributors writing
  new controllers default to the canonical helper.
- **Consequences**:
  - On Laravel Cloud, file URLs resolve through the configured object-
    storage driver without code changes — the `Storage` facade
    abstraction is exactly what D-009 was set up to enable.
  - 5 call sites migrated:
    - `app/Http/Controllers/Dashboard/BookingController.php:126`
    - `app/Http/Controllers/Dashboard/CalendarController.php:93, 107`
    - `app/Http/Controllers/Booking/PublicBookingController.php:70, 118`
  - 10 call sites already canonical — unchanged.
  - No migration of previously-uploaded files needed; the helper
    only changes URL generation, not file paths.
  - Pre-launch test pass exercises every URL via the regression suite
    (logo upload + render in onboarding step-1, profile, welcome,
    public booking page; avatar render in calendar, manual booking,
    staff list).
- **Rejected alternative**:
  - *`asset('storage/...')` everywhere* — would require keeping the
    public symlink alive on Laravel Cloud, which contradicts the
    object-storage migration path D-065 set up. Net regression on
    deploy-time flexibility.
  - *Per-feature freedom* — leave both helpers in use, document
    which to use when. Adds rule-following overhead for a one-line
    convention; no upside.
```

### §4.4 No D-073 (R-12)

R-12 is purely mechanical: dead-link replacement + lifetime constant
extraction. The "model constant vs config key" choice was considered
and resolved in §3.1 (constant wins on simplicity). Codifying that
choice as D-073 would inflate the decision corpus without load-bearing
content. Future change to invite lifetime is a one-line edit to the
constant.

### §4.5 No D-077 (R-15 URL reconciliation)

`.env.example` URL drift is real (`http://localhost` vs the documented
Herd convention `http://riservo-ch.test`), but the canonical
convention is already documented in CLAUDE.md ("served by Laravel
Herd at `https?://[kebab-case-project-dir].test`"). Updating
`.env.example` to match is mechanical. No D-NNN.

---

## §5 Implementation order

**Recommendation: split into 3 sequential implementation sessions.**
The four R-NNs do not couple tightly enough to justify a single-close
session; the test-pass + code-style + build-verification cycle of the
Session Done Checklist runs better in three smaller cuts.

### Session 1 — R-12 (Welcome links + invite copy alignment)

- **Why first**: smallest scope; no decision overhead; warms up the
  test suite with no risk of cross-cutting failures. Implementation
  is ~5 file edits + 1 new constant + 2 tests. Likely lands in
  ~30 minutes once approved.
- **Deliverables**: §3.1 steps 1-7. New tests assert (a) the dialog
  copy reads the configured lifetime; (b) the welcome page renders
  with the corrected/Wayfinder'd links.
- **Decisions recorded**: none (no D-NNN).
- **Risk surface**: minimal — copy and link changes only. The new
  Inertia prop on the staff settings page is a one-line addition.

### Session 2 — R-13 + R-14 (Customer registration + notification delivery + branding)

- **Why bundled**: both touch user-facing auth flows. R-13 widens
  customer registration; R-14 makes the auth-recovery + invitation
  notifications fire after response. Test cycle naturally overlaps
  (`Notification::fake()`, `Bus::fake()` in both). Both decisions
  land in `DECISIONS-AUTH.md`.
- **Why R-13 needs developer sign-off first**: D-074 is a product
  decision (Option A vs B). Recommend Option A in §3.2; the developer
  must approve before this session starts.
- **Deliverables**: §3.2 steps (Option A) + §3.3 steps. Three new
  tests for R-13 (fresh email registration; existing-Customer link;
  existing-User collision). Five new tests for R-14: one per call
  site (4) confirming the notification still fires, one cross-cutting
  test asserting after-response dispatch (using `Bus::fake()` +
  `Bus::assertDispatchedAfterResponse`).
- **Decisions recorded**: D-074, D-075 in `DECISIONS-AUTH.md`.
- **Risk surface**: medium. Customer registration is exercised by a
  small set of feature tests today (`tests/Feature/Auth/`); the
  rewrite must not regress the existing flow for users with
  Customer rows. R-14's after-response dispatch needs a manual smoke
  check (tail `storage/logs/laravel.log`) because the test pattern
  asserts dispatch but not actual mail rendering.

### Session 3 — R-15 (Dependency + URL-generation cleanup)

- **Why last and standalone**: broad audit footprint (15 controller
  call sites for storage URL alone), touches the public booking page
  (`PublicBookingController:70, 118` — customer-facing). Worth its
  own pre-merge verification: visit a public booking page locally,
  upload a logo, click Remove on the settings profile page, confirm
  the file is gone from `storage/app/public/logos/`.
- **Deliverables**: §3.4 sub-areas 1-5. New tests for logo removal in
  both onboarding and settings paths.
- **Decisions recorded**: D-076 in `DECISIONS-FOUNDATIONS.md`.
- **Risk surface**: low per edit but wide. Each storage-URL swap is
  mechanical; the regression risk is uniform — break a render and
  the existing test for that page catches it.

### Why not a different split?

- **One-session bundle (per ROADMAP)**: covered in §1.5. Test-cycle
  serialisation argument; product-decision pause for R-13 also blocks
  the others.
- **Four sessions (one per R-NN)**: R-12 and R-14's branding fixes
  are too small to justify their own checklist runs. Pairing R-13
  with R-14 leverages both touching auth-flow notifications.
- **R-12 + R-14 together (both touch `InvitationNotification`)**: the
  R-12 edit is in `toMail()` (the message body); the R-14 edit is in
  the controller call sites (the dispatch wrapper). They don't conflict.
  Bundling them adds the R-14 decision overhead to the R-12 quick
  win — net regression on session pacing.
- **R-15 first** (since it has no decision dependency): possible, but
  R-15's 15-site audit is the heaviest cycle. Front-loading it delays
  the small wins. Last is better.

---

## §6 Verification

### §6.1 R-12 — Tests

**New / updated tests.**

1. **`tests/Feature/Settings/StaffTest.php`** (or new
   `tests/Feature/Onboarding/InvitationLifetimeTest.php` if cleaner):
   ```
   test('invite expiry copy matches configured lifetime', function () {
       …
       $response->assertInertia(fn ($page) => $page
           ->where('inviteExpiryHours', BusinessInvitation::EXPIRY_HOURS)
       );
   });
   ```
2. **`tests/Feature/Onboarding/WelcomePageTest.php`**: extend
   the existing test to assert the three (or two) "next step" links
   resolve to the named routes the welcome controller exposes.
3. **`tests/Feature/Settings/StaffTest.php`**: extend the existing
   `invite` and `resendInvitation` assertions to check `expires_at`
   is `BusinessInvitation::defaultExpiresAt()` (within 1 second).
4. **`tests/Feature/Onboarding/Step4InvitationsTest.php`**: same
   `expires_at` assertion.

**Manual sanity check.** Open `/dashboard/welcome`, click each
"Next up" link, confirm each resolves to a real page.

### §6.2 R-13 — Tests

**New tests** (under Option A, in
`tests/Feature/Auth/CustomerRegisterTest.php` — file does not exist
yet, will be created):

1. `test('a customer can register with an unknown email and is logged in')`.
2. `test('registration with an existing-customer email links the customer to the new user')`.
3. `test('registration with an already-registered user email returns a unique-validation error')`.

**Updated tests.**

- Audit `tests/Feature/Auth/RegisterTest.php` (business-owner
  registration) — confirm no breakage from validation rule changes
  (the rules live in different FormRequests, so likely zero impact).
- Audit `tests/Feature/Auth/MagicLinkTest.php` — confirm the
  `resolveUser` flow still works for both prior-customer and
  prior-user paths; widening registration adds a new path (registered
  user with no Customer yet) which step 1 handles unchanged.

**Manual sanity check.** Visit `/register/customer`, register a fresh
email, confirm redirect to `/my-bookings` and that the User + Customer
rows are created and linked.

### §6.3 R-14 — Tests

**New tests** in
`tests/Feature/Notifications/AfterResponseDispatchTest.php`:

1. `test('magic link notification dispatches after response')`:
   `Bus::fake()`; POST `/magic-link`; assert
   `Bus::assertDispatchedAfterResponse(fn (\Closure $job) => …)` ;
   alternatively assert via `Notification::fake()` after the closure
   is unwrapped.
2. `test('invitation notification (staff page) dispatches after response')`.
3. `test('invitation notification (resend) dispatches after response')`.
4. `test('invitation notification (onboarding) dispatches after response')`.

**Updated tests.**

- `tests/Feature/Auth/MagicLinkTest.php`: existing tests use
  `Notification::fake()`. They must still pass — the closure-after-
  response pattern unwraps to the same `notify()` call, and
  `Notification::fake()` should record the call. Verify in green
  bar; if `fake()` doesn't catch closure-after-response invocations,
  use `Bus::fake()` adjacency.
- `tests/Feature/Settings/StaffTest.php`: existing invitation tests
  use `Notification::fake()`; same expectation.
- `tests/Feature/Onboarding/Step4InvitationsTest.php`: same.

**Manual sanity check (mandatory for R-14).**

```bash
tail -f storage/logs/laravel.log &
# trigger magic link
# trigger invite
# confirm: From: riservo.ch <hello@riservo.ch>
# confirm: no "Laravel" string anywhere in the rendered email
```

### §6.4 R-15 — Tests

**New tests.**

1. `tests/Feature/Settings/ProfileTest.php`:
   `test('updating profile with empty logo deletes the existing file and stores null')`.
2. `tests/Feature/Onboarding/Step1ProfileTest.php`:
   `test('storing profile with empty logo deletes the existing file and stores null')`.

**Updated tests.**

- Storage URL refactor has no test surface of its own — `Storage::disk('public')->url()`
  and `asset('storage/'.$path)` produce the same string in the local
  test environment. The refactor must not break any existing render
  test (`tests/Feature/Booking/PublicBookingPageTest.php`,
  `tests/Feature/Calendar/*`, `tests/Feature/Settings/StaffTest.php`).
  Green bar confirms.
- `composer dev` script — no test; manual sanity check that
  `composer dev` still launches queue:listen + pail + vite.
- Dependency removal — no test; `npm run build` after `npm install`
  is the gate.

**Manual sanity check.**

1. `npm run build` — green; no axios / geist warnings; no chunk-size
   regression.
2. `composer dev` — three processes launch (queue, logs, vite). No
   `php artisan serve`.
3. Visit a Herd URL (`http://riservo-ch.test`) — public booking page
   renders, business logo loads.
4. Settings → Profile → Remove logo → reload page → logo is gone,
   `storage/app/public/logos/{path}` is gone.
5. `.env.example` `APP_URL` is `http://riservo-ch.test`.

### §6.5 Cross-session — Suite gates

After every implementation session, run the standard Session Done
checklist:

```
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm run build
```

Each session's PR (or commit) must show all three green.

Expected suite size at the end of all three sessions:
- After Session 1 (R-12): +2 tests → **486 passed**.
- After Session 2 (R-13 + R-14): +3 (R-13) + 4 (R-14) = +7 → **493 passed**.
- After Session 3 (R-15): +2 (logo) → **495 passed**.

(Baselines from HANDOFF.md: 484 post-R-10-11.)

---

## §7 Files to create / modify / delete

Grouped per R-NN for implementation clarity. Not every Session 1-3
session touches every group — see §5 split.

### §7.1 R-12 (Session 1)

**Create.**
- (none — extending existing tests)

**Modify.**
- `app/Models/BusinessInvitation.php` — add `EXPIRY_HOURS` constant
  + `defaultExpiresAt(): CarbonImmutable` accessor.
- `app/Http/Controllers/Dashboard/Settings/StaffController.php` —
  replace `now()->addHours(48)` at lines 193 and 212; pass
  `inviteExpiryHours` Inertia prop from `index()`.
- `app/Http/Controllers/OnboardingController.php` — replace
  `now()->addHours(48)` at line 427.
- `app/Notifications/InvitationNotification.php` — `toMail` line
  38 reads from constant.
- `resources/js/components/settings/staff-invite-dialog.tsx` —
  read `inviteExpiryHours` prop; render parameterised string.
- `resources/js/pages/dashboard/welcome.tsx` — replace lines 44, 53,
  60 with Wayfinder route imports; the third entry's link target
  becomes `settings.booking` (closest semantic match for "Tune your
  reminders").
- `lang/en.json` — add new key `"This invitation expires in :hours hours."`
  and `"They receive an email to set up a password. The invite expires in :hours hours."`;
  remove obsolete keys `"This invitation expires in 48 hours."` and
  `"They receive an email to set up a password. The invite expires in 7 days."`.
- `tests/Feature/Onboarding/WelcomePageTest.php` — extend.
- `tests/Feature/Settings/StaffTest.php` — extend.
- `tests/Feature/Onboarding/Step4InvitationsTest.php` — extend.

**Delete.**
- (none)

### §7.2 R-13 (Session 2, first half — Option A)

**Create.**
- `tests/Feature/Auth/CustomerRegisterTest.php` — three new tests.

**Modify.**
- `app/Http/Requests/Auth/CustomerRegisterRequest.php` — drop
  `exists:customers,email`; add `unique:users,email`; drop
  `email.exists` message.
- `app/Http/Controllers/Auth/CustomerRegisterController.php` —
  rewrite `store()` per §3.2 step 2.
- `resources/js/pages/auth/customer-register.tsx` — line 20 copy
  change.
- `lang/en.json` — replace
  `"Register with the email you used when booking to manage all your appointments."`
  with `"Create an account to manage all your appointments in one place."`;
  remove `"No bookings found for this email address. You must have at least one booking to register."`.

**Delete.**
- (none)

### §7.3 R-14 (Session 2, second half)

**Create.**
- `tests/Feature/Notifications/AfterResponseDispatchTest.php` — four
  new tests.

**Modify.**
- `app/Http/Controllers/Auth/MagicLinkController.php` line 45 —
  wrap notification in `dispatch(fn …)->afterResponse()`.
- `app/Http/Controllers/Dashboard/Settings/StaffController.php` lines
  196 and 215 — wrap.
- `app/Http/Controllers/OnboardingController.php` line 430 — wrap.
- `config/app.php` line 16 — default `'riservo.ch'`.
- `.env` line 1 — `APP_NAME=riservo.ch`.
- `.env` `MAIL_FROM_ADDRESS="hello@riservo.ch"`.
- `.env.example` line 1 — `APP_NAME=riservo.ch`.
- `.env.example` `MAIL_FROM_ADDRESS="hello@riservo.ch"`.

**Delete.**
- (none)

### §7.4 R-15 (Session 3)

**Create.**
- (none)

**Modify.**
- `package.json` lines 15 and 31 — remove `axios` and `geist`.
- `package-lock.json` — regenerated by `npm install`.
- `composer.json` line 55 — drop `php artisan serve` from `dev`
  script.
- `app/Http/Controllers/Dashboard/BookingController.php` line 126 —
  storage URL helper swap.
- `app/Http/Controllers/Dashboard/CalendarController.php` lines 93,
  107 — same.
- `app/Http/Controllers/Booking/PublicBookingController.php` lines
  70, 118 — same.
- `app/Http/Controllers/Dashboard/Settings/ProfileController.php`
  `update()` — empty-string `logo` normalisation + file delete.
- `app/Http/Controllers/OnboardingController.php` `storeProfile()` —
  same normalisation. Optional: extract to
  `App\Models\Business::removeLogoIfCleared(array &$data): void`.
- `resources/js/pages/dashboard/settings/profile.tsx` lines 161-176
  — add Remove button mirroring onboarding step-1.
- `.env.example` line 5 — `APP_URL=http://riservo-ch.test`.
- `tests/Feature/Settings/ProfileTest.php` — extend.
- `tests/Feature/Onboarding/Step1ProfileTest.php` — extend.

**Delete.**
- (none)

---

## §8 Risks and mitigations

### §8.1 R-12

**Risk: dialog `inviteExpiryHours` prop is dropped on a future page
refactor.** A future change to `StaffController::index` could omit
the prop; the dialog would render `undefined` hours.

- **Mitigation**: the new test in `tests/Feature/Settings/StaffTest.php`
  asserts the prop is present. Regression caught at PR time.

**Risk: the deleted i18n keys break translations in other locales.**
The project ships English-only today (D-008 — translations come
pre-launch); not a runtime risk, but `lang/it.json`, `lang/de.json`,
`lang/fr.json` (if any) need the same key removed.

- **Mitigation**: confirm in audit that no other locale files exist
  yet (HANDOFF says English-only; pre-launch translations not started).

### §8.2 R-13 (Option A)

**Risk: orphan Customer rows from registrants who never book.** A
visitor registers, never books — Customer + User rows persist
forever. Storage cost negligible (~100 bytes per row), CRM impact
zero (already filtered).

- **Mitigation**: documented in D-074 consequences; orphan tolerance
  is the trade-off. Future cleanup script (post-launch, if it ever
  matters) can prune Customer rows with `bookings_count = 0` and
  `created_at < N months ago`.

**Risk: existing test fixture for `CustomerRegisterTest.php` (none
exists today) needs to be written from scratch and may catch a
behaviour the controller-level rewrite missed.**

- **Mitigation**: write the three tests first (TDD-style); implement
  the controller rewrite to make them pass.

**Risk: a fresh registration whose email later collides with a
Customer created by another business's manual booking is now
permitted.** Today the user couldn't register at all; under Option A,
they could register, then later make a booking, then `firstOrCreate`
links existing or creates new — `customers.email` is unique, so
`firstOrCreate` finds the existing row.

- **Mitigation**: this is a feature, not a bug. The Customer row is
  global; the per-business CRM filters by booking presence. No
  ambiguity.

### §8.3 R-14

**Risk: dispatch-after-response failure observability gap.** If the
mailer call inside the closure throws, the user has already received
the response — no validation error surfaces. Failure logs to the
default exception handler only.

- **Mitigation**: in production, error reporting tooling (Sentry /
  Bugsnag / log aggregation) catches the exception. For dev, the
  `log` mailer doesn't throw on bad input. Acceptable trade-off
  given the user-recourse story (resend).

**Risk: `Notification::fake()` in existing tests may not capture the
closure-after-response invocation.** If `fake()` only intercepts the
`notify()` method directly (not when wrapped in a closure dispatched
after response), existing tests will silently fail to assert the
notification fired.

- **Mitigation**: implementation-time check — run the existing
  `MagicLinkTest.php` and `StaffTest.php` after the wrapper change.
  If `Notification::fake()` doesn't capture, adjust to `Bus::fake()`
  + `Bus::assertDispatchedAfterResponse(function (\Closure $job) { ... })`
  with a closure unwrapper. This is a documented Laravel pattern; not
  a new invention.

**Risk: branding update breaks an existing test that asserts
"Laravel" appears anywhere.**

- **Mitigation**: grep `tests/` for `'Laravel'` literal; the audit
  shows zero occurrences. Safe.

### §8.4 R-15

**Risk: storage-URL migration breaks already-saved asset references.**
Existing logo / avatar paths in the DB are unchanged (they're
relative paths like `logos/abc.jpg`). Both helpers consume the same
input; output is the same in dev (symlink) and the same in prod
under D-009/D-065 (object-storage URL returned by the disk driver).
No reference breakage.

- **Mitigation**: regression tests for every render path catch any
  unexpected divergence.

**Risk: logo removal could race with an in-flight request reading
the file path from cache.** Unlikely in practice (no file-path
caching layer in the app), but worth noting.

- **Mitigation**: the deletion runs in the controller transaction
  on profile/onboarding update — no separate background job, no
  long-lived path cache. Single-user-action race only; acceptable.

**Risk: removing `php artisan serve` from `composer dev` breaks a
contributor's workflow who relies on `localhost:8000`.** The
developer's chat note ("you can also use http://localhost:8002/") shows
the `serve` URL is in active use.

- **Mitigation**: the script change doesn't *prevent* `php artisan
  serve` — it just stops the bundled `composer dev` from running it
  automatically. Devs who prefer `serve` can run it in a side
  terminal. Add a one-line note in the docs (or as a `composer.json`
  comment field) documenting both options. Herd is the recommended
  default; `serve` is the documented fallback.

**Risk: removing `axios` from `package.json` breaks a transitive
dependency that secretly imports it.**

- **Mitigation**: `npm install` after removal exposes any missing
  transitive — would error with "axios not found" at build time.
  The audit grep across `app/` and `resources/` for `from 'axios'`
  / `require('axios')` returned zero. Safe.

**Risk: `geist` removal breaks a font import.**

- **Mitigation**: project uses `@fontsource-variable/{bricolage-grotesque,
  hanken-grotesk,inter}` (per `package.json` lines 25-27). `geist`
  is unreferenced; safe.

---

## §9 What happens after

R-12 + R-13 + R-14 + R-15 close out the polish bundle. The single
remaining REVIEW-1 remediation item is:

**R-16 — Frontend code splitting** (ROADMAP-REVIEW lines 332-348).
Switch the Inertia page resolver from `import.meta.glob(..., { eager:
true })` to lazy loading. Standalone session, no decisions, no
backend touches. Can be implemented immediately after R-15 lands
(no dependency in either direction). Bundle-size measurement is the
deliverable; expected to drop the current 928 kB main JS bundle to a
sequence of per-page chunks measured in tens of kB each.

After R-16 lands, the REVIEW-1 backlog is exhausted. Pre-launch
focus shifts to: (a) the deferred manual QA carryovers (R-7, R-8,
R-9 browser/SR walkthroughs), (b) translation files for IT / DE / FR
per D-008, (c) the Stripe billing surface (post-launch per current
ROADMAP).

---

## §10 Carried-to-BACKLOG / deferred

### §10.1 R-12 deferred

- **Real `/dashboard/settings/notifications` page**: no current
  product driver to build a separate notifications-settings page.
  The `reminder_hours` config lives on `business` (D-019) and is
  edited via `settings.booking`. A dedicated notifications page is a
  post-MVP UX enhancement.
- **Per-business invite-lifetime override**: today the 48-hour
  lifetime is global. A future "invitation valid for X days" per-
  business setting is a small enhancement; not in scope.

### §10.2 R-13 deferred

- **Customer email verification**: the new registration path
  `markEmailAsVerified()` immediately. A real verify-email-then-login
  flow for customers is post-MVP (D-038 covers business users only).
- **OAuth / social login for customers**: post-MVP (D-006 mentions
  Google via Socialite as v2).
- **Customer profile page**: registration creates the User but the
  customer-side dashboard today shows only `/my-bookings` (per D-035).
  A real profile-edit page (avatar, password change) is post-MVP.

### §10.3 R-14 deferred

- **Per-flow retry semantics**: if abuse telemetry shows the
  invitation flow has high SMTP failure rates, revisit using
  `ShouldQueue` for invitations specifically. Not today.
- **Failure observability for after-response dispatch**: a global
  exception listener that surfaces deferred-mailer failures to admin
  email is post-MVP. Today's behaviour relies on the Laravel default
  exception handler + log-aggregation tooling.
- **Per-business email branding** (e.g., "send from your domain via
  DKIM"): post-launch product enhancement.
- **Mail rendering smoke-test in CI**: a Pest browser test that
  triggers each notification and parses the log-mailer output for
  required strings (no "Laravel", required CTA URL). Useful for
  catching regressions in branding; not blocking.

### §10.4 R-15 deferred

- **Object storage migration**: D-065 already scopes this to deploy-
  time on Laravel Cloud. After D-076, the code is ready; no further
  app-side change.
- **`config/throttle.php` consolidation**: carry-over from R-11; the
  `auth.throttle.*` subtree could merge with `booking-api` /
  `booking-create` rate limiters from `AppServiceProvider`. Non-blocking.
- **Slug-alias history**: ROADMAP-REVIEW carry-over; URL stability
  for renamed business slugs (relevant to embed snippets and
  bookmarks). Not in R-15 scope.
- **Booking-flow state persistence**: ROADMAP-REVIEW carry-over;
  refresh / back / recover-progress in the public booking flow.
- **Profile + onboarding logo upload deduplication**: the
  `OnboardingController::uploadLogo` and
  `Dashboard\Settings\ProfileController::uploadLogo` methods are
  near-duplicates. R-15's logo-removal helper extraction could cover
  this; deferred unless a small additional pass is wanted.

---

> **End of plan. Awaiting developer approval.** No application-code
> changes, migrations, or test runs were performed during planning.
> Only this file was written.
