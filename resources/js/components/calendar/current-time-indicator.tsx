import { useState, useEffect } from 'react';

interface CurrentTimeIndicatorProps {
    timezone: string;
    /** Optional column index (0-based) for week view; omit for full-width (day view). */
    columnStart?: number;
}

function getCurrentMinutesInTimezone(timezone: string): number {
    const now = new Date();
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone: timezone,
        hour: 'numeric',
        minute: 'numeric',
        hour12: false,
    }).formatToParts(now);

    const hour = parseInt(parts.find((p) => p.type === 'hour')?.value ?? '0', 10);
    const minute = parseInt(parts.find((p) => p.type === 'minute')?.value ?? '0', 10);

    return hour * 60 + minute;
}

/**
 * Renders a horizontal honey-tinted line at the current time within the calendar grid.
 * Must be rendered directly inside the time grid <ol> so grid-row positioning applies.
 */
export function CurrentTimeIndicator({ timezone, columnStart }: CurrentTimeIndicatorProps) {
    const [minutesSinceMidnight, setMinutesSinceMidnight] = useState(() =>
        getCurrentMinutesInTimezone(timezone),
    );

    useEffect(() => {
        const interval = setInterval(() => {
            setMinutesSinceMidnight(getCurrentMinutesInTimezone(timezone));
        }, 60_000);

        return () => clearInterval(interval);
    }, [timezone]);

    // Grid row: header offset (row 1 = 1.75rem) + 288 5-minute slots. Row 2 = midnight.
    const gridRow = Math.round(minutesSinceMidnight / 5) + 2;

    const style: React.CSSProperties = {
        gridRow: `${gridRow} / span 1`,
    };
    if (typeof columnStart === 'number') {
        style.gridColumnStart = columnStart + 1;
    }

    return (
        <li className="pointer-events-none relative z-20 -mt-px flex items-center" style={style}>
            <span
                aria-hidden="true"
                className="-ml-1 size-2 shrink-0 rounded-full bg-primary shadow-[0_0_0_3px_--theme(--color-background)]"
            />
            <span aria-hidden="true" className="h-px flex-1 bg-primary/70" />
        </li>
    );
}

export function isTodayInRange(timezone: string, rangeStart: Date, rangeEnd: Date): boolean {
    const now = new Date();
    const todayStr = now.toLocaleDateString('sv', { timeZone: timezone });
    const today = new Date(todayStr + 'T00:00:00');
    return today >= rangeStart && today <= rangeEnd;
}
