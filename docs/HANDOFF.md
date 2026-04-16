# Handoff

**Session**: R-9 — Popup embed service prefilter + modal robustness
**Date**: 2026-04-16
**Status**: Code complete; developer-driven browser + screen-reader QA pending

---

## What Was Built

R-9 closed REVIEW-1 §#10 ("The popup embed does not meet the documented
feature set and behaves like a brittle modal") and resolved the
service-prefilter contract drift PLAN-R-8 §1.3 flagged. The popup
embed now matches the iframe's prefilter capability, the overlay
behaves like a real modal (focus trap, scroll lock, duplicate guard,
focus restore, `role="dialog"`, mousedown/mouseup backdrop fix), and
SPEC §8 is aligned with the code. One new decision (D-070), four new
tests, zero backend changes.

### D-070 — canonical service prefilter (new)

Appended to `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md`. Pins
the path form `/{slug}/{service-slug}?embed=1` as the canonical
shape across direct link, iframe embed, and popup embed. Rejects
query form, dual-read, hash fragment, and a dedicated `/embed/*`
route. `?embed=1` remains the embed-mode switch (D-054); D-070 is
the orthogonal service axis. The controller still silently ignores
unknown path-form slugs — the existing "invalid service slug is
ignored" behaviour is preserved.

### SPEC §8 updated

`docs/SPEC.md` lines 269-279 rewritten to document the path form
(instead of the stale `?embed=1&service=…` query form) plus the new
`data-riservo-service` attribute on the popup trigger button.

### Frontend — two edits

`resources/js/pages/dashboard/settings/embed.tsx` (three lines
added). `popupSnippet` now emits `data-riservo-service="<slug>"`
on the `<button data-riservo-open>` when a service is selected in
the existing `Select`. Multiple buttons can share one `<script>`
tag on the host page, each with its own service (or none).

`public/embed.js` rewritten (80 → 139 lines, vanilla JS, single
IIFE, no bundler — D-054 preserved). Changes:

- **Service prefilter**: script-tag `data-service` optional default;
  per-button `data-riservo-service` override. `buildUrl(service)`
  returns `{base}/{slug}/{service}?embed=1` when present, else
  `{base}/{slug}?embed=1`.
- **Duplicate-overlay guard**: module-level `overlay` ref plus a
  DOM query for `[data-riservo-overlay]` — covers both repeated
  clicks and accidental duplicate `<script>` tags on the host page.
- **Scroll lock**: `document.body.style.overflow = 'hidden'` on
  open; original value captured and restored on close.
- **Focus restore**: the trigger element is captured on open and
  re-focused after the fade-out if it's still in the DOM.
- **Focus trap**: `focusin` listener on `document` redirects any
  focus that escapes the overlay back to the close button. Iframe
  contents manage their own same-origin tab order naturally.
- **ARIA**: container gets `role="dialog"`, `aria-modal="true"`,
  `aria-label` (from script-tag `data-label` or falling back to
  `'Book appointment'`).
- **Backdrop click fix**: `mousedown` and `mouseup` must BOTH land
  on the overlay itself. Prevents accidental close when a user
  drags a selection from inside the iframe to the backdrop and
  vice versa. Replaces the `click`-only listener.

### New test coverage (+4 tests)

`tests/Feature/Settings/EmbedTest.php`:

- `embed.js ships as a non-empty vanilla JS IIFE` — pins the
  D-054 "single IIFE, no bundler" invariant (file exists, non-zero
  size, starts with `(function`).
- `embed.js supports per-button service prefilter` — substring
  check for `data-riservo-service` and `data-slug` in the shipped
  file; regression pin that prefilter support shipped.
- `embed.js references no external domains` — regex guard against
  any `https?://…` reference in the widget source. Supply-chain /
  supply-simplicity guard. Whitelist starts empty by design.
- `embed settings page exposes appUrl prop as absolute URL` —
  locks the `appUrl` contract the `popupSnippet` template literal
  in `embed.tsx:42` depends on.

