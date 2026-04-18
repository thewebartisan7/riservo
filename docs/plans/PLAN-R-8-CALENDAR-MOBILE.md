---
name: PLAN-R-8-CALENDAR-MOBILE
description: "R-8: calendar mobile improvements (agenda view, view switcher)"
type: plan
status: shipped
created: 2026-04-16
updated: 2026-04-16
---

# PLAN-R-8 — Calendar mobile improvements

**Session**: R-8 — Calendar bug fixes and mobile improvements
**Source**: `docs/reviews/ROADMAP-REVIEW.md` §R-8, `docs/reviews/REVIEW-1.md` issue #8
**Status**: proposed (plan-only)
**Date**: 2026-04-16
**Depends on**: R-1B (Provider rename — D-061; touched the same calendar files via `provider-filter.tsx` rename), R-7 (no direct dependency; different files)

---

## 1. Context

### 1.1 The findings

REVIEW-1 §#8 ("The calendar has a real DOM/hydration bug and weak mobile behavior") flagged three concrete problems:

1. **Nested `<li>` hydration warning** — `CurrentTimeIndicator` returns an `<li>`, and in week view it was wrapped inside another `<li>`, producing invalid HTML and a confirmed React hydration warning captured via Boost browser logs.
2. **No mobile view switcher** — `CalendarHeader` hides the day/week/month `Select` with `hidden md:block` (`calendar-header.tsx:159`), with no replacement under 768 px. Phone users cannot switch view.
3. **Booking items hidden in week view below `sm`** — Each event `<li>` in `WeekView` carries `hidden sm:flex` (`week-view.tsx:179`). Combined with the 7-column grid that collapses to 1 column under 640 px, week view on phones is an empty time grid with no escape (the switcher in #2 is also hidden).

### 1.2 Audit — what's still true today

This planning session ran a code audit before drafting. Findings:

| Finding | Status today | Evidence |
| --- | --- | --- |
| #1 nested `<li>` hydration | **Already fixed** in commit `191c029` ("Fix review R-1A done", 2026-04-16, +33/-33 lines across `calendar-event.tsx`, `day-view.tsx`, `month-view.tsx`, `week-view.tsx`, `current-time-indicator.tsx`). The current `WeekView` renders booking `<li>`s and `CurrentTimeIndicator` (which itself returns an `<li>`) as **siblings** inside the outer `<ol>` (`week-view.tsx:157-212`). No nesting. Both are valid `<ol>` children. | `git show 191c029 --stat -- resources/js/components/calendar/` |
| #2 mobile view switcher hidden | **Still present** | `calendar-header.tsx:159` — `<div className="hidden md:block">` |
| #3 week-view bookings hidden < `sm` | **Still present** | `week-view.tsx:179` — `className="relative mt-px hidden sm:flex"` |
| Adjacent: "New booking" button hidden < `md` | **Still present, NOT in REVIEW-1 scope** | `calendar-header.tsx:173` — `className="hidden md:inline-flex"` |
| Day view mobile | **OK** — `day-view.tsx:77-79` shows a mobile day strip; the time grid renders single-column on phones and is usable. The desktop sidebar mini-calendar is hidden under `md` by design. | `day-view.tsx:154` |
| Month view mobile | **OK** — `month-view.tsx:167-280` uses a compact dot-indicator grid on phones plus a "selected day's events" agenda list at the bottom. Functional. | `month-view.tsx:167` |

So the real R-8 work is **two findings**, not three. The third (the only "confirmed correctness" item) is already done. The remaining work is mobile UX.

### 1.3 Bundle decision — R-8 alone

The session brief offered bundling with R-9 (popup embed service prefilter + modal robustness). Applied the four bundling conditions from PROMPT:

| Condition | R-8 + R-9 | Verdict |
| --- | --- | --- |
| 1. Shared files or shared concepts | R-8 touches `resources/js/components/calendar/*.tsx` and `resources/js/pages/dashboard/calendar.tsx`. R-9 touches `public/embed.js`, `resources/js/pages/dashboard/settings/embed.tsx`, `resources/js/components/settings/embed-snippet.tsx`. **Zero file overlap, zero concept overlap.** Calendar UX is dashboard; embed is third-party widget. | FAIL → split |
| 2. No new architectural decision blocked behind a separate item | R-9 needs a new decision: **canonical service-prefilter contract** for the popup embed. The SPEC documents `?embed=1&service=taglio-capelli` (`SPEC.md:269-273`) but the iframe actually uses the path form `/{slug}/{service-slug}?embed=1` (`embed.tsx:40`) and `?service=` is never read by `PublicBookingController::show()` (`PublicBookingController.php:37-91`). R-9 must reconcile, then teach `embed.js` the chosen shape. That decision deserves its own session for review attention. R-8 only needs a smaller, mobile-rendering decision. | FAIL → split |
| 3. Combined diff reviewable in one sitting | R-8 estimate: 2 component files modified (`week-view.tsx`, `calendar-header.tsx`) + manual QA. R-9 estimate: full rewrite of `embed.js` (~150 lines), `embed.tsx` updates, `EmbedController` updates, possible route change, plus tests. Combined: ~10 files modified, ~6-8 tests. Right at the borderline. | NEUTRAL |
| 4. Implementation order is unambiguous | R-8 and R-9 are independent — they could ship in any order. Nothing forces interleaving. | NEUTRAL |

Conditions 1 and 2 fail; 3 and 4 are neutral. **Default per PROMPT is to split.** R-8 ships alone this session; R-9 gets its own planning session that can dedicate attention to the prefilter-contract decision.

### 1.4 D-058 and the mobile dilemma

`D-058` (DECISIONS-FRONTEND-UI) sets the calendar default view to **week**. A phone user landing on `/dashboard/calendar` (no `?view=` query) lands on week view. With finding #2 + #3 in their current state, that landing is hostile: an empty time grid with no way to navigate to day or month view. This is the headline UX bug R-8 must close.

Two design directions for the mobile-week-view fallback were considered (decision matrix in §3.2):

- **Agenda-list fallback** — replace the time grid with a vertically scrollable list of bookings grouped by day, similar to the existing month-view mobile pattern.
- **Auto-redirect to day view** — JavaScript redirect when `view=week` and viewport `< sm`.

Plan picks the agenda-list fallback (see §3.2 for rationale).

### 1.5 No browser test infrastructure

Confirmed during audit: the project has no Pest browser-test setup (`grep -r "Pest\\Browser\|visit(" tests/` returns zero hits outside `node_modules`). Calendar UI behaviour cannot be verified by automated tests in this session. R-8's automated coverage is limited to the existing backend Inertia-prop contract tests (which are unchanged by R-8 — the page props don't change). The bulk of verification is **manual QA** (§6).

