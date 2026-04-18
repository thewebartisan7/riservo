---
name: PLAN-R-9-EMBED-PREFILTER
description: "R-9: popup embed canonical service prefilter + modal robustness"
type: plan
status: shipped
created: 2026-04-16
updated: 2026-04-16
---

# PLAN-R-9 — Popup embed: canonical service prefilter + modal robustness

**Session**: R-9 — Popup embed service prefilter + modal robustness
**Source**: `docs/reviews/ROADMAP-REVIEW.md` §R-9, `docs/reviews/REVIEW-1.md` §#10
**Status**: proposed (plan-only)
**Date**: 2026-04-16
**Depends on**: D-054 (embed mode via `?embed=1`), the path-form catch-all route `/{slug}/{serviceSlug?}` already in place since Session 9. Independent of R-8 (calendar mobile — different files) and R-10 (reminders — different files).

---

## 1. Context

### 1.1 The findings

REVIEW-1 §#10 ("The popup embed does not meet the documented feature set and behaves like a brittle modal") flagged two concrete problems:

1. **Popup has no service prefilter.** SPEC §8 promises both embed modes support service pre-filtering. The iframe snippet in the dashboard does (`embed.tsx:40` emits a path-form URL when a service is picked). The popup snippet is always `<script src="…/embed.js" data-slug="…"></script><button data-riservo-open>Book Now</button>` (`embed.tsx:42`) — no service hint. `public/embed.js` reads only `data-slug` from its own script tag and always opens `/{slug}?embed=1`.
2. **Popup behaves like a brittle overlay rather than a modal.** `public/embed.js` has Esc handling, a close button, and backdrop-click close — but no focus trap, no scroll lock, no duplicate-overlay guard, no focus restore, no `role="dialog"`, no `aria-modal`, no `aria-labelledby`.

PLAN-R-8 §1.3 also flagged a **contract-drift** sub-problem that R-9 must resolve: SPEC §8 lines 269-273 document `?embed=1&service=taglio-capelli` (query form) while the code uses `/{slug}/{service-slug}?embed=1` (path form) and `PublicBookingController::show()` never reads `?service=`. R-9 must pick one canonical shape and align SPEC + code + the popup snippet + `embed.js` behind it.

### 1.2 Audit — what's still true at HEAD

Ran the full code audit before drafting. PLAN-R-8 §1.3's characterization holds with no drift.

| Claim from PLAN-R-8 §1.3 | State at HEAD | Verdict |
| --- | --- | --- |
| SPEC documents `?embed=1&service=taglio-capelli` at SPEC.md:269-273 | Matches exactly. `docs/SPEC.md:269-273` still reads `?embed=1&service=taglio-capelli` under the "Service Pre-filter" heading. | HOLDS |
| Iframe uses the path form `/{slug}/{service-slug}?embed=1` at `embed.tsx:40` | Matches exactly. `embed.tsx:40`: `const iframeUrl = previewService ? \`${baseUrl}/${previewService}?embed=1\` : embedUrl`. | HOLDS |
| `?service=` is never read by `PublicBookingController::show()` | Confirmed. `show(string $slug, ?string $serviceSlug = null)` reads the path segment via the router. No `request('service')`, no `$request->input('service')` anywhere in the controller. Invalid path-form slugs are silently ignored (`$preSelectedServiceSlug` stays `null`). | HOLDS |
| `embed.js` reads only `data-slug` | Confirmed. `public/embed.js:7` — `script.getAttribute('data-slug')`; no other `data-*` attribute is read. | HOLDS |

No drift. The plan proceeds on the R-8 assumptions.

