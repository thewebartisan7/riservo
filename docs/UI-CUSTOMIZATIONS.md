# UI customizations — what we modified vs. what stays upstream-clean

This doc tracks every place riservo diverges from out-of-the-box COSS UI / Tailwind defaults. **A future agent upgrading COSS UI reads this first.** Cross-reference against `git diff <last-known-coss-commit> resources/js/components/ui/` to know what's safe to overwrite vs. what needs a 3-way merge.

---

## Theme palette — `:root` and `.dark` reassigned

**File:** `resources/css/app.css` (lines ~84–171)

`--primary`, `--primary-foreground`, `--background`, `--foreground`, `--muted`, `--muted-foreground`, `--accent`, `--accent-foreground`, `--secondary-foreground`, `--border`, `--ring` are reassigned globally to a warm honey/paper/ink palette (oklch-based, slight chroma in the yellow-orange hue ~75°). This is the riservo brand decision — confirmed with the user during the Impeccable booking refactor. The dashboard, auth surfaces, and any future page automatically inherit it.

**What stays at the original COSS UI defaults:** `--card`, `--card-foreground`, `--popover`, `--popover-foreground`, `--sidebar-*`, `--input`, `--secondary`, `--destructive`, `--destructive-foreground`, `--chart-*`, `--code*`, `--warning*`, `--success*`, `--info*`. Repainting these is a deliberate design call and not part of the Impeccable scope.

**Two new vars:** `--honey-soft` (pale amber surface), `--rule-strong` (78% lightness solid border) — registered as `--color-honey-soft` and `--color-rule-strong` in the existing `@theme inline` block. Used by booking only (so far).

**On COSS UI upgrade:** the upstream `:root` / `.dark` blocks may add new tokens — pull them in. Don't let the upstream override the 11 reassigned values above.

---

## COSS UI primitive files — kept upstream-clean (mostly)

**Files:** `resources/js/components/ui/*.tsx`

Default policy: **do not modify**. Override at the callsite via `cn()` + `className`. Upstream upgrades stay a clean overwrite.

**Exception — tracked brand overrides.** Small, cosmetic, brand-wide edits to a primitive's default classes are acceptable when:
- the change is genuinely brand-wide (not surface-scoped — in which case use callsite `className`)
- the change is cosmetic (token swap, color adjustment) — not structural (new props, new composition, behavior shift)
- the divergence is logged in the "COSS primitives with brand overrides" section below
- the affected lines carry an inline `// riservo:` comment referencing this doc, so the next person diffing against upstream sees it

For structural changes, prefer wrapping the primitive in a riservo-owned component (see `<Display>` as the pattern).

If you find yourself repeating the same `className=…` across 3+ callsites for a brand-wide reason, that's the signal to promote it into a primitive override — not to extract a shared const.

---

## COSS primitives with brand overrides

Each entry documents exactly what was changed, why, and the minimal diff to re-apply on a COSS upgrade. Diff against the upstream file before overwriting — don't blow these away.

### `input.tsx` — invalid state uses `primary` (honey), not `destructive` (red)

**Why:** form validation is feedback, not alarm. The riservo brand keeps red reserved for genuinely destructive actions (delete, cancel, system error). Invalid-email-on-form is a gentle nudge, not a red flag — see `.impeccable.md` §"Calm".

**What changed:** on the wrapper `<span>` className in the main `Input` component, four `destructive` classes were swapped:

| Upstream COSS | Riservo |
|---|---|
| `has-aria-invalid:border-destructive/36` | `has-aria-invalid:border-primary` |
| `has-focus-visible:has-aria-invalid:border-destructive/64` | `has-focus-visible:has-aria-invalid:border-primary` |
| `has-focus-visible:has-aria-invalid:ring-destructive/16` | `has-focus-visible:has-aria-invalid:ring-ring/24` |
| `dark:has-aria-invalid:ring-destructive/24` | `dark:has-aria-invalid:ring-ring/24` |