This is a deliberate scope decision: adding browser-test infra inside R-8 would itself be a sizeable item (Pest Browser plugin install, Playwright/Pest setup, base-page fixtures, CI considerations). Flagged in the open-questions list for the developer.

---

## 2. Goal and scope

### Goal

Make the dashboard calendar usable on phones. After R-8, a phone user can land on the default week view, see today's bookings rendered as a readable agenda list, and switch to day or month view from any viewport width. Document the mobile-rendering policy as **D-069** so the pattern is clear for future calendar work.

### In scope

- **`week-view.tsx`** — below `sm` (640 px), replace the time grid with an agenda list of the week's bookings grouped by day. The day strip at the top remains. The time grid remains for `sm+`.
- **`calendar-header.tsx`** — make the view-switcher `Select` always visible (drop the `hidden md:block` wrapper). Use a compact width on mobile.
- **Decision D-069** in `docs/decisions/DECISIONS-FRONTEND-UI.md` — the mobile-rendering policy: agenda fallback for week, switcher always visible. Document why this preserves D-058 (week is still the default URL view).
- **Documentation of finding #1 fix** — note in HANDOFF + decision text that R-8 inherits the already-shipped hydration fix; the remaining work is mobile UX only. (No code change here — the audit confirmed the fix is in place.)
- **Tests** — one backend Inertia-prop contract assertion that the calendar page still renders for an authenticated admin; no other automated tests are addable without browser infra. See §5.
- **Audit day view + month view** for mobile parity — this is a read-only verification per REVIEW-1's directive ("The day view and month view should be verified for similar mobile issues while the agent is in this area"). Confirmed during planning that both are fine. Documented for the record (§3.5). No code change.

### Out of scope

- **The "New booking" button on mobile** (`calendar-header.tsx:173`, currently `hidden md:inline-flex`). REVIEW-1 §#8 does not mention it. PROMPT explicitly warns "Don't expand scope aesthetically." Carry it into BACKLOG as "mobile primary CTA for calendar." Phone admins still create bookings via desktop or by calling `ManualBookingDialog` programmatically — no functional regression.
- **`ProviderFilter` mobile responsiveness** (`provider-filter.tsx`). It already uses `flex-wrap` and works on phones (the chips wrap onto multiple lines). No change.
- **Day view sidebar mini-calendar** on mobile (`day-view.tsx:154-156`, `hidden ... md:block`). Intentionally desktop-only per the existing layout; the mobile day strip serves the same purpose. No change.
- **Browser-test infrastructure** (Pest Browser, Playwright, etc.). Documented as a future decision; not introduced inside R-8.
- **Calendar URL state mobile-aware redirects** (e.g., auto-rewriting `view=week` to `view=day` on phones). Rejected in §3.2 — interferes with deep-link sharing and analytics.
- **Swipe gestures for week-view horizontal scrolling.** No gesture infra exists; introducing it for one screen is overkill.
- **Calendar-controller backend changes.** R-8 is purely a frontend responsive-rendering pass.
- **Calendar timezone-rendering or booking-data shape changes.** R-6 closed timezone work; R-8 inherits it.
- **Re-running browser-logs to "verify the hydration warning is gone."** Per session rules we do not start a dev server. The git diff that closed the issue (commit `191c029`) is the authoritative evidence.

