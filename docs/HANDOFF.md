# Handoff

**Session**: R-15 — Dependency + URL-generation cleanup (Session 3 of 3 and FINAL in the R-12/13/14/15 polish bundle)
**Date**: 2026-04-16
**Status**: Code complete; full Pest suite green (496 passed); public-booking smoke check clean; entire R-12 → R-15 bundle closed

---

## Review Round 1 — CLOSED (2026-04-16)

The post-Session-3 housekeeping pass closed the first review round:

- **R-1 – R-15** complete. **R-16** deferred to `docs/BACKLOG.md` (not a launch blocker — main bundle is cached after first load; post-launch real-user metrics decide whether the refactor ships).
- `docs/reviews/REVIEW-1.md` and `docs/reviews/ROADMAP-REVIEW.md` were moved to `docs/archive/reviews/`; the roadmap was renamed to `ROADMAP-REVIEW-1.md` on archive.
- `docs/reviews/` is now empty (the active round lives there; it will refill when REVIEW-2 lands).
- Archive convention documented in `docs/README.md`, `docs/reviews/CLAUDE.md` (+ `AGENTS.md`), and `.claude/CLAUDE.md`.

**Next phases** (running in parallel once this commit lands):

1. **Codex-driven REVIEW-2** — a fresh re-review of the current codebase. Output will land in `docs/reviews/REVIEW-2.md`, with `ROADMAP-REVIEW-2.md` if remediation is needed.
2. **ROADMAP-E2E** — Claude Code Max-driven end-to-end test roadmap planning (see `docs/roadmaps/ROADMAP-E2E.md`). Likely starts with a doc-alignment pass (SPEC, ARCHITECTURE-SUMMARY, DEPLOYMENT) before planning test coverage.

---

## What Was Built

Session 3 closes REVIEW-1 §#16 (dependency + URL generation drift)
and §#17 (logo removal). One decision recorded verbatim in
`docs/decisions/DECISIONS-FOUNDATIONS.md`: **D-076** (canonical
storage URL helper).
Plan: `docs/plans/PLAN-R-12-15-PRE-LAUNCH-POLISH.md` §3.4, §4.3,
§6.4, §7.4. Plan file moved to `docs/archive/plans/` — the R-12 →
R-15 bundle is complete.

### R-15 — Dependency + URL cleanup + logo removal (D-076)

**Sub-area 1 — Dependency removal.** Dropped `axios` (devDependencies)
and `geist` (dependencies) from `package.json`. `npm install`
regenerated the lockfile — 15 packages removed — and `npm run build`
is green. No transitive imports broke (audit confirmed zero direct
imports before removal).

**Sub-area 2 — `composer dev` script.** Dropped `php artisan serve`
from the concurrently command; colour list and `--names` reduced to
three. The script now runs `queue:listen + pail + vite`. Artisan-
served port remains available as a manual fallback — contributors
who prefer `http://localhost:8000` run `php artisan serve` in a
side terminal. Added a one-line note to `docs/DEPLOYMENT.md` Local
Development section documenting both options.

**Sub-area 3 — Storage URL standardisation (D-076).** Migrated the
five remaining `asset('storage/'.$path)` call sites to
`Storage::disk('public')->url($path)`:

- `app/Http/Controllers/Dashboard/BookingController.php:126`
- `app/Http/Controllers/Dashboard/CalendarController.php:93, 107`
- `app/Http/Controllers/Booking/PublicBookingController.php:70, 118`

Added `use Illuminate\Support\Facades\Storage;` where missing.
Grep across `app/` for `asset('storage/` returns zero matches
post-migration. The regression suite is the gate (both helpers
output identical URLs in local/Herd dev; the refactor pays off
only on Laravel Cloud object storage per D-065).

**Manual smoke check** — `http://riservo-app.test/salone-bella`
(the onboarded local business) returns `HTTP 200` and renders the
booking page; `logo_url` prop is `null` as expected (no logo on the
local fixture). Tinker-probed the helper output directly:
`Storage::disk('public')->url('logos/test.png')` →
`http://riservo-app.test/storage/logos/test.png` — identical to
what `asset('storage/logos/test.png')` would return.

**Sub-area 4 — Logo removal hygiene.** Added
`Business::removeLogoIfCleared(array &$data): void` — the single
home for the empty-string-/null-to-null normalisation + physical
file delete. The plan's `$data['logo'] === ''` check was widened
to `in_array($data['logo'], [null, ''], true)` because Laravel 13's
default `ConvertEmptyStringsToNull` global middleware rewrites
empty-string form posts to null before validation fires. The method
deletes the file from the `public` disk (if present) and forces the
persisted value to `null`. Wired from
`Dashboard\Settings\ProfileController::update` and
`OnboardingController::storeProfile`, each called once before
`$business->update($data)`.

