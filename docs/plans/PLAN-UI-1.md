---
name: PLAN-UI-1
description: "UI consolidation: booking flow to COSS UI primitives"
type: plan
status: shipped
created: 2026-04-15
updated: 2026-04-15
---

# UI consolidation plan — booking flow → COSS UI primitives

## Context

Impeccable produced a new visual for the public booking flow. Two passes have already shipped (uncommitted on `main`):

**Pass 1 (implementation cleanup)** — palette rebranded app-wide via `:root` / `.dark` overrides in `resources/css/app.css` (honey/paper/ink replaces the prior neutral palette; `--primary`, `--background`, `--foreground`, `--muted`, `--accent`, `--secondary-foreground`, `--border`, `--ring` all point to honey/paper/ink values). Two new vars added: `--honey-soft`, `--rule-strong`. All inline `style={{}}` props on booking components removed; mouse/focus handlers replaced with Tailwind `hover:` / `focus-visible:` / `enabled:` / `disabled:` / `aria-pressed:` / `aria-invalid:` variants. Two JSX `<style>` blocks (SVG check animation, skeleton shimmer) moved into `app.css`. `.booking-numerals` removed in favor of `tabular-nums` utility. Sizes promoted to named tokens where exact (`text-[24px]` → `text-2xl`, `text-[14px]` → `text-sm`, `text-[12px]` → `text-xs`).

**Pass 2 (logic review)** — extracted 10 duplicated format helpers to `resources/js/lib/booking-format.ts`. Replaced `.replace('{x}', value)` interpolation with `useTrans`'s built-in `t(key, { x: value })` `:placeholder` convention.

End-to-end verified at `http://localhost:8002/salone-bellissima` via the Chrome preview MCP (full flow service → confirmation; computed styles match plan; dashboard surfaces inherit the new palette as expected).

## What was deferred — and what this plan addresses

The booking components still bypass COSS UI primitives and still carry many half-pixel arbitrary sizes:

- **Raw `<button>`** instead of `<Button>` (8 callsites across 7 files)
- **Raw `<input>` / `<textarea>` + custom local `Field`** in `customer-form.tsx` instead of the COSS UI `<Field>` / `<FieldLabel>` / `<FieldError>` / `<Input>` / `<Textarea>` primitives
- **Raw `<img>` + custom initials divs** instead of `<Avatar>` / `<AvatarImage>` / `<AvatarFallback>` (collaborator-picker, booking-layout)
- **Custom skeleton divs** instead of `<Skeleton>` (collaborator-picker, date-time-picker)
- **Custom card containers** in booking-summary and the date-time-picker calendar shell (could be `<Card>` / `<CardPanel>`)
- **Arbitrary text sizes** `text-[10.5px]` `text-[11px]` `text-[11.5px]` `text-[12.5px]` `text-[13px]` `text-[13.5px]` `text-[14.5px]` `text-[15px]` `text-[15.5px]` `text-[17px]` `text-[18px]` `text-[22px]` `text-[44px]` — none promoted because none match the default scale exactly. **Decision (this plan): collapse to nearest named token, accept 0.5–2px drift.**
- **Arbitrary `leading-[0.95|1.05|1.5|1.55]` and `tracking-[0.12em|0.14em]`** — same treatment.
- **`.booking-root` / `.booking-display` / `@keyframes` blocks** still live as free-floating CSS in `app.css`. Tailwind v4 supports `--animate-*` and `--font-*` aliases inside `@theme` — those would generate `animate-booking-rise`, `font-booking-display` etc. as proper utilities.

User goal: brand consistency across riservo (booking + dashboard), follow Tailwind + COSS UI conventions, keep COSS UI upgrade-friendly.

## Strategy

**Don't fork `resources/js/components/ui/`.** COSS UI is copy-paste — every modification creates a 3-way-merge burden on every upstream sync. Use primitives unmodified, override via `className` + `cn()` only when small adjustments are needed.

**Where adjustments are needed:** prefer `<Button variant="default">` / `variant="ghost"` / `variant="link"` + `className="..."` over rolling raw HTML. The variant supplies the base contract (cursor, transition, focus ring, disabled handling, `data-pressed`, `data-loading`); your `className` layers font / sizing / brand color tweaks via `cn()`'s tailwind-merge so conflicts resolve cleanly.