---

## 3. Approach

### 3.1 D-069 (new) — Mobile calendar UI policy

**File**: `docs/decisions/DECISIONS-FRONTEND-UI.md` (calendar UI behaviour).

Proposed text:

> ### D-069 — Mobile calendar week view falls back to agenda list; view switcher is always visible
>
> - **Date**: 2026-04-16
> - **Status**: accepted
> - **Context**: Pre-R-8, the dashboard calendar's week view was effectively unusable on phones. Booking events carried `hidden sm:flex` (week-view.tsx:179), so the 7-column time grid (which collapses to 1 column under `sm` per CSS Grid auto-placement) rendered empty. The view switcher was wrapped in `hidden md:block` (calendar-header.tsx:159), so phone users could not switch to day or month view either. Combined with D-058 (default view is week), a phone admin landing on `/dashboard/calendar` saw an empty grid with no escape. A separate hydration bug (nested `<li>` between `WeekView` and `CurrentTimeIndicator`) was already closed by commit `191c029`; this decision covers only the remaining mobile-rendering policy.
> - **Decision**:
>   1. **Mobile week view (< 640 px) renders an agenda list.** The 7-day header strip already at the top of `WeekView` (week-view.tsx:67-91) remains. Below it, the time grid is replaced by a vertically scrollable list of the week's bookings, grouped by day with date dividers, mirroring the existing pattern in `MonthView` (month-view.tsx:234-280). Each booking renders as a tappable card showing service, customer, and start/end time, and opens `BookingDetailSheet` on click — same handler as desktop. The time grid itself (`<ol>` at week-view.tsx:157) becomes `hidden sm:grid`; the agenda list becomes `block sm:hidden`.
>   2. **The view switcher is always visible.** The `<Select>` in `CalendarHeader` (calendar-header.tsx:159-170) drops its `hidden md:block` wrapper. Width becomes `w-[110px] sm:w-[130px]` to keep the mobile header from overflowing.
>   3. **D-058 is preserved.** Week remains the default view across viewports. The phone simply renders week as agenda; the URL `?view=week` is unchanged. A user explicitly choosing week from the switcher on mobile sees the same agenda layout.
>   4. **Day view + month view are unchanged.** Both already have working mobile patterns (day view: mobile day strip + single-column time grid; month view: compact dot-indicator grid + selected-day agenda list). The audit confirmed no parallel issues.
>   5. **The "New booking" button on mobile is deferred.** It remains `hidden md:inline-flex` and is captured as a BACKLOG item ("mobile calendar primary CTA"). R-8 is a bug-fix-and-mobile-rendering session, not a CTA redesign.
> - **Consequences**:
>   - Phone users get a usable week view (agenda) and can navigate views from the always-visible switcher.
>   - The time-grid metaphor is preserved for tablet (`sm`) and up.
>   - URL semantics are stable — no JS-driven view rewriting based on viewport.
>   - The `BookingDetailSheet` mobile UX is exercised more often (sheets are mobile-friendly by COSS default; no change needed).
>   - Future calendar work that touches week-view rendering must respect the agenda-vs-grid split at `sm`.
> - **Rejected alternatives**:
>   - *Auto-redirect mobile from `view=week` to `view=day`.* Would override the URL parameter, breaking deep-link sharing, analytics, and back-button semantics. Worse for the user who explicitly picked week.
>   - *Compact event cards in the existing 7-column grid below `sm`.* 7 columns at 320 px = ~45 px wide each. Cannot fit any meaningful event metadata. Visually cramped.
>   - *Swipe-driven horizontal week view (Google Calendar mobile).* Requires gesture infra (e.g., `react-use-gesture` or hand-rolled pointer events) that does not exist in this project. Out of scope for one screen.
>   - *Hide week view entirely on mobile.* Removing a documented view based on viewport breaks D-058 and produces inconsistent navigation.
>   - *Render the time grid in a horizontally-scrollable container on mobile.* Forces horizontal scroll, which is hostile UX for a calendar that should be glanceable. Also conflicts with the vertical scroll already needed for 24 hours.

### 3.2 Mobile week view — decision matrix

| Approach | Pros | Cons | Verdict |
| --- | --- | --- | --- |
| **Agenda list grouped by day** (chosen) | Information-dense; readable on phones; matches the existing month-view mobile pattern; no new gesture infra; preserves URL semantics. | Loses the "spatial week" metaphor on phones — but the metaphor is already broken at 7-cols-in-320px. | **CHOSEN.** |
| Auto-redirect `view=week` → `view=day` on `< sm` | Trivially small code change. | Overrides URL; breaks deep-link sharing and back button; surprising if the user explicitly picked week. | Rejected. |
| Compact event cards in 7-col grid | Keeps the spatial metaphor. | 45 px columns are unreadable; no metadata fits; positions overlap. | Rejected. |
| Horizontal swipe (1 day per swipe) | Best mobile metaphor (Google Calendar pattern). | No gesture infra; days of work to add it well; collides with vertical time scroll. | Rejected (scope). |
| Drop week view on mobile entirely | Simple. | Breaks D-058; inconsistent URL behaviour; user can pick week from switcher anyway and see nothing. | Rejected. |