Added a Remove button to
`resources/js/pages/dashboard/settings/profile.tsx` mirroring the
onboarding step-1 pattern exactly — `variant="ghost"`, `size="sm"`,
`onClick={() => { setPreviewUrl(null); setLogoPath(''); }}`, and
rendered only when `previewUrl` is truthy. Wrapped with the
existing Upload/Replace button in a `flex items-center gap-2` row.

**Sub-area 5 — `.env.example` `APP_URL`.** Line 5 now reads
`APP_URL=http://riservo-ch.test`, matching the CLAUDE.md Herd
convention (`kebab-case-project-dir.test`). `.env` is untouched —
it remains the developer's personal config.

**D-076** appended verbatim to `docs/decisions/DECISIONS-FOUNDATIONS.md`.
D-077 was considered and explicitly not claimed: the `APP_URL`
convention was already documented in `.claude/CLAUDE.md`, so the
`.env.example` edit is mechanical drift reconciliation, not an
architectural choice.

### New tests (+2, full suite 494 → 496)

- `tests/Feature/Settings/ProfileTest.php` — added
  `updating profile with empty logo deletes the existing file and stores null`.
  Fakes the `public` disk, stores an `existing.jpg` at `logos/...`,
  seeds it onto the business, PUTs `/dashboard/settings/profile`
  with `logo=''`, asserts redirect, fresh logo is null, and the
  fake disk no longer contains the file.
- `tests/Feature/Onboarding/Step1ProfileTest.php` — added
  `storing profile with empty logo deletes the existing file and stores null`.
  Same shape against `/onboarding/step/1`.

Both cover the new `Business::removeLogoIfCleared` path end-to-end.
The five storage-URL swaps have no dedicated tests — `asset()` and
`Storage::disk('public')->url()` produce identical strings on the
local symlink, so the regression suite (185 passing
Booking/Calendar/PublicBooking tests) is the gate.

### Audit drift vs plan §1.4

- Plan's §3.4 Sub-area 4 step 2 code snippet checked `$data['logo']
  === ''`. At implementation time, Laravel 13's global
  `ConvertEmptyStringsToNull` middleware rewrites empty strings to
  null *before* the FormRequest runs, so the validated `$data`
  never carries an empty string for `logo`. The first attempt of the
  two new tests failed on `assertMissing` until the check was
  widened to `in_array($data['logo'], [null, ''], true)`. The
  empty-string branch is preserved for robustness (e.g., if the
  middleware is ever disabled on a specific route). No scope change;
  the behaviour the plan described is delivered.
- Plan §8.4 anticipated a `.env.example` URL change to match Herd
  convention. The only edit was line 5 as planned.
- Plan §3.4 Sub-area 2 step 2 specified the resulting concurrently
  command; implementation matches exactly (3 colours, 3 names).

---

## Current Project State

- **Backend**:
  - `Business::removeLogoIfCleared($data)` model method is the single
    home for empty-logo normalisation + file delete.
  - 5 storage URLs migrated to `Storage::disk('public')->url()`;
    `asset('storage/')` is no longer used in `app/`.
- **Frontend**: Remove button added to `dashboard/settings/profile.tsx`.
  Bundle size unchanged (958 kB main chunk — R-16's territory).
- **Config**:
  - `.env.example` `APP_URL=http://riservo-ch.test`.
  - `config/app.php` and `MAIL_FROM_*` from Session 2 are unchanged.
  - `composer.json` `dev` script runs 3 processes only.
  - `package.json` has no `axios` or `geist`; lockfile regenerated
    (15 packages removed).
- **Tests**: full Pest suite green on Postgres — **496 passed, 2073
  assertions**. +2 from the Session 2 baseline of 494. Matches plan
  §6.5's ≥496 expectation exactly.
- **Decisions**: **D-076** appended to
  `docs/decisions/DECISIONS-FOUNDATIONS.md`. D-001 – D-075 untouched.
  D-073 / D-077 both considered and not claimed.
- **Migrations**: none.
- **i18n**: no changes this session.
- **Routes**: no changes.

---

## How to Verify Locally

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm run build
```

All three green: **496 passed** (in ~22 s), `{"result":"pass"}`,
clean Vite build in ~760 ms. The pre-existing 958 kB main-bundle
chunk-size warning is unchanged and remains R-16's scope.

Targeted checks:

```bash
php artisan test --compact --filter='Profile'
# → 18 passed (includes both new logo-removal tests)