**Adjacent findings surfaced during audit** (documented for transparency; see §10 for any that aren't rolled into R-9 scope):

- **The iframe snippet the admin copies IS already service-prefilterable.** When a service is picked in the Select at `embed.tsx:104-124`, `iframeSnippet` on `embed.tsx:41` rebuilds with the path-form URL. Good — this is the reference implementation the popup needs to match.
- **The Live Preview at `embed.tsx:154-161` uses the same path-form URL.** Confirms path form is already the canonical shape inside the app; only SPEC is drifted.
- **`public/embed.js` is not as bare as REVIEW-1 suggested.** It **does** have Esc close (`embed.js:67-69`), a close button with `aria-label="Close"` (`embed.js:28-33`), a backdrop-click close (`embed.js:44-46`), and a fade-in transition. It is **missing**: focus trap, scroll lock, duplicate-overlay guard, focus restore on close, `role="dialog"` + `aria-modal`, and `aria-labelledby`. REVIEW-1 §#10 listed focus / scroll / duplicate — audit confirms all three. The remaining missing items (restore focus, dialog roles) are a direct extension of the same concerns and ship in R-9.
- **`public/embed.js` hardcodes `iframe.title = 'Book appointment'` in English.** i18n of a vanilla JS widget is non-trivial (it can't reach `useTrans`). Out of scope for R-9 — carry to §10 as a deferred item.
- **No `/embed/*` routes exist.** The prompt's reference to "wherever the `/embed/*` route handler lives" is based on a misassumption — per D-054, embed is a `?embed=1` query parameter on the catch-all `/{slug}/{serviceSlug?}` route. The only `EmbedController` in the codebase is `app/Http/Controllers/Dashboard/Settings/EmbedController.php`, which is the dashboard settings page, not the embed-rendering route. No controller or route change is needed for R-9 if the chosen contract is path form.
- **No CSP / X-Frame-Options middleware exists.** The app sets no `Content-Security-Policy` or `X-Frame-Options` headers (`grep` over `app/`, `bootstrap/`, `config/`, `routes/` returns zero hits). This means `/{slug}?embed=1` is iframable from any origin — intentional for the iframe use case, and the popup (which opens a same-origin iframe on the host page) inherits the permissiveness. Explicit CSP hardening is out of scope; captured in §10.
- **Existing test coverage for the path form is solid.** `tests/Feature/Booking/PublicBookingPageTest.php` has three tests that pin path-form behaviour: `service pre-selection via URL sets preSelectedServiceSlug`, `invalid service slug is ignored`, `preselected service page exposes allow_provider_choice = false when setting is off`. R-9 inherits these.
- **Existing test coverage for the popup snippet is zero.** `tests/Feature/Settings/EmbedTest.php` has four tests (admin can view embed settings; services prop has N items; `?embed=1` sets prop; no `?embed=1` sets prop false). None test the popup snippet's string shape or the `appUrl` prop. R-9 adds these.

### 1.3 Bundle-or-split — R-9 alone (split from R-10)

Applied the four bundling conditions from PLAN-R-8 §1.3 to R-9 + R-10:

| Condition | R-9 + R-10 | Verdict |
| --- | --- | --- |
| 1. Shared files or shared concepts | R-9 touches `public/embed.js`, `resources/js/pages/dashboard/settings/embed.tsx`, `docs/SPEC.md`, `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md`, `tests/Feature/Settings/EmbedTest.php`. R-10 touches `app/Console/Commands/SendBookingReminders.php`, `app/Notifications/BookingReminderNotification.php`, reminder tests in `tests/Feature/Notifications/`, and the reminder decision file. **Zero file overlap, zero concept overlap.** Embed is a third-party widget; reminders are a scheduled command + DST-sensitive time math. | FAIL → split |
| 2. No new architectural decision blocked behind a separate item | R-9 needs **D-070** — the canonical service-prefilter contract. R-10 needs a separate decision — reminder eligibility semantics (wall-clock-local vs absolute-UTC) plus delayed-run resilience strategy. Two unrelated decisions, neither depending on the other. | FAIL → split |
| 3. Combined diff reviewable in one sitting | R-9 estimate: ~70 lines rewrite of `embed.js`, ~15 lines in `embed.tsx`, small SPEC update, ~3-5 new tests. R-10 estimate: significant time-zone-aware math + look-back window + DST-edge tests, likely ~150 lines across command + notification + tests. Combined: ~250 lines, ~8+ tests with DST subtlety. Hard to review in one sitting. | FAIL → split |
| 4. Implementation order is unambiguous | Independent. Either could ship first. | NEUTRAL |

Three conditions fail; one is neutral. **Default per PROMPT is to split.** R-9 ships alone this session; R-10 gets its own planning session.

### 1.4 SPEC is the only source of the drift

The service-prefilter contract lives in exactly one authoritative place: `docs/SPEC.md:269-273` under "Service Pre-filter". Code, iframe preview, iframe snippet, public booking page, and test coverage all use the path form. The only code referencing the query form is SPEC itself. Aligning SPEC to code is the cheap end of the decision tree.

---

## 2. Goal and scope

### Goal

Make the popup embed deliver on SPEC §8's promise. A business admin can copy a per-service popup snippet from the Embed settings page; any host site that embeds the snippet opens a proper modal — with focus trap, scroll lock, duplicate-overlay guard, focus restore, and ARIA roles — that lands the visitor directly on the prefiltered booking step. Codify the canonical service-prefilter contract (path-based) as **D-070** so every surface (SPEC, snippets, popup, iframe) emits the same URL shape.

### In scope

- **Decision D-070** — path-based service prefilter as the canonical contract, to be appended to `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md`.
- **`docs/SPEC.md` §8 update** — rewrite lines 269-279 to document the path form `/{slug}/{service-slug}?embed=1` (iframe) and the new `data-riservo-service` attribute (popup). Remove the query-form example.
- **`public/embed.js` substantive rewrite** — in a single file, keep the vanilla-JS no-bundler design (D-054):
  - Read a service hint: script-tag `data-service` as default; per-button `data-riservo-service` overrides. Build URL = `${base}/{slug}/{service}?embed=1` when present, else `${base}/{slug}?embed=1`.
  - Guard against duplicate overlays — short-circuit `createOverlay()` if one is already open.
  - Lock body scroll on open; restore on close (capture original `overflow` value).
  - Capture `document.activeElement` on open; restore focus on close.
  - Focus trap — tab cycling stays inside the overlay (close button ↔ iframe boundary).
  - ARIA roles — `role="dialog"`, `aria-modal="true"`, `aria-label` from script-tag `data-label` or fallback to iframe title.
  - Minor: move the backdrop-click logic so mousedown-on-iframe then mouseup-on-overlay does not accidentally close (existing bug; tiny fix).
- **`resources/js/pages/dashboard/settings/embed.tsx`** — generate per-service popup snippet. When a service is picked in the existing `Select`, emit a button with `data-riservo-service="<slug>"`. The `data-slug` stays on the `<script>`. Multiple buttons can coexist on the host page against one `<script>` tag.
- **Snippet copy** — keep `<button data-riservo-open>`; add the service attribute conditionally on the button.
- **Tests** — four new, described in §6:
  1. `/embed.js` is served 200 with the right Content-Type.
  2. `/embed.js` contains the `data-riservo-service` marker (regression pin that prefilter support shipped).
  3. `/embed.js` references no external domains (Pest architecture-style test — a substring check for `http://` / `https://` against a whitelist of `data-slug`-resolved URLs).
  4. `GET /dashboard/settings/embed` returns `appUrl` prop equal to an absolute URL (pins the current `url('/')` contract).

### Out of scope

- **Changes to `PublicBookingController::show()` or any booking route.** Path form already works end-to-end; no controller or routing change is needed under the chosen contract. This is the primary payoff of the choice.
- **`?service=` query support.** Rejected by D-070 (see §4).
- **Dual-read or 301 redirect from query form to path form.** No deployed embed snippet uses the query form (audit confirmed only SPEC does). Dual-read buys nothing but surface area.
- **CSP / `X-Frame-Options` hardening.** The app currently sets neither. Adding CSP middleware is a cross-cutting decision that affects every route, not just embed. Carry to §10.
- **i18n of the vanilla-JS popup strings** (`iframe.title = 'Book appointment'`, close button aria-label). Loading a translation file into a third-party-embedded JS widget is a non-trivial decision (per-script-tag `data-locale`? Server-rendered JS? Separate bundle per locale?). Carry to §10.
- **Custom themes / CSS variables for the popup overlay.** Colors and border-radius are hardcoded inline styles for a zero-dependency vanilla JS widget. Theming is a separate decision.
- **Analytics events** (`onOpen`, `onBooked` callbacks). Not in REVIEW-1 §#10; captured in §10 as deferred.
- **Multi-service picker for the popup.** A single button opens a single-service flow; the multi-service picker is the booking page default. No change.
- **The popup's `iframe[title]`.** Left as English `'Book appointment'`. See §10.
- **A calendar-integration embed** or any other embed variant beyond the two documented in SPEC §8. Not in scope.
- **SRI (Subresource Integrity) on the script tag.** The snippet is a same-origin reference to `/embed.js`, not a third-party CDN — SRI adds no value here.
- **Browser test infrastructure** (Pest Browser, Playwright). Same rule as R-7 / R-8 — adding browser-test infra is itself a sizeable session. The test layer R-9 commits to is feature tests + server-side string pins on the shipped `embed.js`. Documented in §6.3.

---

## 3. Approach

### 3.1 D-070 (new) — canonical service prefilter

**File**: `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md` (embed/share management decisions; same home as D-054).

Proposed text:

> ### D-070 — Service prefilter for embed and direct link is a URL path segment
>
> - **Date**: 2026-04-16
> - **Status**: accepted
> - **Context**: The public booking page, iframe embed, and popup embed all need to support landing a visitor directly on a single service's booking flow (skipping the service picker). Pre-R-9, two forms were in play simultaneously: SPEC §8 (lines 269-273) documented a query form `?embed=1&service=<slug>`, while the code already used a path form `/{slug}/{service-slug}?embed=1` (`embed.tsx:40`, `PublicBookingController::show($slug, ?$serviceSlug)`, the catch-all route `/{slug}/{serviceSlug?}` in `routes/web.php:201`, and three tests in `tests/Feature/Booking/PublicBookingPageTest.php`). `?service=` is never read by the controller. The popup embed supports neither. R-9 unifies the contract before teaching `public/embed.js` about services, because shipping a third divergent URL shape inside the popup would compound the drift.
> - **Decision**:
>   1. **Path form is canonical.** A prefiltered embed URL is `/{slug}/{service-slug}?embed=1`. A prefiltered direct link is `/{slug}/{service-slug}`. The `{service-slug}` segment is the business-scoped `services.slug`.
>   2. **`?service=` is not supported.** The controller does not read `request('service')`, and we do not add it. If a host accidentally produces `/salon?embed=1&service=foo`, the `?service=foo` is silently ignored and the visitor lands on the service picker — the same behaviour as an unknown path-form slug.
>   3. **Invalid path slugs are silently ignored.** `PublicBookingController::show()` already checks that the submitted service slug matches an active service for the business; unknown slugs fall through to the default service-picker flow (no 404). This is preserved.
>   4. **SPEC is updated to match.** `docs/SPEC.md` §8 lines 269-279 rewrite the "Service Pre-filter" block to document path form and drop the query-form example.
>   5. **Popup snippet emits the path form via `data-riservo-service`.** A per-button attribute on the `<button data-riservo-open>` element carries the service slug. `public/embed.js` builds the URL as `{base}/{slug}/{service}?embed=1` when present, else `{base}/{slug}?embed=1`. The `<script>` tag's `data-slug` is the only required attribute; an optional script-tag `data-service` sets a page-wide default that per-button attributes override.
>   6. **Iframe snippet is unchanged in shape.** `embed.tsx:40-41` already emits path form; R-9 ratifies it.
>   7. **D-054 is preserved.** `?embed=1` remains the embed-mode switch. D-070 specifies the service axis; D-054 specifies the layout axis. The two are orthogonal.
> - **Consequences**:
>   - One canonical URL shape across SPEC, iframe snippet, popup snippet, direct link, and tests.
>   - Zero server-side work for R-9 — `show()` and routing are unchanged.
>   - The popup gains feature parity with the iframe.
>   - Analytics on the embedded page see a service-scoped pathname by default (`/salon/haircut`) rather than a query parameter on a shared path — cleaner segmentation without additional plumbing on the host.
>   - Future service-rename workflows must either keep the old slug as an alias or accept that embed links break (slug-alias history is already flagged as a carry-over in ROADMAP-REVIEW.md "Public slug stability"; D-070 does not solve it, only names it).
> - **Rejected alternatives**:
>   - *Query form `?embed=1&service=<slug>`.* Would require a controller change (`$request->input('service')`), invalidates every iframe snippet already copied out of the dashboard (path form is already emitted), and analytics on host pages would have to parse query strings rather than read `location.pathname`. Net-negative on every column of §3.2 except matching the (stale) SPEC.
>   - *Dual-read (accept path + query, emit path).* Buys no compat — no deployed snippet uses the query form today (audit confirmed only SPEC drifts). Adds surface area and a silent-rewrite-vs-301 decision for no benefit.
>   - *URL fragment `#service=<slug>`.* Client-only; the server never sees fragments, so SSR hydration can't pre-select the service. Would require a client-side redirect after mount — worse UX and worse analytics.
>   - *A new `/embed/{slug}/{service}` route.* Adds a second embed surface for no reason. `?embed=1` (D-054) already scopes the layout axis; mixing a route-level axis with a query-level axis is redundant.
>   - *`data-service` only on the `<script>` tag (no per-button override).* Forces a host page with multiple services to include multiple `<script>` tags with different `data-slug` values (which re-execute the module IIFE each time and collide on the module-level `overlay` variable). The per-button override is a trivial snippet change with materially better ergonomics.

### 3.2 Decision matrix — contract-shape choice

| Option | URL shareability | Analytics cleanliness | Server routing simplicity | Snippet ergonomics | SEO impact | Backward compat with deployed embeds | Implementation effort | Verdict |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| **Path form `/{slug}/{service}?embed=1`** (chosen) | Clean, readable, "REST-ish"; the service is part of the page identity. | Pageview path is service-scoped by default — no extra config on host GA/Plausible. | Already works. `show($slug, ?$serviceSlug)` + catch-all route already in place. Zero server-side work. | Popup snippet gains a single `data-riservo-service` attribute. Iframe snippet unchanged. | Path form is SEO-friendly for public direct links (`/salon/haircut`). | Full: iframe snippets in the wild (if any — pre-launch, none confirmed) already emit this shape. | Low: ~70 lines in `embed.js`, ~15 in `embed.tsx`, ~15 lines of SPEC text, 3-5 tests. | **CHOSEN.** |
| Query form `?embed=1&service=<slug>` | OK, query-heavy; less readable. | Path is always `/salon`; analytics must parse query strings on the host side. | Requires `show()` to read `$request->input('service')` and still handle the path-form segment (or drop path-form support — which would break the already-shipped iframe snippet). | Equivalent snippet complexity. | `/salon?service=haircut` is worse for public direct links than `/salon/haircut`. | Partial: would need to keep path form working or migrate every already-copied iframe snippet. | Medium: controller change, dual-input handling, migration considerations, more tests. | Rejected. |
| Dual-read (accept both, emit path) | ✓ (canonical path) | ✓ | Controller must read both + maybe 301 the non-canonical → canonical. | ✓ (same as path form for emission). | ~ Dual URLs risk duplicate content if both ever get indexed. | ✓ — every hand-rolled URL works. | Medium: controller change + 301 logic + tests for both inputs. | Rejected — buys nothing because no deployed URL uses the query form today. |
| Hash fragment `/{slug}?embed=1#service=<slug>` | ~ | ✗ Analytics doesn't see fragments. | ✗ Server never sees the fragment; requires client-side redirect after mount. | ✓ | ✗ No SSR prefilter. | N/A | Low on the server, medium on the client. | Rejected — worse UX. |
| New `/embed/{slug}/{service}` route | ✓ | ~ | ✗ Adds a second embed surface that must be maintained alongside `?embed=1`. | ~ | ~ | ✗ Invalidates existing iframe snippets. | Medium-high: new controller, new tests, deprecation path. | Rejected — adds a redundant axis. |

**Recommendation: Path form.** Every column of the matrix favours it against every alternative, and it aligns SPEC to working code rather than the reverse.

### 3.3 Popup snippet shape — data-attribute-driven

The SPEC §8 shape stays close to the existing snippet:

```html
<!-- No service prefilter -->
<script src="https://riservo.ch/embed.js" data-slug="salone-mario"></script>
<button data-riservo-open>Book Now</button>

<!-- With service prefilter -->
<script src="https://riservo.ch/embed.js" data-slug="salone-mario"></script>
<button data-riservo-open data-riservo-service="taglio-capelli">Book haircut</button>
```

Rules (implemented in `embed.js`):

- Script-tag `data-slug` is **required**. No fallback.
- Script-tag `data-service` is **optional** — page-wide default.
- Button `data-riservo-service` is **optional** — per-button override. If present, wins over script-tag default.
- Button `data-riservo-open` remains the trigger marker. No change.
- Invalid service slug at the server (handled gracefully by `show()` — silently falls through to the service picker). No client-side validation needed.

Justification vs the alternatives in §3.2:

- **URL-driven `<a href="/salon/haircut?embed=1">Book</a>`** — works because the catch-all route handles it, but it bypasses `embed.js` entirely (no popup, just a navigation). Useful for non-embed direct links but not for the "popup over my site" use case. Not a replacement for the script/button pattern.
- **Data-attribute-driven** (chosen) — matches the existing `data-slug` / `data-riservo-open` pattern the SPEC already documents. Adding one more `data-*` attribute is consistent.
- **Hybrid with URL fallback** — rejected. Two code paths for no reason.

The admin dashboard reflects the choice by updating `popupSnippet` in `embed.tsx` when `previewService` changes, matching how `iframeSnippet` already updates.

### 3.4 Modal robustness — what's missing and how we add it

`public/embed.js` today: 80 lines, vanilla JS, IIFE, module-level `overlay` and `iframe` refs, Esc close, backdrop close, close button with `aria-label`.

What it's missing (REVIEW-1 §#10 + audit):

| Gap | Current state | R-9 fix | `embed.js` line reference |
| --- | --- | --- | --- |
| **Focus trap** | Absent. Once the iframe loads, tab focus can escape to the host page. | On open, attach a `keydown`-tab listener to the overlay that cycles focus between the close button and the iframe. `Shift+Tab` reverses. Iframe contents manage their own tab order same-origin. | Add around `embed.js:48` (after `document.body.appendChild`). |
| **Scroll lock** | Absent. Host body scrolls behind the modal. | On open, capture `document.body.style.overflow` into a local, set `body.style.overflow = 'hidden'`. On close, restore the captured value. | Add at `embed.js:48` (open) and inside `close()` at line 55. |
| **Duplicate-overlay guard** | Absent. Clicking `[data-riservo-open]` twice creates a second overlay; the module-level `overlay` variable is overwritten, leaking the first DOM subtree. | Add `if (overlay) return;` at the top of `createOverlay()` (line 19). | `embed.js:19`. |
| **Focus restore on close** | Absent. Close puts focus wherever the browser leaves it (usually `document.body`). | On open, capture `document.activeElement` into `triggerEl`. On close, after the fade-out, call `triggerEl.focus()` if it's still in the DOM. | Add at `embed.js:19` (capture) and inside the `setTimeout` callback at line 58 (restore). |
| **`role="dialog"` + `aria-modal`** | Absent. Screen readers don't announce the overlay as a modal. | On the `container` element (line 24), set `role="dialog"`, `aria-modal="true"`, `aria-label="Book appointment"` (fallback; derived from `data-label` on script if present). | `embed.js:24`. |
| **`aria-labelledby`** | Absent. Not strictly needed if `aria-label` is set, but a `<h1 class="sr-only">` inside the container referencing the business name would be stronger. Optional. | Optional: emit a visually-hidden heading with the business name, bound via `aria-labelledby`. Requires passing the business name into `embed.js` — skip for R-9; `aria-label` is sufficient. | N/A (deliberately skipped). |
| **Backdrop close vs mousedown-in-iframe** | Existing subtle bug: `overlay.addEventListener('click', e => { if (e.target === overlay) close(); })` catches a click whose mousedown happened inside the iframe and whose mouseup lands on the overlay (user drags while selecting text inside the iframe). Rare but surprising. | Switch to `mousedown` + `mouseup` both checking `e.target === overlay`. Only close if both happen on the overlay itself. | `embed.js:44-46`. |

Design note: the iframe is same-origin (`/{slug}?embed=1` served from the same Laravel app), so the focus trap can trust that the browser handles tab order inside the iframe naturally. This is important — a cross-origin iframe would require `postMessage` plumbing for focus management, which is a different scope.

No `inert` attribute on body children — `inert` is supported in modern browsers but the belt-and-suspenders combination of focus trap + scroll lock + `aria-modal` is already conformant. Adding `inert` is a nice-to-have that can ship later without breaking the R-9 API.

### 3.5 Audit drift — none

PLAN-R-8 §1.3 claimed:

1. SPEC documents query form at SPEC.md:269-273 — **confirmed**.
2. Iframe uses path form at `embed.tsx:40` — **confirmed**.
3. `?service=` is never read — **confirmed**.

No drift. R-9 proceeds on PLAN-R-8's characterization.

Adjacent findings during audit (not drift, additional context):

- The iframe Live Preview already uses the path form — the contract inside the app is path form; only SPEC and the popup diverge. This makes R-9's job easier.
- `public/embed.js` already has more modal behaviour than REVIEW-1 §#10 implied (Esc, backdrop, close button). The missing items list is still the right list, just with two that are already done.
- The `EmbedController` mentioned in the prompt's audit section is the dashboard settings controller, not an embed-rendering controller. No embed-rendering controller exists (embed mode is a query param). This reduces R-9's server-side footprint to zero.

---

## 4. New decisions

### D-070 — full text in §3.1 above

That is the only new decision. R-9 does not touch any existing locked decision (D-001 through D-069).

---

## 5. Implementation order

Each step leaves the suite green. Today's baseline: **468 passing** (R-8 baseline per HANDOFF).

### Step 1 — D-070 decision file (docs only)

Append D-070 to `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md` using the text in §3.1. This is the design anchor. Also update `docs/SPEC.md` §8 lines 269-279 to document the path form and remove the query-form example (wording below).

Proposed SPEC §8 replacement for lines 269-279:

```markdown
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
```

**Verifies**: `php artisan test --compact` still 468 passing (no code touched).

### Step 2 — Popup snippet in `embed.tsx`

Update `embed.tsx:42` to emit a service-aware popup snippet:

```tsx
const popupButton = previewService
    ? `<button data-riservo-open data-riservo-service="${previewService}">${t('Book Now')}</button>`
    : `<button data-riservo-open>${t('Book Now')}</button>`;
const popupSnippet = `<script src="${appUrl}/embed.js" data-slug="${slug}"></script>\n${popupButton}`;
```

The existing `Select` at `embed.tsx:104-124` already drives `previewService`. No new prop. No new state.

**Verifies**: `npm run build` (TypeScript compiles). Existing `EmbedTest` still 4/4.

### Step 3 — `public/embed.js` rewrite

Full rewrite of `public/embed.js` per §3.3 + §3.4. Stays under ~140 lines, vanilla JS, one IIFE, no build step. Keys:

- Read `data-slug` (required), script-tag `data-service` (optional), script-tag `data-label` (optional; used as `aria-label` on the dialog).
- Add `resolveService(triggerEl)`: returns the button's `data-riservo-service` if set, else the script-tag default, else `null`.
- `buildUrl(service)` → `{base}/{slug}` + (service ? `/{service}` : '') + `?embed=1`.
- `createOverlay(triggerEl)` signature — takes the trigger so we can resolve the service and restore focus.
- At top of `createOverlay`: `if (overlay) return;` — duplicate guard.
- Capture `triggerEl = triggerEl || document.activeElement` into module-level `lastTrigger`.
- Capture `originalBodyOverflow = document.body.style.overflow`; set `document.body.style.overflow = 'hidden'`.
- Container gets `role="dialog"`, `aria-modal="true"`, `aria-label="Book appointment"` (or script-tag `data-label`).
- `iframe.src = buildUrl(resolveService(triggerEl))`.
- `trapFocus(e)` — if `e.key === 'Tab'`, compute focusable elements inside container (close button, iframe), and cycle. `Shift+Tab` reverses.
- `document.addEventListener('keydown', trapFocus)` — added on open, removed on close.
- `close()` restores scroll, removes `trapFocus`, restores focus on `lastTrigger`.
- Backdrop: `mousedown` + `mouseup` both check `e.target === overlay`.
- Click dispatcher passes the trigger: `createOverlay(target)`.

**Verifies**: `npm run build` (no frontend-bundled output change — `embed.js` is a static file); full test suite still 468; manual sanity on `/embed.js` served 200 via `curl -I https://riservo-app.test/embed.js`.

### Step 4 — Tests

Add four tests to `tests/Feature/Settings/EmbedTest.php` (same file as existing embed settings tests):

```php
test('embed.js is served with javascript content-type', function () {
    $this->get('/embed.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript');
});

test('embed.js supports per-button service prefilter', function () {
    $response = $this->get('/embed.js');
    $response->assertOk();
    expect($response->getContent())
        ->toContain('data-riservo-service')
        ->toContain('data-slug');
});

test('embed.js references no external domains', function () {
    $content = file_get_contents(public_path('embed.js'));

    // All http/https references must be in a known-allowed list
    // (currently: none — embed.js is entirely self-referential via script.src).
    preg_match_all('#https?://[^\s"\'`]+#', $content, $matches);

    expect($matches[0])->toBeEmpty();
});