### 3.3 Mobile view switcher — design

The existing `<Select>` (Base UI under COSS UI per D-032/D-034) is responsive by construction — the `SelectPopup` renders a popover on desktop and works fine on mobile (Base UI handles touch). The only change needed is removing the `hidden md:block` wrapper and constraining the trigger width.

Code shape for `calendar-header.tsx:159-170`:

```tsx
// Before
<div className="hidden md:block">
    <Select value={view} onValueChange={changeView}>
        <SelectTrigger className="w-[130px]">
            <SelectValue />
        </SelectTrigger>
        <SelectPopup>
            <SelectItem value="day">{t('Day view')}</SelectItem>
            <SelectItem value="week">{t('Week view')}</SelectItem>
            <SelectItem value="month">{t('Month view')}</SelectItem>
        </SelectPopup>
    </Select>
</div>

// After
<Select value={view} onValueChange={changeView}>
    <SelectTrigger className="w-[110px] sm:w-[130px]">
        <SelectValue />
    </SelectTrigger>
    <SelectPopup>
        <SelectItem value="day">{t('Day view')}</SelectItem>
        <SelectItem value="week">{t('Week view')}</SelectItem>
        <SelectItem value="month">{t('Month view')}</SelectItem>
    </SelectPopup>
</Select>
```

The header layout (`flex items-center justify-between gap-3 px-5 sm:px-7`) absorbs the extra control because the title `<div>` already has `min-w-0` + `truncate` and yields width to the action group. Verified by the existing mobile-only "Today" button (line 150-157) which currently sits in the same row without overflowing. Adding one more compact control is safe.

If overflow surfaces on a 320-px viewport during manual QA, the fallback is `text-xs sm:text-sm` on the trigger — a one-line tweak. Not pre-applied; only added if QA reveals a problem.

### 3.4 Mobile week view — agenda list shape

Replace the `<ol>` at `week-view.tsx:157-212` with a responsive split:

- **`sm` and up**: existing time-grid `<ol>` (unchanged).
- **Below `sm`**: a new `<ol>` rendering the week's bookings grouped by day.

Skeleton (final code in implementation):

```tsx
{/* Mobile agenda list (under sm) */}
<ol className="flex flex-col divide-y divide-border/60 px-5 pb-6 sm:hidden">
    {weekDays.map((day) => {
        const dayKey = format(day, 'yyyy-MM-dd');
        const dayBookings = bookingsByDay.get(dayKey) ?? [];
        return (
            <li key={dayKey} className="flex flex-col gap-2 py-4">
                <div className="flex items-center gap-2">
                    <p className="text-[10px] font-medium uppercase tracking-[0.18em] text-muted-foreground">
                        {format(day, 'EEEE, MMM d')}
                    </p>
                    {isToday(day) && (
                        <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider text-primary">
                            {t('Today')}
                        </span>
                    )}
                </div>
                {dayBookings.length > 0 ? (
                    <ul className="flex flex-col gap-1">
                        {dayBookings
                            .slice()
                            .sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime())
                            .map((booking) => {
                                const color = colorMap.get(booking.provider.id) ?? DEFAULT_COLOR;
                                const startTime = formatTimeShort(booking.starts_at, timezone);
                                const endTime = formatTimeShort(booking.ends_at, timezone);
                                return (
                                    <li key={booking.id}>
                                        <button
                                            type="button"
                                            onClick={() => onBookingClick(booking)}
                                            className="group flex w-full items-center gap-3 rounded-lg border border-border/60 bg-background px-3 py-2 text-left transition-colors hover:bg-accent/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        >
                                            <span aria-hidden="true" className={`size-2 shrink-0 rounded-full ${color.dot}`} />
                                            <div className="flex min-w-0 flex-1 flex-col">
                                                <p className="truncate text-sm font-semibold text-foreground">
                                                    {booking.service.name}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {booking.customer.name}
                                                </p>
                                            </div>
                                            <time
                                                dateTime={booking.starts_at}
                                                className="shrink-0 text-xs font-medium tabular-nums text-muted-foreground"
                                            >
                                                {startTime}–{endTime}
                                            </time>
                                        </button>
                                    </li>
                                );
                            })}
                    </ul>
                ) : (
                    <p className="text-xs text-muted-foreground">{t('No bookings')}</p>
                )}
            </li>
        );
    })}
</ol>

{/* Existing time grid — desktop and tablet */}
<div className="flex flex-auto sm:flex hidden">  {/* note: was always-shown; gains hidden sm:flex */}
    {/* ... existing hour lines + day dividers + time-grid <ol> ... */}
</div>
```

