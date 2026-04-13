# Future UX Ideas

Items identified during the UI review that are deferred — either because they are low-impact
for MVP, require broader work, or depend on features not yet built.

## Inertia Polling
Consider using Inertia's built-in polling for the dashboard home (today's appointments summary),
so counts update without a manual refresh. Relevant once real usage data exists.
Reference: https://inertiajs.com/docs/v3/data-props/polling

## Prefetching
Inertia v3 supports link prefetching. Once the calendar view (Session 12) is built, evaluate
whether prefetching the next/previous month's data on hover improves perceived performance.
Reference: https://inertiajs.com/docs/v3/the-basics/links#prefetching

## Scroll Preservation
On the bookings list, returning from a booking detail panel currently resets scroll position.
Inertia's scroll preservation feature can fix this.
Reference: https://inertiajs.com/docs/v3/the-basics/scroll-management