test('embed settings page exposes appUrl prop as absolute URL', function () {
    $this->actingAs($this->admin)
        ->get('/dashboard/settings/embed')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/settings/embed')
            ->where('appUrl', fn ($v) => is_string($v) && str_starts_with($v, 'http'))
        );
});
```

Rationale:

- Test 1 pins the Content-Type. If Laravel / the web server ever changes how `public/*.js` is served (e.g., via a catch-all route fallthrough), this fails.
- Test 2 pins that the prefilter feature actually shipped in the file. A lightweight regression guard — if someone deletes the new code paths, this fails before manual QA.
- Test 3 is a supply-chain / supply-simplicity guard: the popup widget must not reach out to external CDNs or analytics endpoints. If a future contributor adds a `fetch('https://plausible.io/...')` inside the widget, this fails. The whitelist is empty today by design.
- Test 4 locks the `appUrl` prop contract that the popup snippet in `embed.tsx:42` depends on. Without this, a refactor that turns `appUrl` into a relative path would silently break all copied popup snippets.

The content-inspection tests (2, 3) could alternatively live in `tests/Unit/Embed/EmbedJsStaticAnalysisTest.php` as a Pest unit test with no DB; I prefer the Feature test file for co-location with the other embed tests.

**Verifies**: 4 new tests pass. Total 472 passing (468 + 4).

#### Correction applied at implementation time

Tests 1 and 2 as originally written used `$this->get('/embed.js')->assertOk()->assertHeader(…)`. Probing the Laravel HTTP kernel at implementation time showed that request hits the catch-all `/{slug}/{serviceSlug?}` route and returns 404 — `public/*.js` is served by nginx/Herd in production and never reaches the Laravel kernel, so feature tests cannot exercise it. The plan framed the concern narrowly (Herd returning `text/javascript` instead of `application/javascript`); the deeper truth is that static public assets are invisible to the feature-test layer by construction.

The shipped tests switch to file-system inspection, matching Test 3's pattern:

- Test 1 — `embed.js ships as a non-empty vanilla JS IIFE`: asserts `file_exists(public_path('embed.js'))`, non-zero size, and that the content starts with `(function` — the D-054 "single IIFE, no bundler" invariant.
- Test 2 — substring checks via `file_get_contents(public_path('embed.js'))` instead of an HTTP request.
- Tests 3 and 4 are unchanged.

Still four tests, still 472 total. Content-Type verification remains a production/web-server concern (confirmed with `curl -I http://riservo-app.test/embed.js` returning `application/javascript; charset=utf-8`).

### Step 5 — Pint, full test run, build

- `vendor/bin/pint --dirty --format agent` — only the new tests + any PHP touched appear dirty.
- `php artisan test --compact` — expected 472 passing.
- `npm run build` — expected clean build (no TypeScript regressions from the tiny `embed.tsx` edit).

### Step 6 — Manual QA (browser)

Run §6.5 manual QA checklist on a host page. Document outcomes before declaring R-9 complete.

### Step 7 — HANDOFF + roadmap update

- Overwrite `docs/HANDOFF.md` with the R-9 summary. Note D-070 as the anchor; note SPEC §8 was updated.
- `docs/reviews/ROADMAP-REVIEW.md` has no checkboxes for R-9; leave the prose section as-is.
- Move `docs/plans/PLAN-R-9-EMBED-PREFILTER.md` to `docs/archive/plans/`.

---

## 6. Verification

### 6.1 Existing-test audit

- `tests/Feature/Settings/EmbedTest.php` (4 tests, 50 lines) — covers admin can view settings, services prop, `?embed=1` prop propagation. **None test `appUrl` or the popup snippet shape.** R-9 adds those.
- `tests/Feature/Booking/PublicBookingPageTest.php` (3 service-prefilter tests at lines 103, 115, 161) — covers path-form prefilter end-to-end, invalid-slug fallthrough, and `allow_provider_choice = false` preselection. **Unchanged by R-9.** Continues to pass.
- Every other test file in `tests/Feature/` — unaffected. R-9 touches no booking, calendar, onboarding, or auth flow.

### 6.2 New tests (4)

Described in §5 Step 4. One Content-Type pin; two content-inspection pins on the shipped `public/embed.js`; one prop-contract pin on `appUrl`.

### 6.3 What IS and ISN'T automatable

| Concern | Automated? | How / why not |
| --- | --- | --- |
| Path-form service prefilter on the booking page | ✓ | Three existing tests in `PublicBookingPageTest.php`. |
| `embed.js` serves 200 with correct Content-Type | ✓ | New test. |
| `embed.js` contains the prefilter marker (`data-riservo-service`) | ✓ | New test (substring check on response body). |
| `embed.js` references no external domains | ✓ | New test (regex over file contents). |
| `appUrl` prop is an absolute URL | ✓ | New test. |
| Popup snippet string emitted by React is correct | ✗ (indirectly) | The snippet is built in the component; Inertia tests can't introspect component-local derived state. The closest pin is `appUrl` (above); the snippet construction is a one-line template literal that reviewer can audit in the diff. Browser test would catch it but is out of scope. |
| Focus trap actually cycles | ✗ | Requires a browser. Manual QA only. |
| Scroll lock on host body | ✗ | Requires a browser. Manual QA only. |
| Duplicate-overlay guard | ✗ | Requires a browser. Manual QA only. |
| ARIA roles applied | ✗ (unit-style) | Could be pinned via a content check (`toContain('role="dialog"')`), but that only confirms the literal string is in the source, not that it's applied to the right element. Manual QA is more honest. |
| Dialog keyboard accessibility | ✗ | Requires a browser + a real screen reader pass. Documented for manual QA; not CI-enforced. |
| Visual rendering at 375 / 768 / 1280 px | ✗ | Requires a browser. Manual QA only. |

Introducing Pest Browser plugin + Playwright for a single widget would flip the cost-benefit for the project, not just R-9. Deferred per R-7 / R-8 precedent; captured in §10.

### 6.4 Test count

- Existing: 468 passing.
- +4 new tests in `EmbedTest.php`.
- **Expected total: 472 passing.**

### 6.5 Manual QA checklist (browser)

Set up: create a simple HTML host page (not committed — a scratch file) that includes the generated popup snippet. Alternatively, use a tool like JSFiddle / Codepen pointing at Herd's public URL, or a `public/test-embed.html` scratch file that is not shipped. The host page has some visible body content (Lorem ipsum) tall enough to verify scroll lock.

Run all of these on Chrome, Firefox, and Safari if possible; at least Chrome + one mobile viewport.

1. **Default popup opens.** Click a `<button data-riservo-open>Book Now</button>` on the host page. **Expected**: modal fades in; focus moves to the close button; iframe loads `/{slug}?embed=1` (no service); booking flow lands on the service-picker step.
2. **Service prefilter opens the right step.** Click `<button data-riservo-open data-riservo-service="haircut">Book haircut</button>`. **Expected**: iframe loads `/{slug}/haircut?embed=1`; booking flow lands on the provider step (if `allow_provider_choice = true`) or the datetime step (if false). The service picker is skipped. Re-do with an unknown slug (`data-riservo-service="bogus"`) — **expected**: iframe loads `/{slug}/bogus?embed=1`, server falls through to the service picker (per existing `invalid service slug is ignored` test behaviour).
3. **Esc closes.** Press Escape while modal is open. **Expected**: modal fades out; focus returns to the button that opened it (verify by pressing Enter/Space — the same button reopens it).
4. **Backdrop click closes.** Click the dark area around the modal. **Expected**: modal closes; focus returns to the trigger. Now try dragging: mousedown on the backdrop, drag over the iframe, mouseup on the iframe — **expected**: modal does NOT close. Try the reverse: mousedown on the iframe, drag out to the backdrop, mouseup on the backdrop — **expected**: modal does NOT close (the mousedown-on-iframe-then-mouseup-on-overlay bug in the pre-R-9 code is fixed).
5. **Close button works.** Click the `×` in the top-right. **Expected**: modal closes; focus returns to the trigger.
6. **Focus trap.** Open the modal. Press Tab. **Expected**: focus cycles to iframe contents; press Tab enough times to reach the last focusable inside iframe → one more Tab returns focus to the close button (not the host page). Shift+Tab reverses. Focus never escapes the overlay.
7. **Scroll lock.** Scroll the host page so there's content below the modal. Open the modal. Try scrolling the host page with mouse wheel / touch / keyboard (arrow keys, space) behind the modal. **Expected**: host page doesn't scroll. Close the modal. **Expected**: host page scrolls normally again; previous scroll position preserved.
8. **Duplicate-open guard.** Click the trigger button rapidly twice in a row (or bind to `dblclick`). **Expected**: only one modal opens; no stacked overlays in the DOM (inspect via DevTools — one `<div>` at `document.body > :last-child`, not two).
9. **Multiple triggers on one page.** Have two `<button data-riservo-open>` elements — one with `data-riservo-service="haircut"`, one without. Clicking each produces the correct URL. Closing one and opening the other works normally.
10. **Duplicate `<script>` tags.** Just in case a host mis-installs two `<script src=".../embed.js">` tags. **Expected**: not great but not catastrophic — the IIFE runs twice, both register keydown and click listeners, but the duplicate-overlay guard keeps only one modal open. Document any surprises.
11. **ARIA roles present.** Inspect the open modal with DevTools → `role="dialog"`, `aria-modal="true"`, `aria-label` present on the container.
12. **Screen reader sanity check.** VoiceOver on macOS or NVDA on Windows announces "dialog, Book appointment" when the modal opens.
13. **Mobile viewport.** Chrome DevTools device emulator at 375 px. The modal container is `width: 90%; max-width: 500px; height: 85vh` — should render well on a phone. Tap outside, tap close, Esc-via-software-keyboard (Android): behaviour matches desktop.
14. **Booking flow still works end-to-end inside the popup.** Make a real test booking: pick datetime, fill customer details, submit. Modal stays open through the flow. Confirmation renders inside the iframe. Closing the modal does not cancel the confirmed booking.
15. **Copied popup snippet from the dashboard matches the documentation.** In the dashboard at `/dashboard/settings/embed`, pick a service in the iframe Select. Copy the popup snippet. Verify it matches the SPEC example shape exactly (script with `data-slug`; button with `data-riservo-open data-riservo-service="…"`).

### 6.6 What to watch for during QA

- **iOS Safari scroll lock edge case.** On iOS Safari, `body { overflow: hidden }` alone is sometimes insufficient to stop scroll — the workaround is `position: fixed; top: -Yy px` where `Y` is the current scroll position. If QA #7 reveals iOS Safari still scrolls, apply the `position: fixed` workaround inside `embed.js`'s scroll-lock code. Document the trade-off.
- **Third-party script conflicts on the host page.** If the host site uses a CSS framework with a strict `body { overflow: auto !important }`, the scroll lock won't work. Not R-9's problem; note in release docs.
- **Iframe same-origin cookie.** The booking iframe is same-origin (`riservo.ch/{slug}?embed=1`). Safari ITP treats `SameSite=Lax` cookies inside iframes inconsistently for cross-site embed contexts. If QA #14 fails to keep the visitor signed in through the booking flow, it's a separate issue — document and carry to §10.
- **Host page's own modal systems.** If the host page already has a modal library binding to Escape, ordering matters. `embed.js` listens at `document` bubble; host modal may listen at `document` capture. Worst case: Esc closes both. Rare; document.

---

## 7. Files to create / modify / delete

### Created

- `docs/plans/PLAN-R-9-EMBED-PREFILTER.md` — this file (moved to `docs/archive/plans/` on session completion).

### Modified

- `public/embed.js` — full rewrite per §3.3 + §3.4. Stays vanilla JS, single IIFE, no build step. ~140 lines.
- `resources/js/pages/dashboard/settings/embed.tsx` — update `popupSnippet` construction (lines ~42) to emit `data-riservo-service` when `previewService` is set.
- `docs/SPEC.md` — replace lines 269-279 with the path-form documentation per §5 Step 1.
- `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md` — append D-070 with the full text from §3.1.
- `tests/Feature/Settings/EmbedTest.php` — add four tests from §5 Step 4.
- `docs/HANDOFF.md` — overwrite with R-9 summary (post-implementation).

### Deleted

None.

### Renamed

None.

### Explicitly untouched (despite being in the R-9 area)

- `app/Http/Controllers/Booking/PublicBookingController.php` — path form already works. No controller change.
- `app/Http/Controllers/Dashboard/Settings/EmbedController.php` — prop contract unchanged.
- `resources/js/components/settings/embed-snippet.tsx` — dumb display component; no URL logic here.
- `routes/web.php` — catch-all already accepts `/{slug}/{serviceSlug?}`.

---

## 8. Risks and mitigations

### 8.1 Backward compatibility for deployed popup snippets (none exist; minor risk for iframe)

**Scenario**: an admin has already copied a popup snippet before R-9 ships. The pre-R-9 snippet has no service attribute; R-9 does not change the `<script>` or `<button data-riservo-open>` contract — only adds an optional attribute.

**Mitigation**: zero-breakage-by-design. Pre-R-9 snippets keep working unchanged; they simply don't get prefilter. The pre-R-9 iframe snippet also keeps working — the path form has been canonical in the iframe since Session 9. The new SPEC text reflects reality; it does not introduce a new URL shape.

Audit evidence: the pre-launch state (per ROADMAP-REVIEW.md) means there are no known third-party sites running these snippets today. Zero-breakage is therefore "best-effort expected" rather than "tested against telemetry." Captured as a BACKLOG item ("instrument embed load for analytics once live") but not blocking R-9.

### 8.2 CSP / inline-script behavior in host pages

**Scenario**: the host site has a strict CSP (`Content-Security-Policy: script-src 'self'`) that blocks `<script src="https://riservo.ch/embed.js">`. `embed.js` loads from a different origin than the host; if the host's CSP doesn't whitelist `riservo.ch`, the script is blocked and the popup never opens.

**Mitigation**: the snippet copy UX already implies the host trusts riservo.ch (they copy-pasted a `<script src>` tag). If CSP blocks it, the host admin sees a console error immediately during integration — standard diagnosis. Document in a release note: "Add `riservo.ch` to your CSP `script-src` directive if you use strict CSP."

Explicitly out of scope: serving `embed.js` from the customer's own domain via a proxy (that would add hosting complexity and break cache strategy). Also out of scope: SRI hashes — the script is still served from our domain; SRI protects against CDN tampering, which we don't have.

### 8.3 Iframe-in-iframe

**Scenario**: a host page is itself already inside an iframe (e.g., the host is a Wix site rendered inside an `<iframe>` somewhere). The popup overlay + iframe-inside-iframe nests three levels deep. Focus, scroll-lock, and cookie behaviour get weird.

**Mitigation**: we don't officially support this configuration. The popup is designed for direct host-page embedding. Document as a known limitation. If QA reveals a hard break, the fallback is "install as a direct link" (the SPEC direct-link option at `/{slug}/{service-slug}`).

### 8.4 Cookie / session behavior in the embedded iframe

**Scenario**: Safari ITP (Intelligent Tracking Prevention) strips `SameSite=Lax` cookies from cross-site iframes after 7 days of no first-party interaction, breaking session continuity. `SameSite=None; Secure` fixes it but requires HTTPS end-to-end.

**Mitigation**: audit Laravel's session cookie config (`config/session.php`). Default is `SameSite=Lax`. If R-9 QA reveals Safari session issues inside the embedded iframe, document as a separate finding and either:
- Change `SESSION_SAME_SITE=none` (requires `SECURE_COOKIES=true` — fine, production is HTTPS-only), or
- Scope the iframe to a stateless flow (no session cookies required — tricky given the booking flow uses CSRF).

Escalated into §10 rather than decided here; Safari ITP behaviour is a cross-cutting session topic, not an R-9-specific bug.

### 8.5 HTTP → HTTPS host mismatch

**Scenario**: host page loads over HTTP; `embed.js` references `https://riservo.ch/embed.js` — browser blocks the script as mixed content.

**Mitigation**: release note: "Use HTTPS on your embedding site." No code change in R-9. Very few production sites are HTTP-only today.

### 8.6 Focus trap breaks if iframe is slow to load

**Scenario**: on a slow connection, the iframe takes a second to load. During that second, the focus trap cycles between close button and the (not-yet-loaded) iframe. Tab lands on the iframe element which has no focusable content yet; screen readers report "frame."

**Mitigation**: accepted trade-off. Initial focus goes to the close button (safe). The iframe's internal focus happens once it loads. No extra mitigation needed; behaviour is no worse than any other same-origin modal with an iframe child.

### 8.7 `data-riservo-service` slug typo

**Scenario**: an admin hand-edits the snippet and mistypes the slug. `embed.js` builds `/{slug}/typo?embed=1`; `show()` silently falls through to the service picker.

**Mitigation**: no client-side validation. The user gets the full service picker, which is the safe fallback. This is identical to the existing path-form direct-link behaviour (tested by `invalid service slug is ignored`). Document the behaviour in the dashboard copy — the "Pre-filter by service" field description already says "Optional. Skips the service picker and opens directly on the selected service." Add one line: "If the service is renamed, the link continues to work and falls back to the service picker."

### 8.8 `public/embed.js` grows past the one-IIFE budget

**Scenario**: the rewrite adds enough features that a single IIFE becomes unwieldy. The project has no bundler for `public/*.js`.

**Mitigation**: target ~140 lines for the rewrite. If the file grows past ~200 lines during implementation, stop and reconsider: (a) still one file; (b) internal helper functions inside the IIFE; (c) JSDoc type annotations for internal sanity. Do NOT introduce a Vite-bundled artefact for this widget — D-054 chose vanilla JS specifically to keep the embed zero-dependency for third parties.

### 8.9 Tests whitelist regex is fragile

**Scenario**: the "no external domains" test (§5 Step 4, test 3) uses a regex `#https?://[^\s"\'\`]+#`. If a future contributor needs to add a legitimate external URL (e.g., a canonical-link comment `// See https://riservo.ch/docs/embed`), the test fails spuriously.

**Mitigation**: start with whitelist = `[]` and grow it only when a real external reference is needed. If a legit reference is added, the test change is a single-line list update — small friction, large safety return. Could be relaxed to only check inside string literals (parser-based) if it becomes annoying; for R-9 the simple regex is enough.

### 8.10 D-070 names a contract the popup inherits but doesn't test end-to-end

**Scenario**: R-9 ships tests that the `embed.js` source contains `data-riservo-service`, but no automated test actually exercises a popup click → URL construction → backend route → Inertia prop chain end-to-end.

**Mitigation**: the chain is already tested in parts: (a) path-form URL → controller prop (3 tests in `PublicBookingPageTest.php`); (b) `embed.js` source pin (new tests in R-9); (c) manual QA #2 + #5 runs the end-to-end click path. The intermediate link — "JS builds the URL correctly from the DOM attribute" — is the one untestable segment without browser infra. Accepted; captured in §6.3 and §10.

---

## 9. What happens after R-9

The remediation roadmap continues with **R-10** (reminder DST + delayed-run resilience, Medium). Independent of R-9 — different files, different concepts, different decision. A separate planning session.

Items surfaced by R-9 that land in BACKLOG (not part of R-9 itself):

- **i18n for `public/embed.js` strings.** The vanilla JS widget hardcodes `iframe.title = 'Book appointment'` and the close button's `aria-label='Close'` in English. Loading translations into a third-party-embedded widget is non-trivial (per-script-tag `data-locale`? A separate `/embed-{locale}.js`? A `window.riservoLocale` global?). Separate decision; not R-9 scope.
- **Analytics events** (`onOpen`, `onBooked`, `onClose` callbacks). Useful for host pages wanting to track popup engagement. Post-MVP.
- **Custom theming** (CSS variables on the overlay / container for color, border-radius, etc.). Post-MVP.
- **CSP hardening** (`Content-Security-Policy`, `X-Frame-Options: ALLOW-FROM` or `frame-ancestors`). Cross-cutting, not embed-specific.
- **Safari ITP session continuity in iframes.** See Risk 8.4. Revisit once real production traffic surfaces issues.
- **Slug-alias history** — if a business renames their slug or a service slug, deployed embed links break silently. Carry-over from ROADMAP-REVIEW.md "Public slug stability"; D-070 does not solve it.
- **Browser test infrastructure** (Pest Browser, Playwright). Same carry-over as R-7 / R-8.
- **Telemetry for "how many deployed popup snippets exist in the wild."** Once real, guides whether future contract changes need a deprecation period.
- **Dashboard snippet copy UX.** A single Select that flips between "no service" and "<service>" snippets is minimal. A future polish pass could show both by default with a toggle, or expose a copy-as-HTML-blob that includes comments like `<!-- Replace with your preferred button markup -->`.

When the next R-10 plan is requested, the planning prompt should cite this plan's §1.3 for the bundle-decision reasoning so the R-10 planner does not re-evaluate bundling with R-9.

---

## 10. Carried-to-BACKLOG / deferred

The following are explicitly deferred and should be captured in `docs/BACKLOG.md` at R-9 implementation time (one-liners; not decisions):

- **Popup widget i18n** — load translations into `public/embed.js` (decide: per-script `data-locale`? server-rendered `/embed-{locale}.js`?).
- **Popup analytics events** (`onOpen`, `onBooked`, `onClose` callbacks) for host-page telemetry.
- **Custom theming for the popup overlay** (CSS variables for container colors, border-radius, close-button style).
- **Multi-service picker embed** — open the popup on a service-picker step rather than a single-service flow. Today's SPEC is single-service; a "Book with us" button that shows a picker inside the popup is a separate feature.
- **CSP / X-Frame-Options hardening** — cross-cutting; not embed-specific.
- **Safari ITP session-cookie behaviour inside iframes** — investigate once real traffic surfaces an issue.
- **Slug-alias history** — old embed URLs should continue to work when a business renames their slug or a service's slug (ROADMAP-REVIEW.md "Public slug stability" carry-over).
- **Browser test infrastructure** (Pest Browser, Playwright, etc.) — add once the value across the whole project justifies the session cost.
- **Telemetry "popup snippets deployed in the wild"** — informs future contract changes.
- **Dashboard embed-settings copy UX polish** — toggles, multi-service snippet view, inline copy-with-comments.
- **SRI hashes on the script tag** — revisit if/when `embed.js` is served from a CDN rather than the app origin.