Implementation notes:

- The agenda list reuses `formatTimeShort` from `@/lib/datetime-format` (already imported in `month-view.tsx` for the same purpose). Same business-timezone rendering as the rest of the calendar.
- Colors come from the existing `colorMap` (per-provider palette) — same `DEFAULT_COLOR` fallback as the time grid.
- `BookingDetailSheet` opens on click — same `onBookingClick` callback already plumbed in.
- The `useEffect` that auto-scrolls the desktop time grid to 7 AM (`week-view.tsx:55-60`) becomes a no-op on mobile because the time grid is hidden — but it doesn't cause an error (the ref still attaches; `scrollHeight` of a hidden flex item is 0; `scrollTop = 0` is harmless). No conditional needed.
- The mobile day strip at the top (`week-view.tsx:67-91`, currently `sm:hidden`) is preserved unchanged. It gives the user week context above the agenda.
- **Tap-on-day to navigate to day view**: not in scope for R-8. The day strip on mobile is currently display-only in week view (unlike day-view which makes it tappable per `day-view.tsx:171-194`). Adding tap-to-day-view here would be a small extension; left out to keep R-8 surgical, and noted in §10.

### 3.5 Day view + month view audit

Per REVIEW-1's directive ("The day view and month view should be verified for similar mobile issues while the agent is in this area"), the planning audit confirms:

- **Day view** (`day-view.tsx`):
  - Mobile day strip at top with tappable days (lines 161-196) — works.
  - Single-column time grid renders bookings normally on phones (no `hidden sm:*` on event `<li>`) — works.
  - Sidebar mini-calendar `hidden ... md:block` (line 154) is intentionally desktop-only.
  - Verdict: no change.
- **Month view** (`month-view.tsx`):
  - Two parallel grids: desktop (`hidden ... lg:grid`, line 92-165) and mobile (`grid ... lg:hidden`, line 167-229).
  - Mobile grid uses tappable day cells with dot indicators per provider (lines 211-217); a "selected day's events" agenda list lives below (lines 234-280).
  - Verdict: no change. Already best-in-class for mobile.

These are documented in the plan only; no code touches `day-view.tsx` or `month-view.tsx`.

### 3.6 The fixed hydration bug — for the record

Commit `191c029` (R-1A, 2026-04-16) restructured the calendar components when `collaborator-filter.tsx` was renamed to `provider-filter.tsx`. Per the diff stat (5 files, +33/-33), the same commit shifted the `CurrentTimeIndicator` placement so it is now a sibling of booking `<li>`s under the outer `<ol>` rather than a child of one (`week-view.tsx:209-211`). The audit verified the current file structure is HTML-valid: both booking `<li>` and `CurrentTimeIndicator`-rendered `<li>` are direct children of the same `<ol>`.

R-8 inherits this fix and does not need to revisit it. The HANDOFF entry for R-8 will note: "Hydration warning was already fixed in commit 191c029 (R-1A); R-8 covered only the remaining mobile-UX work."

---

## 4. New decisions

### D-069 — full text proposed in §3.1

That is the only new decision. R-8 does not touch existing locked decisions D-001 through D-068.

---

## 5. Implementation order

Each step leaves the suite green. Today's baseline: **467 passing** (per HANDOFF after R-7).

### Step 1 — D-069 decision file (docs only)

Append D-069 to `docs/decisions/DECISIONS-FRONTEND-UI.md` using the text in §3.1. This is the design anchor.

**Verifies**: `php artisan test --compact` still 467 passing (no code touched).

### Step 2 — Mobile view switcher in `calendar-header.tsx`

Replace `calendar-header.tsx:159-170` per §3.3:
- Remove the `<div className="hidden md:block">` wrapper.
- Change `<SelectTrigger className="w-[130px]">` to `<SelectTrigger className="w-[110px] sm:w-[130px]">`.

No JSX structure changes beyond unwrapping. No prop changes. Existing handler `changeView` is untouched.

**Verifies**: `npm run build` (TypeScript still compiles); existing `CalendarControllerTest` backend tests still pass (467 still green — frontend-only change).

### Step 3 — Mobile week-view agenda list in `week-view.tsx`

Two surgical edits:

3a. Wrap the existing time grid (the entire `<div className="flex flex-auto">` at `week-view.tsx:123-214` containing the hour-label sticky column, the day-dividers grid, and the events `<ol>`) with a `hidden sm:flex` to hide it on phones.

3b. Insert the new mobile-agenda `<ol>` (per §3.4) above the time grid wrapper, with `sm:hidden` so it shows only on phones.

The `useEffect` at line 55-60 (auto-scroll the desktop grid to 7 AM) stays as-is — it's a no-op when the grid is hidden.

