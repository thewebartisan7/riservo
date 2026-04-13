import type { DashboardBooking } from '@/types';
import type { CollaboratorColor } from '@/lib/calendar-colors';

interface CalendarEventProps {
    booking: DashboardBooking;
    color: CollaboratorColor;
    onClick: (booking: DashboardBooking) => void;
    compact?: boolean;
}

export function CalendarEvent({ booking, color, onClick, compact = false }: CalendarEventProps) {
    const startTime = new Date(booking.starts_at).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });

    return (
        <button
            type="button"
            onClick={() => onClick(booking)}
            className={`group absolute inset-x-1 flex flex-col overflow-hidden rounded-lg p-2 text-left text-xs/5 ${color.bg} ${color.hoverBg} transition-colors`}
            style={{ top: '1px', bottom: '1px' }}
        >
            <p className={`order-1 font-semibold ${color.text} truncate`}>
                {booking.service.name}
            </p>
            {!compact && (
                <p className={`order-1 ${color.accent} truncate`}>
                    {booking.customer.name}
                </p>
            )}
            <p className={`${color.accent} group-hover:${color.text}`}>
                <time dateTime={booking.starts_at}>{startTime}</time>
            </p>
        </button>
    );
}

/**
 * Compute the grid row start and span for a booking in the time grid.
 * Grid has 288 rows (24h x 12 five-minute intervals).
 * Row 2 = midnight (row 1 is the header offset).
 */
export function getBookingGridPosition(
    startsAt: string,
    endsAt: string,
    timezone: string,
): { gridRow: number; gridSpan: number } {
    const start = new Date(startsAt);
    const end = new Date(endsAt);

    const startMinutes = getMinutesInTimezone(start, timezone);
    const endMinutes = getMinutesInTimezone(end, timezone);

    const gridRow = Math.round(startMinutes / 5) + 2;
    const gridSpan = Math.max(Math.round((endMinutes - startMinutes) / 5), 1);

    return { gridRow, gridSpan };
}

function getMinutesInTimezone(date: Date, timezone: string): number {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone: timezone,
        hour: 'numeric',
        minute: 'numeric',
        hour12: false,
    }).formatToParts(date);

    const hour = parseInt(parts.find((p) => p.type === 'hour')?.value ?? '0', 10);
    const minute = parseInt(parts.find((p) => p.type === 'minute')?.value ?? '0', 10);

    return hour * 60 + minute;
}

/**
 * Get the date string (YYYY-MM-DD) for a datetime in a given timezone.
 */
export function getDateInTimezone(isoString: string, timezone: string): string {
    return new Date(isoString).toLocaleDateString('sv', { timeZone: timezone });
}

/**
 * Group overlapping events and assign column positions.
 * Returns events with their column and total columns info.
 */
export function layoutOverlappingEvents<T extends { starts_at: string; ends_at: string }>(
    events: T[],
): Array<T & { column: number; totalColumns: number }> {
    if (events.length === 0) return [];

    const sorted = [...events].sort(
        (a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime(),
    );

    const result: Array<T & { column: number; totalColumns: number }> = [];
    const columns: Array<{ end: number; items: typeof result }> = [];

    for (const event of sorted) {
        const eventStart = new Date(event.starts_at).getTime();
        const eventEnd = new Date(event.ends_at).getTime();

        // Find the first column where this event doesn't overlap
        let placed = false;
        for (let col = 0; col < columns.length; col++) {
            if (columns[col].end <= eventStart) {
                const entry = { ...event, column: col, totalColumns: 0 };
                columns[col].end = eventEnd;
                columns[col].items.push(entry);
                result.push(entry);
                placed = true;
                break;
            }
        }

        if (!placed) {
            const entry = { ...event, column: columns.length, totalColumns: 0 };
            columns.push({ end: eventEnd, items: [entry] });
            result.push(entry);
        }
    }

    // Set totalColumns for each event to the max columns used by its overlap group
    const totalColumns = columns.length;
    for (const entry of result) {
        entry.totalColumns = totalColumns;
    }

    return result;
}