**Accept 0.5–2px visual drift** on font sizes, button heights, label styling, and tracking. The user has explicitly accepted "doesn't match perfectly the impeccable design applied". The honey palette + the `.booking-display` typeface carry enough brand signal that `text-sm` instead of `text-[13.5px]` will still feel cohesive.

**Genuinely irreducible custom UI** (the date-time-picker calendar grid, the step-indicator progress + breadcrumb) — keep custom but log them in `docs/UI-CUSTOMIZATIONS.md` with a one-line rationale.

## Phase 1 — Replace primitives

### Buttons

The `Button` component (`resources/js/components/ui/button.tsx`) supports `variant`: default, destructive, destructive-outline, ghost, link, outline, secondary; and `size`: xs, sm, default, lg, xl, icon-{xs,sm,default,lg,xl}. Default is `bg-primary text-primary-foreground` — since the rebrand, that's already honey/honey-ink. So `<Button variant="default">` renders the right colors automatically.

| Callsite | Current | Target |
|---|---|---|
| `booking-summary.tsx` Confirm booking | raw button h-12 honey | `<Button variant="default" size="xl" className="booking-display h-12 text-[14.5px] tracking-tight" loading={http.processing} disabled={http.processing}>` |
| `customer-form.tsx` Continue to review | raw button h-12 honey | same as above |
| `booking-confirmation.tsx` View booking details | raw `<a>` h-12 inverted (bg-foreground text-background) | `<Button variant="ghost" render={<a href={show.url(token)} />} className="booking-display h-12 bg-foreground text-background hover:bg-foreground hover:[filter:brightness(1.15)]">` — uses Button's `render` prop to render as anchor while keeping styling. **Log in `UI-CUSTOMIZATIONS.md`** — inverted color combo doesn't match any built-in variant. |
| `booking-confirmation.tsx` Book another appointment | raw button h-11 ghost | `<Button variant="ghost" size="lg" className="booking-display h-11 text-[13.5px]" onClick={onBookAnother}>` |
| `date-time-picker.tsx` Previous/Next month | raw 32×32 ghost | `<Button variant="ghost" size="icon-sm" disabled={...}>` |
| `date-time-picker.tsx` "Try next →" | raw button | `<Button variant="link" className="booking-display font-semibold text-primary">` |
| `date-time-picker.tsx` slot time buttons | raw h-10 outline | `<Button variant="outline" size="sm" className="h-10 tabular-nums hover:border-primary hover:bg-honey-soft hover:text-primary-foreground">` |
| `step-indicator.tsx` Back link | raw button | `<Button variant="link" size="xs" className="text-muted-foreground hover:text-foreground">` |
| `collaborator-picker.tsx` collaborator card | raw button — large card-shaped tap target | **Keep custom** (it's a card+row pattern, not a button-shaped button). Document. Or: wrap in a `<Card>` with `<CardPanel>` and an internal click handler. |

Note on the Button `loading` prop: the Confirm button currently shows `t('Sending…')` text when processing. The Button primitive supports a `loading` prop that hides text + shows a Spinner. Switch to `loading={http.processing}` and let the children stay as the static label — cleaner. If a custom loading label is desired, keep the children-swap and pass `disabled` only.

### Inputs / Fields / Textarea

Per `resources/js/components/ui/CLAUDE.md`: **always use `Field` / `FieldLabel` / `FieldError` for form fields. Never build manually.**

In `customer-form.tsx`:
- Delete the local `Field` helper component entirely.
- Delete `fieldInputClass`.
- For each input:
  ```tsx
  <Field>
      <FieldLabel>{t('Email')}</FieldLabel>
      <Input
          type="email"
          name="email"
          autoComplete="email"
          placeholder="name@example.com"
          value={data.email}
          onChange={(e) => setData({ ...data, email: e.target.value })}
          aria-invalid={!!errors.email}
      />
      {errors.email && <FieldError match>{errors.email}</FieldError>}
  </Field>
  ```
- For the notes textarea:
  ```tsx
  <Field>
      <FieldLabel>
          {t('Notes')}{' '}
          <span className="font-normal text-muted-foreground">({t('optional')})</span>
      </FieldLabel>
      <Textarea
          rows={3}
          value={data.notes}
          onChange={(e) => setData({ ...data, notes: e.target.value })}
          placeholder={t('Anything we should know? Allergies, preferences…')}
      />
  </Field>
  ```

**Visual change accepted**: `FieldLabel` is `font-medium text-base/4.5 sm:text-sm/4` — not uppercase wide-tracked. Lose the booking-specific label aesthetic in exchange for site-wide form consistency. The Input is `h-8.5 sm:h-7.5` (~30–34px) — smaller than the current h-11 (44px). If the smaller density bothers you visually, pass `className="h-11 text-[14.5px]"` to lift it back. **Decision: try without override first; add only if it feels too dense.**

The Input primitive already provides: `aria-invalid:` border-destructive (red) — but with the rebrand, `--destructive` is still red (we kept it). For booking-specific "use primary for invalid" behaviour, override via `className="aria-invalid:border-primary"`.

### Avatars

Replace raw `<img>` and custom initials divs with the `Avatar` family in `resources/js/components/ui/avatar.tsx`.

In `collaborator-picker.tsx`:
```tsx
<Avatar className="h-11 w-11 shrink-0">
    {collaborator?.avatar_url && (
        <AvatarImage src={collaborator.avatar_url} alt={collaborator.name} />
    )}
    <AvatarFallback className="booking-display text-sm font-semibold">
        {collaborator ? getInitials(collaborator.name) : ''}
    </AvatarFallback>
</Avatar>
```

For "Any specialist" (the lucide `Users` icon in a circle), `<Avatar>` accepts arbitrary children — render the icon as fallback-only:
```tsx
<Avatar className="h-11 w-11 shrink-0">
    <AvatarFallback><Users className="h-4 w-4" aria-hidden /></AvatarFallback>
</Avatar>
```

Same treatment in `booking-layout.tsx` for the business identity avatar (h-12 w-12). The image keeps the `ring-1 ring-border` look automatically because Avatar's structure already centers the image.

### Skeleton

Replace the custom skeleton divs in `collaborator-picker.tsx` and `date-time-picker.tsx` with `<Skeleton>` from `resources/js/components/ui/skeleton.tsx`. The shimmer animation on the existing booking-skeleton class isn't needed — `<Skeleton>` has its own animation (`var(--animate-skeleton)` in `@theme`). Delete `.booking-skeleton` and `@keyframes booking-shimmer` from `app.css` afterwards.

### Cards / containers

In `booking-summary.tsx`, the receipt block (`<dl>` + rows) and the customer summary block can become `<Card>` / `<CardPanel>` for consistency with dashboard cards. Accept the slight padding/border-radius shift.

In `date-time-picker.tsx`, the calendar shell `<div className="rounded-xl border border-border bg-background p-4 sm:p-5">` → `<Card><CardPanel className="p-4 sm:p-5">`.

In `service-list.tsx`, the `<ul>` rows currently have a manual border between items. Keep custom — `<Card>` + multiple list items is awkward to express via primitives. Document if you want.

### Step indicator

The step indicator is a custom UX (numbered breadcrumb + progress bar + back link). The progress bar portion *could* use `<Progress>` from `resources/js/components/ui/progress.tsx`, but the rest is bespoke. **Keep entire component custom; document.**

### Calendar

The custom date-time-picker calendar grid renders the honey selected day, today dot, opacity-by-availability. The shipped `<Calendar>` (`resources/js/components/ui/calendar.tsx`, react-day-picker based) is tightly bound to the dashboard's `--primary` / `--accent` token semantics and exposes its own classNames API. **Keep entirely custom; document the divergence (rationale: react-day-picker doesn't cleanly support our "opacity = no availability" semantic and the per-day "today dot underneath the selected highlight" overlay).**

