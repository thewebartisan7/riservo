# Initial Prompt — R-8 Manual QA Closure - This will be done at the end of ROADMAP-REVIEW-1.md

You are closing out R-8 — the calendar mobile improvements. Code is
already shipped and green (468 passing, clean Pint, clean build). The
previous agent could not drive a browser, so the 10-item manual QA
checklist was deferred to you. You are running in the Claude Code
desktop app with a Chrome extension via Preview — use it.

**Scope of this session is exactly R-8 QA. Nothing else.** Do not
start R-9 planning, even if you finish early. R-9 gets its own
planning session with max effort (see `docs/PROMPT-R9-PLAN.md`).

Medium effort is appropriate for this session — the work is
mechanical observation against a detailed checklist.

---

## Required reading (in order)

1. `.claude/CLAUDE.md` — session workflow, critical rules, session
   done checklist.
2. `docs/HANDOFF.md` — R-8 state summary + the "Manual QA" checklist
   you'll run.
3. `docs/archive/plans/PLAN-R-8-CALENDAR-MOBILE.md` — §6.5 (QA detail)
   and §8 (Risks + documented mitigations). Read §8 before testing so
   you know the fallbacks if something fails.

Do NOT read `docs/reviews/REVIEW-1.md`, `docs/reviews/ROADMAP-REVIEW.md`,
or any other review material for this session. They are R-9 concerns.

---

## The 10-item checklist (from PLAN-R-8 §6.5 and HANDOFF)

URL: `http://riservo-app.test/dashboard/calendar`

Use Chrome DevTools device emulator at **375 px** (iPhone SE),
**768 px** (iPad Mini), and **1280 px** (desktop). A real phone is a
bonus if convenient, not required.

1. **Default landing on mobile (375 px).** Admin visits
   `/dashboard/calendar`. Expected: week view; day strip at top;
   agenda list below grouped by day with date headers; empty days
   show "No bookings"; today's row has a "Today" badge; view
   switcher in header reads "Week view".
2. **Switch view on mobile.** Tap switcher → "Day view". Expected:
   navigates to `?view=day&date=…`; day view renders correctly.
   Switch back to week (agenda). Switch to month (existing mobile
   month grid).
3. **Tap a booking on mobile.** From the agenda, tap a booking card.
   Expected: `BookingDetailSheet` opens. Close, tap a different
   booking.
4. **Empty week on mobile.** `?view=week&date=2030-01-01`. Expected:
   7 day rows, each with "No bookings".
5. **Tablet (768 px).** Expected: time grid back (not agenda);
   switcher visible at `w-[130px]`; events render in 7-column layout
   normally.
6. **Desktop (1280 px).** Expected: identical to pre-R-8. Regression
   check.
7. **Hydration console check.** Mobile-emulated week view, refresh,
   watch DevTools Console. Expected: NO `<li> cannot be a descendant
   of <li>` warning and no other calendar hydration warnings.
   **This is non-negotiable** — it's the regression check for the R-1A
   fix in commit `191c029`. Use `mcp__laravel-boost__browser-logs` as
   a secondary source if the console is hard to read live.
8. **ProviderFilter mobile.** Admin with multiple providers on mobile
   week view. Expected: chips wrap; toggling chips filters the agenda
   (it shares `filteredBookings`).
9. **Today indicator.** Desktop week view: the honey-tinted
   current-time line still renders on today's column. Regression
   check for the R-1A hydration fix.
10. **Back button on mobile.** Agenda → Day view → browser back.
    Expected: returns to week view (agenda); URL is
    `?view=week&date=…`. No JS-driven view rewriting.

---

## Recording results

For each item, record in chat:

- `pass` — brief note confirming expected behavior.
- `fail` — exact reproduction steps + expected vs actual + a
  screenshot or console transcript.

When all 10 pass, update `docs/HANDOFF.md`'s "Open Questions /
Deferred Items" section: change the first bullet from
"R-8 manual QA" to
"R-8 manual QA complete, 10/10 pass, YYYY-MM-DD".
Keep the rest of HANDOFF as-is — it is still the R-8 handoff.

---

## If something fails

1. Check PLAN-R-8 §8 Risks. The documented mitigations cover most
   likely failure modes:
   - **Risk 8.2 (hidden grid leaves awkward empty area on mobile)** →
     add `flex-1` to the agenda's outer `<ol>`.
   - **Risks 8.3/8.4 (auto-scroll-to-7-AM useEffect fires on mobile
     date change)** → gate the effect behind
     `window.matchMedia('(min-width: 640px)').matches`.
   - **Risk 8.1 (header overflow at 320 px)** → add `text-xs sm:text-sm`
     on the switcher trigger + reduce `gap-2` to `gap-1.5`.
2. If the mitigation fixes it, apply the code change, then run:
   - `vendor/bin/pint --dirty --format agent`
   - `php artisan test --compact` (expect 468 passing, or higher if
     you added a test)
   - `npm run build`
   Re-test the failing item.
3. If the failure is NOT covered by §8, **stop and surface it to the
   developer**. Do not invent a mitigation on the spot. Do not
   silently scope-creep R-8.

---

## Non-negotiables

- **Do not start R-9 planning.** R-9 has its own dedicated session
  (see `docs/PROMPT-R9-PLAN.md`). Closing R-8 ends your work.
- **Do not modify files outside the narrow fix scope.** If a
  mitigation from §8 is needed, touch only the affected component.
  No refactoring, no cleanup.
- **Do not commit.** Leave the working tree dirty for the developer.
- **Do not reopen decisions D-001 through D-069.** They are locked.
- **Do not introduce browser-test infrastructure** (Pest Browser,
  Playwright). Same rule as the R-8 implementation session.

---

## Tooling

- **Browser (Chrome via Preview)** — primary tool for this session.
- `mcp__laravel-boost__browser-logs` — DevTools console as a
  programmatic read, helpful for QA #7.
- `mcp__laravel-boost__get-absolute-url` — if you need to resolve a
  path to a full URL.
- `vendor/bin/pint --dirty --format agent` — only if you applied a
  mitigation.
- `php artisan test --compact` — only if you applied a mitigation.

Do not start `npm run dev` or `composer run dev` — Herd serves
`riservo-app.test` and `npm run build` is fresh from the R-8
session.

---

## Stop condition

When all 10 items pass:

1. Update `docs/HANDOFF.md` "Open Questions" bullet as described
   above.
2. Post a short summary in chat (≤100 words):
   - 10/10 pass (or the specific failure + mitigation applied + re-test
     result).
   - Any visual observations worth flagging to the developer (e.g.,
     "agenda is quite long on busy weeks — might warrant a max-height
     in a future pass").
   - Confirm R-8 is closed.
3. Do NOT touch R-9. Do NOT commit.

If a failure appears and §8 does not cover it: stop at step 1, do
NOT update HANDOFF, and surface the finding. The developer will
decide whether to extend R-8, patch in a separate session, or accept
the defect.