The mobile day strip at `week-view.tsx:67-91` stays unchanged.

Imports added at top of `week-view.tsx`:
- `useTrans` from `@/hooks/use-trans` — needed for `t('Today')`, `t('No bookings')`.
- `formatTimeShort` from `@/lib/datetime-format` — needed for the agenda time labels.

**Verifies**: `npm run build` clean; backend tests still 467 passing.

### Step 4 — Inertia-prop contract pin (one new test)

Add one test to `tests/Feature/Dashboard/CalendarControllerTest.php` to lock the props the redesigned `WeekView` consumes for the agenda fallback. The contract is unchanged from before R-8 — same `bookings`, `timezone`, `view`, `date`, `providers`, `services`, `isAdmin`. The test asserts the page renders and exposes `bookings` for an authenticated admin on default week view. This guards future refactors from breaking the prop shape the new agenda list reads.

```php
test('week view exposes bookings prop usable for the mobile agenda fallback', function () {
    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-16 09:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-16 10:00', 'Europe/Zurich')->utc(),
    ]);

    $this->actingAs($this->admin)
        ->get('/dashboard/calendar?view=week&date=2026-04-15')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/calendar')
            ->where('view', 'week')
            ->has('bookings', 1)
            ->where('bookings.0.id', $booking->id)
            ->has('bookings.0.starts_at')
            ->has('bookings.0.ends_at')
            ->has('bookings.0.service.name')
            ->has('bookings.0.customer.name')
            ->has('bookings.0.provider.id')
            ->has('timezone')
        );
});
```

This is the **only** new automated test. It's a prop-contract pin, not a UI test — but it is the strongest assertion the test infra supports, and it firewalls the agenda-list rendering against silent prop renames.

**Verifies**: new test passes; total 468 passing.

### Step 5 — Pint, full test run, build

- `vendor/bin/pint --dirty --format agent` — only the new test file should appear in the dirty list (no PHP changes elsewhere).
- `php artisan test --compact` — expected 468 passing (467 + 1).
- `npm run build` — expected clean build, no new TypeScript errors. The new mobile-agenda imports (`useTrans`, `formatTimeShort`) are existing module exports; tree-shaking unchanged.

### Step 6 — Manual QA (browser)

Run §6 manual QA checklist. Document any visual regressions before declaring the session complete.

### Step 7 — HANDOFF + roadmap update

- Overwrite `docs/HANDOFF.md` with the R-8 summary. Note the inherited hydration fix from `191c029`.
- `docs/reviews/ROADMAP-REVIEW.md` has no checkboxes for R-8; leave the prose section as-is.
- Move `docs/plans/PLAN-R-8-CALENDAR-MOBILE.md` to `docs/archive/plans/`.

---

## 6. Verification

### 6.1 Existing coverage audit

`tests/Feature/Dashboard/CalendarControllerTest.php` (235 lines) covers:
- Default view + date.
- Week-range booking filtering (in-range, out-of-range).
- View switching (day, week, month) preserved via query params.
- Provider filter prop shape.
- Admin vs collaborator prop scoping.

These tests don't touch the rendered HTML. Frontend-only changes (R-8 scope) do not invalidate them; they remain green throughout.

`tests/Feature/Settings/EmbedTest.php` and `tests/Feature/Booking/*` are unaffected.

### 6.2 New tests (1)

See §5 step 4. One new prop-contract pin in `CalendarControllerTest.php`.

### 6.3 What's NOT tested and why

- **The agenda list rendering itself.** No browser test infra (`Pest\Browser`, `visit()`) exists. Rendering is verified by manual QA at multiple viewport widths.
- **Tap-to-open `BookingDetailSheet` from agenda.** Same handler as the time grid; verified manually.
- **`<Select>` popup interaction on mobile.** Base UI handles touch; manual QA verifies on a real device or DevTools mobile emulator.
- **Hydration warning absence.** The fix is in place (commit 191c029). Re-running browser-logs would require starting a dev server, which the session done checklist avoids. Visual confirmation in DevTools console during manual QA is the proxy.
- **No regressions in day-view or month-view mobile.** Confirmed by audit (§3.5); no code change makes regression impossible-by-construction.
- **Cross-browser mobile rendering** (Safari iOS, Chrome Android). Manual QA on the developer's real device(s); CI does not currently run mobile-browser tests.

### 6.4 Test count

- Existing: 467 passing.
- +1 prop-contract pin in `CalendarControllerTest`.
- **Expected total: 468 passing.**

### 6.5 Manual QA (browser)

Run all of these before marking R-8 complete. Use Chrome DevTools device emulator (iPhone SE = 375 px, iPhone 14 Pro = 393 px, iPad Mini = 744 px) and at least one real phone if available.

