# Calendar Integration Decisions

This file contains decisions specific to external calendar integration work. It is intentionally small today and will grow as calendar-sync implementation resumes.

---

### D-010 — Google Calendar sync scheduled late in the MVP sequence
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: Google Calendar sync (bidirectional, with webhooks) is the most complex feature in the MVP. It depends on all other features being stable first.
- **Decision**: Google Calendar sync is Session 12, deliberately scheduled near the end of the MVP sequence so it builds on stable booking and dashboard foundations. It is built behind a `CalendarProvider` interface so future providers (Outlook, Apple via CalDAV) can be added without touching booking logic.
- **Consequences**: If time or budget requires cutting a feature, this is the first candidate to defer to v2 without impacting core functionality.

---

### D-080 — Calendar integration stack: Socialite + `google/apiclient`
- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: The MVP-completion roadmap (`docs/roadmaps/ROADMAP-MVP-COMPLETION.md`) mandates Google Calendar sync. The OAuth flow and the Calendar API calls are two separable concerns. Rolling our own on top of Guzzle would mean re-implementing token refresh, typed event-object mapping, batch semantics, quota-aware retries, and the handful of Google-API edge cases the SDK already covers.
- **Decision**: Use `laravel/socialite` (v5) for the OAuth authorization-code flow (consent, callback, token exchange, refresh) and `google/apiclient:^2.15` for Calendar API calls once Session 2 starts making them. Both packages install in Session 1 (the OAuth foundation session) even though Session 1 only calls Socialite, so Session 2 lands on a green baseline with no composer step.
- **Consequences**:
  - ~7 MB dependency footprint for `google/apiclient`. One-time install cost is paid back by the Socialite + SDK gain on implementation velocity and correctness for every subsequent calendar feature.
  - Session 1's `Dashboard\Settings\CalendarIntegrationController::connect` / `callback` call `Socialite::driver('google')` directly. A `CalendarProvider` interface is deferred to Session 2, where the push / pull methods (`pushEvent`, `syncIncremental`, `startWatch`, …) motivate it. Adding an interface in Session 1 would either wrap a single `getAccountEmail` call (premature abstraction) or pre-commit to a method shape before Session 2's agent designs it.
  - No replacement for either package is easy to evaluate later without a concrete failure to motivate the swap.
