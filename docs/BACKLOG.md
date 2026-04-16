# Backlog

This file captures unscheduled follow-up work, UX ideas, and deferred engineering cleanup. Items here are notes, not committed roadmap work.

## UI Follow-up

- Revisit booking formatter ownership once the multi-language pass lands. The helpers in `resources/js/lib/booking-format.ts` currently format client-side via `Intl`; move formatting to Laravel controllers once business-locale formatting becomes product-critical.
- Reassess whether more global theme tokens should be repainted beyond the current honey/paper/ink overrides. `card`, `popover`, `sidebar`, `input`, `secondary`, `destructive`, and chart/code tokens were intentionally left closer to upstream defaults during the booking-flow UI consolidation.
- Review whether the dashboard calendar should eventually be aligned more closely with the newer booking-flow primitive usage, but keep that as a dedicated follow-up rather than bundling it into unrelated UI work.

## UX Ideas

- Consider Inertia polling for the dashboard home so appointment counts refresh without a manual reload once the app has real traffic.
- Evaluate link prefetching for calendar navigation and adjacent booking screens after the calendar work is complete enough to measure perceived performance.
- Revisit scroll preservation on the bookings list so returning from detail views or panels does not reset position unnecessarily.

## Technical Debt / Deferred Engineering Cleanup

- The onboarding logo upload path still reflects the older standalone-request implementation described in D-042. When that area is touched again, evaluate migrating it to the current Inertia v3 `useHttp` pattern.
- If `docs/design/ui.pen` stops being useful as a repo-local reference, move it out of the repository and update `docs/README.md` accordingly.

## Embed & Share (R-9 carry-overs)

- Popup widget i18n — load translations into `public/embed.js` (decide: per-script `data-locale`? server-rendered `/embed-{locale}.js`? `window.riservoLocale` global?). Today `iframe.title = 'Book appointment'` and the close button's `aria-label='Close'` are English-only.
- Popup analytics events — `onOpen`, `onBooked`, `onClose` callbacks for host-page telemetry.
- Custom theming for the popup overlay — CSS variables for container colors, border-radius, close-button style.
- Multi-service picker embed — a "Book with us" trigger that opens the popup on the service picker rather than a single service flow.
- CSP / `X-Frame-Options` hardening — cross-cutting, not embed-specific; the app currently sets neither.
- Safari ITP session-cookie behaviour inside iframes — investigate once real traffic surfaces an issue (D-070 Risk 8.4).
- Slug-alias history — old embed URLs should keep working when a business renames its slug or a service's slug (D-070 names the problem; does not solve it).
- Browser test infrastructure — Pest Browser plugin or Playwright, sized as its own session. Would unlock automated modal-robustness coverage for R-9.
- Deployed-snippet telemetry — instrument `embed.js` load to count "popup snippets in the wild," informing future contract changes.
- Dashboard embed-settings copy UX polish — toggles, multi-service snippet view, inline copy-with-comments.
- SRI hashes on the `<script>` tag — revisit if/when `embed.js` ever moves to a CDN rather than the app origin.