> **Test layer correction from plan.** Plan §5 Step 4 initially
> wrote Tests 1 and 2 as `$this->get('/embed.js')->assertOk()`.
> Probing at implementation time confirmed that hits the catch-all
> `/{slug}/{serviceSlug?}` route and returns 404 — nginx/Herd serves
> `public/*.js` in production and the file is invisible to the
> feature-test layer by construction. Switched to
> `file_get_contents(public_path('embed.js'))` to match Test 3.
> Noted in the archived plan's §5 Step 4. Content-Type verification
> remains a web-server concern; `curl -I http://riservo-app.test/embed.js`
> returns `application/javascript; charset=utf-8`.

---

## Current Project State

- **Frontend**: the dashboard embed settings page emits a
  service-aware popup snippet that mirrors the iframe prefilter
  behaviour. The vanilla JS widget in `public/embed.js` is a proper
  modal on any host page that embeds the snippet. The iframe
  snippet and Live Preview are unchanged.
- **Backend**: no changes. `PublicBookingController::show()`,
  `Dashboard\Settings\EmbedController`, and the catch-all route in
  `routes/web.php:201` are untouched — path form already worked
  end-to-end since Session 9. R-9 is a widget + docs + decision
  session, not a backend change.
- **Routes**: no changes.
- **Tests**: full Pest suite green on Postgres — **472 passed,
  1871 assertions**. +4 from the R-8 baseline of 468.
- **Decisions**: D-070 appended to
  `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md`. Covers the
  canonical service-prefilter contract.
- **Migrations**: none.
- **i18n**: zero new keys. `t('Book Now')` reused from existing
  strings for the popup snippet template. The vanilla JS widget
  still hardcodes English `'Book appointment'` and `'Close'` —
  captured as a BACKLOG item ("Popup widget i18n") because
  loading translations into a third-party-embedded JS widget is
  a non-trivial decision of its own.

---

## How to Verify Locally

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm run build
```

All three are green: **472 passed**, `{"result":"pass"}`, clean
Vite build in under 1 s (no new TypeScript errors, no new warnings
beyond the pre-existing 500 kB chunk-size notice).

Server-side sanity check of the URL shapes the popup will build:

```bash
curl -I http://riservo-app.test/embed.js
# 200, Content-Type: application/javascript; charset=utf-8

curl -sI 'http://riservo-app.test/{slug}?embed=1'             # default popup URL
curl -sI 'http://riservo-app.test/{slug}/{service}?embed=1'   # prefiltered popup URL
curl -sI 'http://riservo-app.test/{slug}/bogus?embed=1'       # unknown service falls through; 200
```

All return `HTTP/1.1 200 OK`. The unknown-service fallthrough is
tested server-side by `PublicBookingPageTest` → "invalid service
slug is ignored".

---

## Manual QA (developer-driven; not yet performed)

The agent cannot drive a browser interactively, so the 15-item
checklist below needs a human run before R-9 is considered
fully verified. Code-level verification (tests, Pint, build,
curl-level URL sanity) is complete.

### Setup — scratch host page

Save this as `/tmp/test-embed.html` (or anywhere outside the
repo) and open with `file://…` in a browser. Use a real business
slug (e.g., `salone-bella`) and one of its active service slugs
(e.g., `colore`).

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Riservo popup embed — host test</title>
  <style>
    body { font-family: system-ui, sans-serif; margin: 40px; max-width: 720px; line-height: 1.6; }
    button { padding: 10px 16px; margin-right: 10px; font-size: 16px; cursor: pointer; }
    section { margin-top: 40px; }
  </style>
</head>
<body>
  <h1>Host page — popup embed QA</h1>
  <p><button data-riservo-open>Book now (no prefilter)</button>
     <button data-riservo-open data-riservo-service="colore">Book colore (prefiltered)</button>
     <button data-riservo-open data-riservo-service="bogus">Book bogus (unknown service)</button></p>

  <section>
    <h2>Lorem ipsum (scroll lock test)</h2>
    <p>Paste ~10 paragraphs of Lorem ipsum here so the page scrolls.
       QA #7 verifies the host page cannot scroll while the modal is open.</p>
  </section>

  <script src="http://riservo-app.test/embed.js" data-slug="salone-bella"></script>
