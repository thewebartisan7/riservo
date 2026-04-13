import { useState, useEffect } from 'react';

interface CurrentTimeIndicatorProps {
    timezone: string;
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

export function CurrentTimeIndicator({ timezone }: CurrentTimeIndicatorProps) {
    const [minutesSinceMidnight, setMinutesSinceMidnight] = useState(() =>
        getCurrentMinutesInTimezone(timezone),
    );

    useEffect(() => {
        const interval = setInterval(() => {
            setMinutesSinceMidnight(getCurrentMinutesInTimezone(timezone));
        }, 60_000);

        return () => clearInterval(interval);
    }, [timezone]);

    // Grid row: 288 rows for 24 hours (12 rows per hour = 5-min intervals)
    // +2 for the header offset row
    const gridRow = Math.round((minutesSinceMidnight / 5) + 2);

    return (
        <div
            className="pointer-events-none absolute right-0 left-0 z-20"
            style={{ gridRow: `${gridRow} / span 1` }}
        >
            <div className="relative flex items-center">
                <div className="size-2.5 -ml-1 rounded-full bg-red-500" />
                <div className="h-px flex-1 bg-red-500" />
            </div>
        </div>
    );
}

export function isTodayInRange(timezone: string, rangeStart: Date, rangeEnd: Date): boolean {
    const now = new Date();
    const todayStr = now.toLocaleDateString('sv', { timeZone: timezone });
    const today = new Date(todayStr + 'T00:00:00');
    return today >= rangeStart && today <= rangeEnd;
}
