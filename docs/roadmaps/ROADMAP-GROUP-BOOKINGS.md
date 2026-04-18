---
name: ROADMAP-GROUP-BOOKINGS
description: Multi-customer-per-slot bookings (group events, classes, workshops). Post-MVP.
type: roadmap
status: planning
created: 2026-04-15
updated: 2026-04-15
---

# riservo.ch — Group Bookings Roadmap

> Version: 0.1
> Frontmatter `status:` is authoritative (see the YAML block above). This roadmap is currently in `planning` (post-MVP — not yet scheduled for delivery).
> Scope: Multi-customer bookings per slot (group events, courses, workshops)

---

## Overview

Group bookings allow a single slot to accept multiple customers simultaneously — a collaborator runs a session and N customers can book the same time slot up to a configurable capacity limit.

Calendly and Cal.com call this feature "Group Events" or "Booking Seats". It is a common use case across many service verticals: yoga classes, workshops, group coaching sessions, training courses, tours, and similar.

This feature is **post-MVP**. The core riservo booking model (one customer per booking) is sufficient for launch. Group bookings require significant data model changes and should be planned as a standalone release.

---

## Data model changes required

The current booking model has a single `customer_id` on the `bookings` table. Group bookings require a fundamentally different structure:

- `capacity` field on `Service` — maximum number of customers per slot for this service. `null` or `1` means standard 1:1 booking.
- `booking_participants` table — replaces the single `customer_id` on `Booking`. Each row links a `Booking` to a `Customer`, with fields for their individual confirmation status, notes, and cancellation token.
- `Booking.customer_id` becomes nullable or is removed entirely in favour of the participants relation.
- Slot calculation logic changes: a slot remains available until `capacity` is reached, rather than being blocked after the first booking.

---

## Slot calculation impact

The current `SlotGeneratorService` treats a slot as blocked as soon as one confirmed/pending booking exists for that collaborator + time window. With group bookings, the logic becomes:

- Count confirmed/pending bookings for the slot
- If count < capacity → slot is still available (show remaining spots)
- If count >= capacity → slot is fully booked

The public booking page should optionally display remaining spots ("3 spots left") when the service has a capacity > 1.

---

## Calendar sync impact

When a group booking is pushed to Google Calendar via the calendar integration (Phase 2), each participant becomes a separate attendee on the same Google event. The `extendedProperties.private.riservo_booking_id` field identifies the riservo booking. On inbound sync, multiple attendee emails could theoretically map to multiple customers — this is the edge case noted in `ROADMAP-CALENDAR.md`.

The calendar integration must be designed to not assume a single customer per booking, so that group booking support can be added later without breaking the sync logic.

---

## Notifications

With multiple participants per booking, notification logic needs to handle:

- Confirmation email sent individually to each participant
- Cancellation by one participant should not cancel the whole booking
- Cancellation of the entire session by the business notifies all participants
- Reminder emails sent to each participant individually

---

## Public booking flow changes

- Service selection shows capacity and remaining spots (if applicable)
- Booking confirmation page confirms the specific participant's spot
- Each participant gets their own cancellation token (individual cancellation without affecting others)

---

## Competitor reference

- **Calendly**: "Group Event Types" — one host, configurable invitee limit per slot, display remaining spots toggle
- **Cal.com**: "Booking Seats" — same concept, each attendee books independently into the same slot
- **Acuity Scheduling**: supports multiple bookings per slot via capacity settings on appointment types

---

## Out of scope within this feature

- Multiple collaborators per session (collective events) — separate feature
- Waitlist when a slot is full — separate post-MVP feature
- Per-participant payment — requires Stripe integration (v2)