php artisan test --compact --filter='PublicBooking|Calendar|Booking'
# → 185 passed (storage-URL refactor regression gate)

grep "asset('storage/" app/ -r
# → zero matches

grep -E "axios|geist" package.json
# → zero matches

grep "php artisan serve" composer.json
# → zero matches in the dev script
```

Manual smoke for the public booking path (what the plan called out
as the regression-risk surface):

```bash
curl -s -o /dev/null -w "HTTP %{http_code}\n" http://riservo-app.test/salone-bella
# → HTTP 200
```

---

## What the Next Session Needs to Know

Session 3 is complete. The R-12 → R-15 polish bundle is fully
closed. The remediation roadmap moves on to the final REVIEW-1 item:

- **R-16 — Frontend code splitting** (ROADMAP-REVIEW §R-16, lines
  332-348). Standalone session. Switch the Inertia page resolver
  from `import.meta.glob(..., { eager: true })` to lazy loading.
  Expected to drop the 958 kB main JS bundle to per-page chunks
  measured in tens of kB. No decisions. No backend touches. After
  R-16, the REVIEW-1 backlog is exhausted.

### Conventions that R-16 must not break

- **D-076 — Canonical storage URL helper.** Any new controller or
  prop-builder uses `Storage::disk('public')->url($path)`. Do not
  reintroduce `asset('storage/...')` anywhere in `app/`.
- **`Business::removeLogoIfCleared` is the single home for
  empty-logo normalisation.** Both controllers (settings profile,
  onboarding step 1) must call it. Do not duplicate the delete
  logic in a third place; extract into the model if a new flow ever
  needs the same normalisation.
- **D-074 — open customer registration.** Do not reintroduce
  `exists:customers,email`. Do not remove `unique:users,email`.
- **D-075 — closure-after-response dispatch.** Do not unwrap the
  four `dispatch(fn)->afterResponse()` call sites. `Booking*Notification`
  classes stay queued + `afterCommit()`.
- **Config branding.** `config/app.php` default is `'riservo.ch'`.
  `.env.example` `APP_NAME=riservo.ch`,
  `MAIL_FROM_ADDRESS="hello@riservo.ch"`, and
  `APP_URL=http://riservo-ch.test`.
- **`BusinessInvitation::EXPIRY_HOURS` is still the single source
  of truth.**
- **Welcome-page next-steps are Wayfinder-routed.**

---

## Open Questions / Deferred Items

R-15 closes cleanly with no new open questions. All items below
are unchanged from the R-13/14 handoff:

- **R-16 — frontend code splitting** — standalone session, next.
- **R-9 / R-8 manual QA** — developer-driven; carry-over.
- **Orphan-logo cleanup** — the R-15 logo-removal fix only applies
  to post-fix remove actions. A background job to prune orphaned
  files from prior remove actions is out of scope (plan §2.4,
  deferred in §10.4).
- **Profile + onboarding logo upload deduplication** — the two
  `uploadLogo` methods are near-duplicates. Deferred (plan §10.4).
- **Per-business invite-lifetime override** — global 48h today;
  `EXPIRY_HOURS` constant is the seam (PLAN §10.1).
- **Real `/dashboard/settings/notifications` page** — no current
  product driver.
- **Per-business email branding** (DKIM "send from your domain") —
  post-launch (D-075 §10.3).
- **Mail rendering smoke-test in CI** — post-MVP (plan §10.3).
- **Failure observability for after-response dispatch** — post-MVP
  (plan §10.3).
- **Customer email verification flow** — customer registration
  auto-verifies; real verify-email-then-login flow is post-MVP
  (plan §10.2).
- **Customer profile page** — post-MVP.
- **Scheduler-lag alerting** — carry-over.
- **`X-RateLimit-Remaining` / `Retry-After` headers on auth-recovery
  throttle** — carry-over.
- **SMS / WhatsApp reminder channel** — SPEC §9 post-MVP.
- **Browser-test infrastructure** (Pest Browser, Playwright) —
  carry-over.
- **Popup widget i18n** — carry-over.
- **`docs/ARCHITECTURE-SUMMARY.md` stale terminology** — carry-over.
- **Real-concurrency smoke test** — carry-over.
- **Availability-exception race** — carry-over.
- **Parallel test execution** (`paratest`) — carry-over.
- **Multi-business join flow + business-switcher UI (R-2B)** — still
  deferred.
- **Dashboard-level "unstaffed service" warning** — still deferred.
- **Slug-alias history** — ROADMAP-REVIEW carry-over.
- **Booking-flow state persistence** — ROADMAP-REVIEW carry-over.