1. **Default landing on mobile.** As admin, visit `/dashboard/calendar` from a phone-emulated viewport (375 px). **Expected**: page loads on week view; the day strip renders 7 days at top; below the strip, an agenda list shows the week's bookings grouped by day with date headers; days with no bookings show "No bookings"; today's row has a "Today" badge. The view switcher is visible in the header showing "Week view".
2. **Switch view on mobile.** Tap the view switcher → select "Day view". **Expected**: navigation to `?view=day&date=...`; day view renders correctly with the existing mobile day strip + single-column time grid. Switch back to week → agenda. Switch to month → existing mobile month grid + selected-day agenda. All three views are reachable from mobile.
3. **Tap a booking on mobile.** From the week-view agenda, tap a booking card. **Expected**: `BookingDetailSheet` opens (sheet is mobile-friendly per COSS UI default). Close, repeat from a different booking.
4. **Empty week on mobile.** Navigate to a week with no bookings (e.g., `?view=week&date=2030-01-01`). **Expected**: agenda renders 7 day rows, each with "No bookings".
5. **Tablet (640 px ≤ width < 1024 px).** Open emulator at 768 px. **Expected**: time grid is back (not the agenda); switcher is still visible at `w-[130px]`; events render in 7-column layout normally.
6. **Desktop (≥ 1024 px).** Open emulator at 1280 px. **Expected**: identical to pre-R-8 — no visible change.
7. **Hydration console check.** Open DevTools Console on the mobile-emulated week view. Refresh. **Expected**: no `<li> cannot be a descendant of <li>` warning, and no other React hydration warnings related to the calendar.
8. **`ProviderFilter` mobile.** As admin with multiple providers, open mobile week view with the filter visible. **Expected**: chips wrap onto multiple lines as needed; toggling chips filters the agenda list (because the agenda reads from the same `filteredBookings`).
9. **Today indicator.** On the desktop time grid, verify the current-time line still renders correctly (regression check for the R-1A hydration fix). It should be a thin honey-tinted horizontal line at the current time, only on today's column.
10. **Back-button on mobile.** From the agenda, switch to day view, then hit browser back. **Expected**: returns to week view (agenda); URL is `?view=week&date=...`. No JS-driven view rewriting.

### 6.6 What to watch for

- **Sticky header overlap.** The day strip is `sticky top-0` — make sure it doesn't get hidden behind a parent layout's sticky elements on mobile.
- **Scrolling inside iframe / embed mode.** Calendar is admin-only; it never renders inside `?embed=1`. No interaction.
- **TimeZone correctness.** The agenda uses `formatTimeShort(booking.starts_at, timezone)` — the same helper used everywhere else in the calendar. Manual QA #1 should glance at a known booking's stored UTC time vs the displayed business-tz time to spot any mismatch.

---

## 7. Files to create / modify / delete

### Created

- `docs/plans/PLAN-R-8-CALENDAR-MOBILE.md` — this file (moved to `docs/archive/plans/` on session completion).

### Modified

- `resources/js/components/calendar/week-view.tsx`
  - Add imports: `useTrans` from `@/hooks/use-trans`, `formatTimeShort` from `@/lib/datetime-format`.
  - Insert the mobile agenda `<ol>` block above the time grid wrapper.
  - Wrap the existing time-grid container with `hidden sm:flex` to hide it on phones.
- `resources/js/components/calendar/calendar-header.tsx`
  - Remove the `<div className="hidden md:block">` wrapper around the view switcher.
  - Change `<SelectTrigger className="w-[130px]">` → `<SelectTrigger className="w-[110px] sm:w-[130px]">`.
- `docs/decisions/DECISIONS-FRONTEND-UI.md`
  - Append D-069 with the full text from §3.1.
- `tests/Feature/Dashboard/CalendarControllerTest.php`
  - Add the prop-contract pin from §5 step 4.
- `docs/HANDOFF.md` — overwrite with R-8 summary (post-implementation).

### Deleted

None.

### Renamed

None.

---

## 8. Risks and mitigations

### 8.1 Mobile header overflow on 320-px viewports

**Scenario**: with the view switcher always visible plus the prev/today/next group + standalone Today button on mobile, a 320-px-wide viewport may overflow horizontally.

**Mitigation**: §3.3 sets the trigger to `w-[110px]` on mobile (already narrow). Title `<div>` has `min-w-0 truncate` and yields width to the action group. If overflow appears in QA at 320 px, the fallback is `text-xs sm:text-sm` on the trigger plus reducing the gap from `gap-2` to `gap-1.5`. Both are one-line tweaks that can ship inside R-8 without amending the plan.

### 8.2 Hidden time grid still consuming layout space

**Scenario**: applying `hidden sm:flex` to the time-grid container should remove it from the flow on mobile, but if the parent `<div>` (`isolate flex min-h-0 flex-1 flex-col overflow-auto bg-background`) has `flex-1` and the agenda list is short, the agenda might not fill the viewport — leaving an awkward empty area below.