</body>
</html>
```

Delete the file when done. If using the scratch-in-`public/`
variant instead, verify with `git status` before closing that
`public/test-embed.html` is not staged or committed.

### Checklist

Run in Chrome + at least one other browser (Firefox or Safari),
plus the DevTools mobile emulator at 375 px.

1. **Default popup opens.** Click "Book now (no prefilter)".
   Modal fades in; focus moves to the close (×) button; iframe
   loads `/salone-bella?embed=1`; booking flow lands on the
   service picker.
2. **Service prefilter opens the right step.** Click "Book
   colore (prefiltered)". Iframe loads
   `/salone-bella/colore?embed=1`; booking flow skips the service
   picker. Click "Book bogus (unknown service)" — iframe loads
   `/salone-bella/bogus?embed=1`; server silently falls through
   to the service picker (existing "invalid service slug is
   ignored" test behaviour).
3. **Esc closes.** Press Escape. Modal fades out; focus returns
   to the trigger button (press Enter/Space on it — same button
   reopens the modal).
4. **Backdrop click closes.** Click the dark area around the
   modal. Modal closes; focus returns to the trigger. Now drag:
   mousedown on the backdrop → drag over the iframe → mouseup on
   the iframe. Modal does NOT close. Reverse: mousedown on the
   iframe → drag out to the backdrop → mouseup on the backdrop.
   Modal does NOT close (the pre-R-9 bug is fixed).
5. **Close button works.** Click the × in the top-right. Modal
   closes; focus returns to the trigger.
6. **Focus trap.** Open the modal. Press Tab repeatedly. Focus
   cycles close button → iframe contents → (when iframe content
   is exhausted) back to close button. Shift+Tab from close
   button redirects back to close button (the trap doesn't let
   focus escape to the host page; symmetry with Shift+Tab is a
   documented tradeoff — see plan §3.4 "Focus trap").
7. **Scroll lock.** Host page has Lorem ipsum below the viewport.
   Open the modal. Try mouse-wheel / touch / arrow-keys /
   spacebar — host page does NOT scroll. Close the modal —
   host page scrolls normally; previous scroll position preserved.
8. **Duplicate-open guard.** Double-click the trigger rapidly.
   Only one modal opens (DevTools: only one
   `[data-riservo-overlay]` element in the DOM).
9. **Multiple triggers.** The three triggers in the scratch
   host page each produce the correct URL. Closing one and
   opening another works normally.
10. **Duplicate `<script>` tags.** Add a second
    `<script src="http://riservo-app.test/embed.js" data-slug="salone-bella"></script>`
    before `</body>` and reload. The DOM-level duplicate guard
    keeps only one modal open despite both IIFEs having registered
    click listeners.
11. **ARIA roles present.** Inspect the open modal with DevTools.
    Container has `role="dialog"`, `aria-modal="true"`, `aria-label`.
12. **Screen reader sanity.** VoiceOver on macOS or NVDA on
    Windows announces "dialog, Book appointment" when the modal
    opens. This one requires SR — the agent cannot verify.
13. **Mobile viewport.** Chrome DevTools device emulator at
    375 px. The modal container (`width: 90%; max-width: 500px;
    height: 85vh`) renders well; tap outside / tap close / Esc
    from software keyboard behave as desktop. Real iOS Safari
    and real Android Chrome should be spot-checked too — the
    emulator does not replicate ITP or real touch scroll-lock
    behaviour.
14. **Booking flow end-to-end inside popup.** Make a real test
    booking: pick datetime, fill customer details, submit.
    Modal stays open through the flow; confirmation renders
    inside the iframe; closing the modal does not cancel the
    confirmed booking.
15. **Dashboard snippet matches SPEC example.** In the dashboard
    at `/dashboard/settings/embed`, pick a service in the
    "Pre-filter by service" Select. Copy the popup snippet.
    Verify it matches the SPEC §8 example shape exactly: script
    with `data-slug`; button with
    `data-riservo-open data-riservo-service="…"`.

### Known-risk follow-ups (apply if QA reveals them)

- **iOS Safari scroll lock**: if #7 still scrolls on real iOS
  Safari, apply the `position: fixed; top: -{scrollY}px`
  workaround inside `embed.js`'s scroll-lock code. Plan §6.6
  flags this as a known risk — mitigation is pre-authorised.
- **Safari ITP cookie/session inside iframe**: if #14 loses
  session state between steps on Safari specifically, that's the
  separate ITP issue flagged in plan Risk 8.4. Document and
  carry to the Embed & Share BACKLOG; do NOT scope-creep R-9.
- **Host-page CSP blocking the script**: if the script is
  blocked by a host-site CSP, that's environmental — the snippet
  copy UX implies the host has authorised `riservo.ch`. Add a
  release note for partners; no code change.

---

## What the Next Session Needs to Know

R-9 code is complete. Developer-driven browser + SR manual QA is
the one remaining verification step; it gates closure. The
remediation roadmap moves on to **R-10 (reminder DST + delayed-run
resilience)** — independent of R-9 (different files, different
concepts, different decision). See plan §1.3 (archived) for the
bundling analysis that split R-9 from R-10.

When adding new popup-embed code:

- **D-070 is the contract.** Path form is canonical across direct
  link, iframe, popup, tests. `?service=` query form is rejected
  and must stay rejected. `?embed=1` (D-054) stays as the
  orthogonal layout switch.
- **`public/embed.js` stays vanilla JS, single IIFE, no bundler.**
  D-054 chose this explicitly. If future work needs shared helpers
  across multiple widget files, that is a new decision (introducing
  Vite bundling for `public/*.js`), not an inline refactor.
- **Zero external references in `public/embed.js`.** The
  `toBeEmpty()` whitelist guard fails loudly on any new
  `https?://…` literal. If a legitimate external reference is ever
  needed, the whitelist is a single-line list update.
- **Iframe `title` and close-button `aria-label` are English-only.**
  See the Embed & Share BACKLOG ("Popup widget i18n"). Do not
  retrofit half-i18n — the loader strategy needs to be decided
  holistically.

---

## Open Questions / Deferred Items

- **R-9 manual QA — developer-driven.** The 15-item checklist
  above needs a browser + SR pass. Code-level verification is
  green; visual + keyboard + SR verification is the one remaining
  gate. Matches R-8's pattern: the agent cannot drive a browser
  interactively.
- **Browser-test infrastructure** (Pest Browser, Playwright). R-9
  could have added browser tests for the modal robustness gaps
  (focus trap cycling, scroll lock, duplicate guard); the plan
  deliberately deferred because introducing browser-test infra is
  itself a non-trivial session. Carry-over from R-7 / R-8.
- **Popup widget i18n.** The English `iframe.title = 'Book appointment'`
  and the close-button `aria-label='Close'` are in code. Loading
  translations into a vanilla-JS third-party-embedded widget is a
  separate decision (per-script `data-locale`? server-rendered
  `/embed-{locale}.js`? `window.riservoLocale` global?).
- **Popup analytics events, custom theming, multi-service picker,
  CSP hardening, Safari ITP session continuity, slug-alias
  history, deployed-snippet telemetry, SRI hashes, dashboard
  embed-settings copy UX polish.** All captured as one-liners in
  `docs/BACKLOG.md` under "Embed & Share (R-9 carry-overs)".
- **R-10 — Reminder DST + delayed-run resilience.** Next candidate
  in the remediation roadmap. Independent from R-9.
- **R-8 manual QA** — carried over from the R-8 HANDOFF; code-level
  verification was green, browser pass is still pending.
- **`docs/ARCHITECTURE-SUMMARY.md` stale terminology** — carried
  over from R-5/R-6: several places still say "collaborator"
  where the code has moved to "provider". Not blocking.
- **Real-concurrency smoke test** — carried over from R-4B;
  deterministic simulation remains authoritative.
- **Availability-exception race** — carried over from R-4B; out of
  scope.
- **Parallel test execution** (`paratest`) — carried over from
  R-4A; revisit only if the suite grows painful.
- **Multi-business join flow + business-switcher UI (R-2B)** —
  carried over from earlier sessions; still deferred.
- **Dashboard-level "unstaffed service" warning** — carried over
  from R-1B; still deferred.