## Phase 2 — Token alignment

### Font sizes

| Current | Replace with | Drift |
|---|---|---|
| `text-[10.5px]` | `text-xs` (12px) | +1.5px |
| `text-[11px]` | `text-xs` | +1px |
| `text-[11.5px]` | `text-xs` | +0.5px |
| `text-[12.5px]` | `text-xs` | -0.5px |
| `text-[13px]` | `text-sm` (14px) | +1px |
| `text-[13.5px]` | `text-sm` | +0.5px |
| `text-[14.5px]` | `text-sm` | -0.5px |
| `text-[15px]` | `text-sm` (or `text-base` 16px — pick by context) | ±1px |
| `text-[15.5px]` | `text-base` | +0.5px |
| `text-[17px]` | `text-lg` (18px) | +1px |
| `text-[18px]` | `text-lg` | exact |
| `text-[22px]` | `text-xl` (20px) or `text-2xl` (24px) — pick by hierarchy weight | ±2px |
| `text-[44px]` | `text-5xl` (48px) | +4px (or stay arbitrary if 44 is sacred for the date hero) |

When two named tokens are equidistant, prefer the larger (more readable). Update the `text-[clamp(...)]` in `booking-layout.tsx` if the smaller end (`1.75rem` = 28px) crosses a boundary — but `clamp(1.75rem, 1.3rem+1.6vw, 2.5rem)` doesn't have a tailwind named equivalent; **keep as arbitrary**.

