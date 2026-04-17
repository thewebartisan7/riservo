import { Popover, PopoverPopup, PopoverTrigger } from '@/components/ui/popover';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/hooks/use-trans';
import { formatTimeShort } from '@/lib/datetime-format';
import type { DashboardBooking } from '@/types';
import type { ProviderColor } from '@/lib/calendar-colors';

interface CalendarEventProps {
    booking: DashboardBooking;
    color: ProviderColor;
    timezone: string;
    onClick: (booking: DashboardBooking) => void;
    /** True when the event's grid span is too small to fit all content inline. */
    compact?: boolean;
    /** True when the event is so tight only a title + time can fit. */
    tight?: boolean;
}

export function CalendarEvent({
    booking,
    color,
    timezone,
    onClick,
    compact = false,
    tight = false,
}: CalendarEventProps) {
    const { t } = useTrans();
    const startTime = formatTimeShort(booking.starts_at, timezone);
    const endTime = formatTimeShort(booking.ends_at, timezone);

    // For very tight bookings: render a compact trigger that reveals full details in a Popover.
    if (tight) {
        return (
            <Popover>
                <PopoverTrigger
                    className={`group absolute inset-x-1 flex items-center gap-1.5 overflow-hidden rounded-md border border-transparent px-1.5 text-left text-[11px]/4 ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 ${color.bg} ${color.hoverBg}`}
                    style={{ top: '1px', bottom: '1px' }}
                >
                    <span aria-hidden="true" className={`size-1.5 shrink-0 rounded-full ${color.dot}`} />
                    <span className={`truncate font-medium ${color.text}`}>
                        {booking.service?.name ?? booking.external_title ?? t('External event')}
                    </span>
                </PopoverTrigger>
                <PopoverPopup side="right" align="start" sideOffset={6} className="w-64">
                    <div className="flex flex-col gap-3">
                        <div className="flex items-start gap-2">
                            <span
                                aria-hidden="true"
                                className={`mt-1.5 size-2 shrink-0 rounded-full ${color.dot}`}
                            />
                            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <p className="font-semibold text-foreground text-sm leading-snug">
                                    {booking.service?.name ?? booking.external_title ?? t('External event')}
                                </p>
                                <p className="truncate text-muted-foreground text-xs">
                                    {booking.customer?.name ?? (booking.external ? t('External') : '')}
                                </p>
                            </div>
                        </div>
                        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                            <dt className="text-muted-foreground">{t('Time')}</dt>
                            <dd className="text-foreground">
                                <time dateTime={booking.starts_at}>{startTime}</time>
                                {' – '}
                                <time dateTime={booking.ends_at}>{endTime}</time>
                            </dd>
                            <dt className="text-muted-foreground">{t('With')}</dt>
                            <dd className="truncate text-foreground">
                                {booking.provider.is_active
                                    ? booking.provider.name
                                    : t(':name (deactivated)', { name: booking.provider.name })}
                            </dd>
                        </dl>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => onClick(booking)}
                            className="w-full"
                        >
                            {t('Open booking')}
                        </Button>
                    </div>
                </PopoverPopup>
            </Popover>
        );
    }

    return (
        <button
            type="button"
            onClick={() => onClick(booking)}
            className={`group absolute inset-x-1 flex flex-col overflow-hidden rounded-md border border-transparent p-1.5 text-left text-[11px]/4 ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 ${color.bg} ${color.hoverBg}`}
            style={{ top: '1px', bottom: '1px' }}
        >
            <p className={`truncate font-semibold ${color.text}`}>
                {booking.service?.name ?? booking.external_title ?? t('External event')}
            </p>
            {!compact && (
                <p className={`truncate ${color.accent}`}>
                    {booking.customer?.name ?? (booking.external ? t('External') : '')}
                </p>
            )}
            <p className={`mt-auto ${color.accent}`}>
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
