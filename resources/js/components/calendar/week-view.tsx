import { Fragment, useEffect, useRef } from 'react';
import { parseISO, startOfWeek, addDays, format, isToday } from 'date-fns';
import type { DashboardBooking } from '@/types';
import type { ProviderColor } from '@/lib/calendar-colors';
import { formatTimeShort } from '@/lib/datetime-format';
import { useTrans } from '@/hooks/use-trans';
import {
    CalendarEvent,
    getBookingGridPosition,
    getDateInTimezone,
    layoutOverlappingEvents,
} from './calendar-event';
import { CurrentTimeIndicator, isTodayInRange } from './current-time-indicator';

interface WeekViewProps {
    bookings: DashboardBooking[];
    date: string;
    timezone: string;
    colorMap: Map<number, ProviderColor>;
    onBookingClick: (booking: DashboardBooking) => void;
}

const HOURS = Array.from({ length: 24 }, (_, i) => i);

const DEFAULT_COLOR: ProviderColor = {
    bg: 'bg-muted',
    hoverBg: 'hover:bg-muted/80',
    text: 'text-foreground',
    accent: 'text-muted-foreground',
    dot: 'bg-muted-foreground',
};

function formatHourLabel(hour: number): string {
    if (hour === 0) return '12 AM';
    if (hour < 12) return `${hour} AM`;
    if (hour === 12) return '12 PM';
    return `${hour - 12} PM`;
}

