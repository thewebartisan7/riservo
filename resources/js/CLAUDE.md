# Inertia v3 + React Frontend Rules

These rules apply to all frontend code in this project.

---

## Forms

Before creating any form, check the Inertia v3 forms docs (use Laravel Boost `search-docs`) and choose the right approach:

1. **`<Form>`** — preferred for standard submissions. Handles errors, processing state, and native form data automatically.
2. **`useForm`** — use when you need programmatic control (dynamic fields, multi-step wizards, pre-submit transforms).
3. **`router` methods** — last resort. Always add a comment explaining why `<Form>` and `useForm` were insufficient.

## HTTP Requests

**Use `useHttp` for all standalone AJAX requests.** Never use `fetch()` or `axios`.

```tsx
import { useHttp } from '@inertiajs/react';
const http = useHttp({ /* initial data */ });
http.get(url);
http.post(url, data);
```

## Partial Reloads

For filter, sort, and pagination interactions, use Inertia partial reloads with `only: [...]`:

```tsx
router.get(url, params, {
    preserveState: true,
    preserveScroll: true,
    only: ['bookings'],
});
```

This prevents re-fetching unchanged props. No server-side changes needed — Inertia v3 handles it automatically.

## File Uploads

File uploads go through `useHttp` — it handles `multipart/form-data` automatically when posting `File` objects. Do not manually construct `FormData` or use `fetch()`.

## Validation

Validation errors come from `<Form>` (via render props) or `useHttp` (via `http.errors`). Display them with `FieldError`:

```tsx
{errors.email && <FieldError match>{errors.email}</FieldError>}
```

Do not maintain parallel client-side validation state that duplicates what Inertia provides.

## TypeScript

- Type all page props with an interface that `extends PageProps`
- Type `usePage<MyPageProps>()` calls explicitly
- Define response shape interfaces in `@/types/index.d.ts` for `useHttp` responses — do not use inline `as` casts
- All user-facing strings go through `useTrans` / `t()`

## View Transitions

View Transitions are enabled globally in `app.tsx`. No per-page configuration needed.
