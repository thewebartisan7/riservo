# Calendar Integration Decisions

This file contains decisions specific to external calendar integration work. It is intentionally small today and will grow as calendar-sync implementation resumes.

---

### D-010 — Google Calendar sync scheduled late in the MVP sequence
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: Google Calendar sync (bidirectional, with webhooks) is the most complex feature in the MVP. It depends on all other features being stable first.
- **Decision**: Google Calendar sync is Session 12, deliberately scheduled near the end of the MVP sequence so it builds on stable booking and dashboard foundations. It is built behind a `CalendarProvider` interface so future providers (Outlook, Apple via CalDAV) can be added without touching booking logic.
- **Consequences**: If time or budget requires cutting a feature, this is the first candidate to defer to v2 without impacting core functionality.