export function WeekView({ bookings, date, timezone, colorMap, onBookingClick }: WeekViewProps) {
    const { t } = useTrans();
    const containerRef = useRef<HTMLDivElement>(null);
    const parsedDate = parseISO(date);
    const weekStart = startOfWeek(parsedDate, { weekStartsOn: 1 });
    const weekDays = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));

    const showTimeIndicator = isTodayInRange(timezone, weekDays[0], weekDays[6]);
    const todayColIdx = showTimeIndicator ? getTodayColumnIndex(weekDays, timezone) : -1;

    const bookingsByDay = new Map<string, DashboardBooking[]>();
    for (const booking of bookings) {
        const dayKey = getDateInTimezone(booking.starts_at, timezone);
        const arr = bookingsByDay.get(dayKey) ?? [];
        arr.push(booking);
        bookingsByDay.set(dayKey, arr);
    }

    useEffect(() => {
        if (containerRef.current) {
            const hourHeight = containerRef.current.scrollHeight / 24;
            containerRef.current.scrollTop = hourHeight * 7;
        }
    }, [date]);

    return (
        <div className="isolate flex min-h-0 flex-1 flex-col overflow-auto bg-background" ref={containerRef}>
            <div className="flex flex-none flex-col">
                {/* Day headers */}
                <div className="sticky top-0 z-30 flex-none border-b border-border/80 bg-background/95 backdrop-blur-sm">
                    {/* Mobile day strip */}
                    <div className="grid grid-cols-7 text-xs text-muted-foreground sm:hidden">
                        {weekDays.map((day) => {
                            const todayDay = isToday(day);
                            return (
                                <div
                                    key={day.toISOString()}
                                    className="flex flex-col items-center pt-2 pb-2.5"
                                >
                                    <span className="text-[10px] uppercase tracking-[0.14em]">
                                        {format(day, 'EEEEE')}
                                    </span>
                                    <span
                                        className={`mt-1 flex size-7 items-center justify-center text-sm font-semibold ${
                                            todayDay
                                                ? 'rounded-full bg-primary text-primary-foreground'
                                                : 'text-foreground'
                                        }`}
                                    >
                                        {format(day, 'd')}
                                    </span>
                                </div>
                            );
                        })}
                    </div>

                    {/* Desktop day headers */}
                    <div className="-mr-px hidden grid-cols-[3.5rem_repeat(7,1fr)_0.5rem] text-xs text-muted-foreground sm:grid">
                        <div className="border-r border-border/60" />
                        {weekDays.map((day) => {
                            const todayDay = isToday(day);
                            return (
                                <div
                                    key={day.toISOString()}
                                    className="flex items-center justify-center gap-2 border-r border-border/60 py-3"
                                >
                                    <span className="text-[10px] font-medium uppercase tracking-[0.16em]">
                                        {format(day, 'EEE')}
                                    </span>
                                    <span
                                        className={`flex h-6 min-w-6 items-center justify-center rounded-full px-1.5 font-display text-sm font-semibold tabular-nums ${
                                            todayDay
                                                ? 'bg-primary text-primary-foreground'
                                                : 'text-foreground'
                                        }`}
                                    >
                                        {format(day, 'd')}
                                    </span>
                                </div>
                            );
                        })}
                        <div />
                    </div>
                </div>

                {/* Mobile agenda list (under sm) */}
                <ol className="flex flex-col divide-y divide-border/60 px-5 pb-6 sm:hidden">
                    {weekDays.map((day) => {
                        const dayKey = format(day, 'yyyy-MM-dd');
                        const dayBookings = bookingsByDay.get(dayKey) ?? [];
                        return (
                            <li key={dayKey} className="flex flex-col gap-2 py-4">
                                <div className="flex items-center gap-2">
                                    <p className="text-[10px] font-medium uppercase tracking-[0.18em] text-muted-foreground">
                                        {format(day, 'EEEE, MMM d')}
                                    </p>
                                    {isToday(day) && (
                                        <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider text-primary">
                                            {t('Today')}
                                        </span>
                                    )}
                                </div>
                                {dayBookings.length > 0 ? (
                                    <ul className="flex flex-col gap-1">
                                        {dayBookings
                                            .slice()
                                            .sort(
                                                (a, b) =>
                                                    new Date(a.starts_at).getTime() -
                                                    new Date(b.starts_at).getTime(),
                                            )
                                            .map((booking) => {
                                                const color = colorMap.get(booking.provider.id) ?? DEFAULT_COLOR;
                                                const startTime = formatTimeShort(booking.starts_at, timezone);
                                                const endTime = formatTimeShort(booking.ends_at, timezone);
                                                return (
                                                    <li key={booking.id}>
                                                        <button
                                                            type="button"
                                                            onClick={() => onBookingClick(booking)}
                                                            className="group flex w-full items-center gap-3 rounded-lg border border-border/60 bg-background px-3 py-2 text-left transition-colors hover:bg-accent/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                        >
                                                            <span
                                                                aria-hidden="true"
                                                                className={`size-2 shrink-0 rounded-full ${color.dot}`}
                                                            />
                                                            <div className="flex min-w-0 flex-1 flex-col">
                                                                <p className="truncate text-sm font-semibold text-foreground">
                                                                    {booking.service.name}
                                                                </p>
                                                                <p className="truncate text-xs text-muted-foreground">
                                                                    {booking.customer.name}
                                                                </p>
                                                            </div>
                                                            <time
                                                                dateTime={booking.starts_at}
                                                                className="shrink-0 text-xs font-medium tabular-nums text-muted-foreground"
                                                            >
                                                                {startTime}–{endTime}
                                                            </time>
                                                        </button>
                                                    </li>
                                                );
                                            })}
                                    </ul>
                                ) : (
                                    <p className="text-xs text-muted-foreground">{t('No bookings')}</p>
                                )}
                            </li>
                        );
                    })}
                </ol>

                {/* Time grid */}
                <div className="hidden flex-auto sm:flex">
                    <div className="sticky left-0 z-10 w-14 flex-none border-r border-border/60 bg-background" />
                    <div className="grid flex-auto grid-cols-1 grid-rows-1">
                        {/* Hour lines */}
                        <div
                            style={{ gridTemplateRows: 'repeat(48, minmax(3.5rem, 1fr))' }}
                            className="col-start-1 col-end-2 row-start-1 grid divide-y divide-border/50"
                        >
                            <div className="row-end-1 h-7" />
                            {HOURS.map((hour) => (
                                <Fragment key={hour}>
                                    <div>
                                        <div className="sticky left-0 z-20 -mt-2.5 -ml-14 w-14 pr-2 text-right text-[10px] font-medium uppercase tracking-wider text-muted-foreground tabular-nums">
                                            {formatHourLabel(hour)}
                                        </div>
                                    </div>
                                    <div />
                                </Fragment>
                            ))}
                        </div>

                        {/* Vertical day dividers */}
                        <div className="col-start-1 col-end-2 row-start-1 hidden grid-rows-1 divide-x divide-border/50 sm:grid sm:grid-cols-7">
                            <div className="col-start-1 row-span-full" />
                            <div className="col-start-2 row-span-full" />
                            <div className="col-start-3 row-span-full" />
                            <div className="col-start-4 row-span-full" />
                            <div className="col-start-5 row-span-full" />
                            <div className="col-start-6 row-span-full" />
                            <div className="col-start-7 row-span-full" />
                            <div className="col-start-8 row-span-full w-2" />
                        </div>

                        {/* Events + current time indicator */}
                        <ol
                            style={{ gridTemplateRows: '1.75rem repeat(288, minmax(0, 1fr)) auto' }}
                            className="col-start-1 col-end-2 row-start-1 grid grid-cols-1 pr-2 sm:grid-cols-7"
                        >
                            {weekDays.map((day, dayIndex) => {
                                const dayKey = format(day, 'yyyy-MM-dd');
                                const dayBookings = bookingsByDay.get(dayKey) ?? [];
                                const layoutEvents = layoutOverlappingEvents(dayBookings);

                                return (
                                    <Fragment key={dayKey}>
                                        {layoutEvents.map((booking) => {
                                            const { gridRow, gridSpan } = getBookingGridPosition(
                                                booking.starts_at,
                                                booking.ends_at,
                                                timezone,
                                            );
                                            const color = colorMap.get(booking.provider.id) ?? DEFAULT_COLOR;

                                            return (
                                                <li
                                                    key={booking.id}
                                                    className="relative mt-px hidden sm:flex"
                                                    style={{
                                                        gridRow: `${gridRow} / span ${gridSpan}`,
                                                        gridColumnStart: dayIndex + 1,
                                                        width:
                                                            booking.totalColumns > 1
                                                                ? `${100 / booking.totalColumns}%`
                                                                : undefined,
                                                        marginLeft:
                                                            booking.totalColumns > 1
                                                                ? `${(booking.column * 100) / booking.totalColumns}%`
                                                                : undefined,
                                                    }}
                                                >
                                                    <CalendarEvent
                                                        booking={booking}
                                                        color={color}
                                                        timezone={timezone}
                                                        onClick={onBookingClick}
                                                        compact={gridSpan < 8}
                                                        tight={gridSpan < 4}
                                                    />
                                                </li>
                                            );
                                        })}
                                    </Fragment>
                                );
                            })}

                            {/* Current time indicator */}
                            {showTimeIndicator && todayColIdx >= 0 && (
                                <CurrentTimeIndicator timezone={timezone} columnStart={todayColIdx} />
                            )}
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    );
}

function getTodayColumnIndex(weekDays: Date[], timezone: string): number {
    const todayStr = new Date().toLocaleDateString('sv', { timeZone: timezone });
    return weekDays.findIndex((d) => format(d, 'yyyy-MM-dd') === todayStr);
}