The inline `// riservo:` comment above the classname pins this for reviewers.

**Not changed:** `<Button variant="destructive">`, destructive alerts/toasts, and confirmation dialogs keep `--destructive` and render red — those are real alarms and the gradient matters.

### `field.tsx` — `FieldError` text color uses `primary` (honey), not `destructive-foreground` (red)

**Why:** same reasoning as `input.tsx` above. A FieldError is always paired with an invalid Input; keeping the two in different colors would be dissonant. Form validation is gentle feedback, red is reserved for real alarms.

**What changed:** in the `FieldError` component:

| Upstream COSS | Riservo |
|---|---|
| `cn("text-destructive-foreground text-xs", className)` | `cn("text-primary text-xs", className)` |

The inline `// riservo:` comment above the `cn()` pins this for reviewers.

---

---

## Custom components in `components/booking/` that bypass primitives

These were intentionally kept custom — primitives don't fit cleanly. Each entry includes the rationale so a future "consolidate to primitives" pass knows what to leave alone.

### `date-time-picker.tsx` — custom calendar grid

Bypasses `resources/js/components/ui/calendar.tsx` (react-day-picker). Rationale: react-day-picker's day cell API doesn't cleanly express "opacity-driven availability state" + "today dot rendered underneath the selected highlight" + per-day disabled-by-availability-data semantics. Customizing through its `classNames` prop ends up fighting the library. The custom grid is ~120 LOC of vanilla JSX and renders correctly with `aria-pressed:` / `disabled:` / `enabled:hover:` / `not-aria-pressed:focus-visible:` Tailwind variants.

If react-day-picker grows native support for these patterns (custom day-cell render with full state control), revisit.

### `step-indicator.tsx` — custom breadcrumb + progress bar

Bypasses `resources/js/components/ui/progress.tsx`. Rationale: the visual is "01 / 05 · STEP NAME ← Back" with a 2px bar underneath — not a standalone progress indicator. The bar portion alone could swap to `<Progress>`, but the cohesive layout is small enough to keep self-contained. Lives entirely inside booking; never reused.

### `collaborator-picker.tsx` — large card-shaped tap targets

Raw `<button>` with internal grid layout (avatar, name, subtitle, arrow). Inner `<Avatar>` + `<AvatarFallback>` are COSS UI primitives; the wrapping button stays raw. Rationale: `<Card>` + `<CardPanel>` doesn't fit a clickable card pattern cleanly — Card is a presentational container, not an interactive surface. Wrapping it in a button would either require nested interactive elements or losing Card's `data-slot` semantics. The raw button keeps `aria-pressed:` / `focus-visible:` / `hover:` variants explicit and is ~10 LOC.

---

## Notable className overrides on primitives

These are valid, supported usage patterns (override via `cn()`), not modifications to the primitives themselves. Listed for awareness when reviewing booking visuals — they intentionally diverge from the variant's defaults.

### `booking-confirmation.tsx` — inverted "View booking details" pill

Uses `<Button variant="ghost" render={<a href={…}/>} className="booking-display h-12 bg-foreground text-background hover:bg-foreground hover:[filter:brightness(1.15)]">`. Inverts the foreground/background mapping — no built-in variant matches this dark-pill-on-paper combination. Renders as `<a>` via Button's `render` prop. Considered adding a `variant="inverted"` to `button.tsx`, declined to keep the primitive upstream-clean.

### `customer-form.tsx` — honey invalid state on Input + FieldError

The COSS UI `<Input>` wrapper applies `has-aria-invalid:border-destructive/36` (red) and `has-focus-visible:has-aria-invalid:border-destructive/64`; `FieldError` applies `text-destructive-foreground`. Booking uses honey for invalid state to match the surrounding accent. Overrides at the callsites:

- Input: `className="has-aria-invalid:border-primary has-focus-visible:has-aria-invalid:border-primary has-aria-invalid:ring-ring/24 has-focus-visible:has-aria-invalid:ring-ring/24"` — note the `has-` prefix is required because `aria-invalid` lives on the inner input but the visual border lives on the wrapper span.
- FieldError: `className="text-primary"` — overrides the default red text to match the border.

### `<Display>` component — Bricolage Grotesque typography primitive

**File:** `resources/js/components/ui/display.tsx`

Riservo-specific primitive that bundles Bricolage Grotesque (`--font-display` / `font-display` utility) with stylistic set 01 and -0.02em tracking. Uses Base UI's `useRender` hook so the rendered element is controlled via a `render` prop — same pattern as `<Button>` and `<Card>`. Replaces the prior `.booking-display` CSS class.

Usage:
```tsx
<Display render={<h1 />} className="text-2xl font-semibold leading-tight">
    {businessName}
</Display>
<Display>riservo</Display>  {/* defaults to span */}
<Button><Display>{t('Confirm booking')} →</Display></Button>  {/* inside Button children */}
```

Why it lives in `components/ui/` but is not upstream COSS UI: COSS UI doesn't ship a display-type primitive. We keep it next to the other primitives because the import path is ergonomic (`@/components/ui/display`). The file is **ours** — a future COSS UI upgrade won't touch it and we don't fork any COSS file to support it.

For `<AvatarFallback>` (where we can't wrap children in Display because Base UI controls the rendered element), apply `className="font-display"` directly — the stylistic set + tight tracking are invisible on 2-character initials.

---

## Animation utilities — defined in `@theme inline`

**File:** `resources/css/app.css` (lines ~15–20)

Three reusable animation utilities live in the `@theme inline` block and are available globally as Tailwind classes:

- `animate-rise` — fades in + translates Y by 6px over 220ms. Used in `booking-layout.tsx` for the step content wrapper. Reusable for any "newly mounted block" entrance (onboarding screens, dialog panels, etc.).
- `animate-confirm-circle` — animates an SVG circle's `stroke-dashoffset` from 200 to 0 over 900ms with 120ms delay. Pair with a circle that has its own `stroke-dasharray` attribute (e.g. `"3 4"` for the dotted look).
- `animate-confirm-check` — animates an SVG path's `stroke-dashoffset` from 60 to 0 over 500ms with 700ms delay. The path must declare `strokeDasharray="60"` for the animation to be visible.

All three use `animation-fill-mode: both` so the `from {}` state is applied during the delay, eliminating the "flash before delay" problem.

The `prefers-reduced-motion: reduce` media query suppresses all four animation classes (including `animate-skeleton`) and forces SVG `stroke-dashoffset: 0` so the final shape stays visible.

`.booking-skeleton` (with custom shimmer keyframes) was retired in favor of the COSS UI `<Skeleton>` primitive, which uses the existing `animate-skeleton` utility.

---

## On COSS UI upgrade — quick checklist

1. Pull the latest `resources/js/components/ui/*.tsx` from the upstream registry. Overwrite directly — **except** for any file listed under "COSS primitives with brand overrides" above (currently: `input.tsx`, `field.tsx`). For those, 3-way merge: keep the riservo override lines, pull in any unrelated upstream changes.
2. Diff `resources/css/app.css` lines 1–185 (everything except the booking section) against the upstream template. Reapply our 11 token reassignments listed above + 2 new vars. Anything else upstream changed: pull in.
3. Walk the booking flow end-to-end at `http://localhost:8002/salone-bellissima` — service → confirmation. Confirm visual parity.
4. Walk the dashboard. Confirm the rebrand still applies (buttons honey, backgrounds paper).
5. Toggle `.dark` on `<html>` — both surfaces should flip palette.
6. `npm run build` — zero TypeScript errors, zero Tailwind warnings.

If any custom component above gains an upstream primitive equivalent that wasn't available before, evaluate consolidation in a separate PR.