### Line heights

| Current | Replace with | Notes |
|---|---|---|
| `leading-[0.95]` | `leading-none` (1) | for the giant 44px date numeral — `1` is fine |
| `leading-[1.05]` | `leading-tight` (1.25) | display headline; some shift, acceptable |
| `leading-[1.5]` | `leading-normal` (1.5) | exact |
| `leading-[1.55]` | `leading-relaxed` (1.625) — or stay normal | `normal` is closer (-0.05 vs +0.075) |

### Letter-spacing

| Current | Replace with | Notes |
|---|---|---|
| `tracking-[0.12em]` | `tracking-widest` (0.1em) | -0.02em — subtle |
| `tracking-[0.14em]` | `tracking-widest` | -0.04em — visible but accept |

### Other

- `max-w-[40ch]`, `max-w-[36ch]`, `max-w-[480px]` — Tailwind has no `ch`-based widths or arbitrary px in defaults. **Keep arbitrary**.
- `h-[72px]` (skeleton), `max-w-[44px]` (calendar day cell), `h-[2px]` (progress bar) — no equivalent. **Keep arbitrary**.
- `gap-y-1`, `gap-1`, `gap-2`, etc. — already named. No change.

## Phase 3 — Tailwind v4 @theme polish

Move from free-floating CSS classes to `@theme inline` aliases so the booking visuals are first-class utilities.

### Font family

In `@theme inline`:
```css
--font-booking-display: 'Bricolage Grotesque Variable', ui-sans-serif, system-ui, sans-serif;
--font-booking-body: 'Hanken Grotesk Variable', ui-sans-serif, system-ui, sans-serif;
```

This generates `font-booking-display` and `font-booking-body` utilities. Then:
- `.booking-root { font-family: var(--booking-font-body); ... }` → can stay (still need `font-feature-settings`, `letter-spacing`, `font-optical-sizing`)
- `.booking-display { font-family: var(--booking-font-display); ... }` → can stay (same reason — feature-settings + letter-spacing + optical-sizing are bundled)

So the `@theme` aliases enable utility generation but don't replace the classes. Net win: optional utility access for ad-hoc usage.

### Animations

In `@theme inline`:
```css
--animate-booking-rise: booking-rise 220ms cubic-bezier(0.2, 0.8, 0.2, 1) both;
--animate-booking-confirm-circle: booking-confirm-circle 900ms cubic-bezier(0.2, 0.8, 0.2, 1) 120ms forwards;
--animate-booking-confirm-check: booking-confirm-check 500ms cubic-bezier(0.2, 0.8, 0.2, 1) 700ms forwards;
```