**Mitigation**: the agenda `<ol>` itself has `flex flex-col` and lives inside the same parent flex container. Adding `flex-1` to the agenda's outer `<ol>` ensures it fills available space. If QA reveals an awkward gap, that's the fix.

### 8.3 Auto-scroll-to-7-AM `useEffect` errors when grid is hidden

**Scenario**: `week-view.tsx:55-60` runs `containerRef.current.scrollTop = hourHeight * 7` on every `date` change. If the ref is the outer container (which still mounts), `scrollHeight` of a container with a hidden flex child is computed normally — no error. But if the auto-scroll target was meant to be the time grid only, the ref might point to the wrong element.

**Mitigation**: re-read `week-view.tsx:39, 55-60` during implementation. The ref is `containerRef` attached to the outer `<div>` at line 63 (`ref={containerRef}`). On mobile, the outer container holds: (a) day strip, (b) hidden time grid, (c) visible agenda. Scrolling it to `hourHeight * 7` (which is `scrollHeight / 24 * 7`) lands at ~30% scroll. If the agenda is shorter than the viewport, this is harmless (capped to max scroll). If longer, it scrolls past 30% of the agenda — slightly weird but not broken. **If desired**, gate the effect behind `window.matchMedia('(min-width: 640px)').matches` to skip on mobile. Add the gate only if QA reveals a real annoyance.

### 8.4 The `useEffect` re-runs on `date` change and scrolls users away

**Scenario**: a mobile user navigating between weeks via prev/next sees the agenda jump-scroll on each navigation.

**Mitigation**: same as 8.3. Gate the effect behind a viewport check, or skip the scroll when the time grid is `hidden`. Decide during implementation based on observed behaviour.

### 8.5 `ProviderFilter` chips push the agenda below the fold

**Scenario**: an admin with 8 providers has the filter render across 2-3 wrapped lines on mobile, eating the visible viewport before the agenda starts.

**Mitigation**: out of scope. The filter chip behaviour predates R-8; if it becomes a problem post-R-8, it can be addressed independently (e.g., collapse to a "Filter" dropdown on mobile). Document as a BACKLOG item if QA #8 reveals this.

### 8.6 No automated coverage of the new mobile agenda

**Scenario**: a future refactor breaks the agenda rendering (e.g., changes the `bookings` prop shape) and no test catches it.

**Mitigation**: the prop-contract pin (§5 step 4) catches `bookings` shape regressions. The agenda's downstream rendering (sorting, formatting, click handler) is verified by manual QA. Adding browser-test infra is the larger fix; flagged as an open question for the developer post-R-8.

### 8.7 Visual regression on desktop

**Scenario**: the wrapping or hiding of the time grid accidentally changes desktop rendering (e.g., adds an extra `<div>` that affects flex sizing, or the unwrapped Select changes header layout).

**Mitigation**: manual QA #6 explicitly verifies desktop is identical. The diff is small enough to read in one pass — no `git stash`-able layout reshuffle.

### 8.8 D-069 over-locks the agenda design

**Scenario**: a future calendar redesign (post-MVP) wants to revisit the mobile pattern (e.g., add swipe gestures). D-069 codifies the agenda choice and could be cited as a reason to reject the redesign.

**Mitigation**: D-069's "Rejected alternatives" section explicitly notes that swipe-driven layouts were rejected on **scope** grounds (no gesture infra), not on **design** grounds. A future session that brings gesture infra can supersede D-069 with the standard decision-supersede pattern (D-NNN — Mobile calendar week view with horizontal swipe). The decision is meant as a clear floor, not a ceiling.

---

## 9. What happens after R-8

The remediation roadmap continues with **R-9** (popup embed service prefilter + modal robustness, Medium) and **R-10** (reminder DST + delayed-run resilience, Medium). Neither depends on R-8.

A separate **planning session** for R-9 must happen next if the developer chooses R-9 for the next implementation slot. R-9's non-trivial decision is the canonical service-prefilter contract (path-based vs query-based vs new shape) — that decision deserves dedicated review attention and is the reason R-8 was not bundled with R-9 (see §1.3).

Items captured in this plan as future work (not part of R-8):

- **Mobile "New booking" CTA on the calendar header.** Carry to BACKLOG. Likely a floating action button (FAB) anchored bottom-right, opening `ManualBookingDialog`. Not blocking; admins can create bookings on desktop or via direct URL today.
- **Tap-on-day in mobile week-view day-strip → switch to that day's day view.** Small extension; could ship in any future calendar polish session.
- **Browser-test infrastructure** (Pest Browser plugin, Playwright, etc.). Open question for the developer. Adds significant value across the whole frontend — not just calendar.
- **Auto-scroll behaviour on `date` change for mobile** (Risk 8.3, 8.4). Address inside R-8 only if observed during QA; otherwise leave for a future polish pass.

When the next R-NN plan is requested, the planning prompt should explicitly cite this plan's §1.3 for the bundle-decision reasoning so the next planner does not waste effort re-evaluating bundling with R-8.
