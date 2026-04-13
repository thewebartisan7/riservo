# COSS UI Component Rules

These rules apply to all frontend code that renders form controls, dialogs, or interactive elements.

---

## Form Fields

**Always use `Field` / `FieldLabel` / `FieldError` / `FieldDescription` for form fields.**

Never build fields manually with `<div>` + `<label>` + utility classes. The correct pattern:

```tsx
import { Field, FieldLabel, FieldError, FieldDescription } from '@/components/ui/field';

<Field>
    <FieldLabel>{t('Email')}</FieldLabel>
    <Input name="email" type="email" />
    <FieldDescription>{t('We will send a confirmation to this address')}</FieldDescription>
    {errors.email && <FieldError match>{errors.email}</FieldError>}
</Field>
```

Key details:
- `FieldError` wraps Base UI `Field.Error` which only renders for HTML5 validation by default. For server-side errors (Inertia), pass `match` (= `match={true}`) to force display. Wrap in a conditional to avoid rendering empty errors.
- `FieldDescription` replaces manual `<p className="text-xs text-muted-foreground">` helper text.
- `FieldLabel` replaces both raw `<label>` and the standalone `Label` component inside field contexts.
- Do not set `id` and `htmlFor` — Base UI `Field` auto-connects label to control via context.

## Selects

**Always use the COSS UI `Select` component. Never use native `<select>`.**

For selects inside Inertia `<Form>` (native form submission):
```tsx
<Select name="field_name" defaultValue={initialValue}>
    <SelectTrigger><SelectValue /></SelectTrigger>
    <SelectPopup>
        <SelectItem value="a">Option A</SelectItem>
        <SelectItem value="b">Option B</SelectItem>
    </SelectPopup>
</Select>
```

The `name` prop makes Base UI render a hidden `<input>` for native form participation.

For controlled selects (outside forms):
```tsx
<Select value={state} onValueChange={setState}>...</Select>
```

## Numeric Inputs

Use `NumberField` for numeric inputs that benefit from stepper buttons (duration, counts, hours).
Use `<Input type="number">` for freeform numeric inputs like price (where steppers are wrong UX).

## Dialogs

All `Dialog`, `AlertDialog`, and slide-over components must use the correct COSS UI structure:
`DialogHeader` → `DialogTitle` + `DialogDescription`, `DialogPanel` for body, `DialogFooter` for actions.

## Copy to Clipboard

Use the existing `EmbedSnippet` pattern or a simple `navigator.clipboard.writeText()` + `useState` for copy interactions. No external hook needed.

## General Rule

Before building any UI element from scratch, check if a COSS UI component exists for it.
Import path: `@/components/ui/<component-name>`.