Then `.booking-step` becomes `<div className="animate-booking-rise max-w-[480px]">`. The two SVG animation classes (`.booking-confirm-circle`, `.booking-confirm-check`) become utilities applied directly on the SVG elements. The `@keyframes` blocks themselves stay in the file (they're standard CSS, not theme tokens).

The `prefers-reduced-motion` block in `app.css` still needs to suppress the animations — can target the utility-applied elements via `[class*="animate-booking-"] { animation: none !important; }` or keep the existing class-based selectors.

**Suggestion, not requirement**: agent judgement on whether the indirection is worth it. If `animate-booking-rise` will only ever apply to one element, the current `.booking-step` class is fine.

### Skeleton

If you replace custom skeletons with `<Skeleton>` (Phase 1), `--animate-skeleton` already exists in `@theme`. Remove `--animate-booking-shimmer` and `.booking-skeleton` after.

## Phase 4 — Documentation (long-term)

Create `docs/UI-CUSTOMIZATIONS.md` with sections:

1. **Scoped overrides via className** — list of booking callsites that pass non-trivial `className` to a primitive (e.g. inverted "View booking details" button). These survive COSS UI upgrades automatically; listed for awareness.
2. **Custom components (no primitive)** — date-time-picker (calendar), step-indicator (progress + breadcrumb), collaborator-picker card-button (if not refactored to Card). One-line rationale each.
3. **Theme token reassignments** — note that `:root` and `.dark` redefine the project palette to honey/paper/ink. Explain the architectural decision so future devs don't think it's a bug.
4. **What to check when upgrading COSS UI** — diff `resources/js/components/ui/` against the upstream registry; we don't modify those files, so a clean overwrite is safe.

## Phase 5 — Future consideration (note only, no work)

The format helpers in `resources/js/lib/booking-format.ts` (`formatPrice`, `formatDurationFull`, `formatDateLong`, etc.) all run client-side via `Intl.DateTimeFormat` with the user's browser locale. For multi-tenant SaaS where each business has its own preferred locale, currency, date format, and time zone, these would be more correct **server-side** — formatted in Laravel via Carbon, the configured Money formatter, and the per-business locale, then sent as already-formatted strings in the Inertia props.

When the i18n / multi-language pass lands (per `docs/SPEC.md` and the `:placeholder` translation convention), revisit and move formatting to the controllers. For now, client-side is fine and consistent with existing dashboard pages.

## Files to touch

- `resources/css/app.css` — `@theme` font + animation aliases (Phase 3)
- `resources/js/components/booking/booking-summary.tsx` — Button + Card
- `resources/js/components/booking/booking-confirmation.tsx` — Button (with `render` prop for the anchor variant)
- `resources/js/components/booking/customer-form.tsx` — Field + FieldLabel + FieldError + Input + Textarea + Button (delete local Field helper)
- `resources/js/components/booking/collaborator-picker.tsx` — Avatar + Skeleton; the row-button stays raw (or wraps in Card)
- `resources/js/components/booking/date-time-picker.tsx` — Button (icon for nav, outline for slots, link for "Try next"), Card (calendar shell), Skeleton (loading slots)
- `resources/js/components/booking/service-list.tsx` — token alignment only (no primitive swap; `<ul>`+`<button>` rows are fine)
- `resources/js/components/booking/step-indicator.tsx` — Button (variant="link") for back; keep progress bar custom
- `resources/js/layouts/booking-layout.tsx` — Avatar for business identity
- `docs/UI-CUSTOMIZATIONS.md` — NEW, Phase 4

## Verification (per phase)

Use the Chrome preview MCP at `http://localhost:8002/salone-bellissima`. After each component swap:

1. `preview_inspect` the swapped element for `background-color`, `color`, `border-color`, `padding`, `font-size`, `height`. Compare against pre-swap values. Drift > 5px on size or any color change without intent → revisit.
2. Walk the full booking flow end-to-end (service → collaborator → datetime → details → review → confirmation) after each component file lands. Verify the back button at every step still works.
3. After the final swap, visit `/dashboard` and confirm Buttons / Inputs / Cards render identically there (we didn't fork the primitives, so they should).
4. Toggle `.dark` on `<html>` — confirm both surfaces flip palette.
5. `npm run build` — zero TypeScript errors, zero Tailwind warnings.

## Out of scope for this plan

- Server-side formatters (Phase 5 note only).
- Reconciling `--card`, `--popover`, `--sidebar-*`, `--input`, `--secondary`, `--destructive`, `--chart-*`, `--code*` with the new palette. They retained their original (white / neutral / red / amber) values. Repaint is a deliberate design call.
- Replacing the dashboard's existing custom calendar styling (`resources/js/components/ui/calendar.tsx`) — out of booking scope.
