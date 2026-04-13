import { Fragment, useEffect, useRef } from 'react';
import { parseISO, startOfWeek, addDays, format, isToday } from 'date-fns';
import type { DashboardBooking } from '@/types';
import type { CollaboratorColor } from '@/lib/calendar-colors';
import { CalendarEvent, getBookingGridPosition, getDateInTimezone, layoutOverlappingEvents } from './calendar-event';
import { CurrentTimeIndicator, isTodayInRange } from './current-time-indicator';

interface WeekViewProps {
    bookings: DashboardBooking[];
    date: string;
    timezone: string;
    colorMap: Map<number, CollaboratorColor>;
    onBookingClick: (booking: DashboardBooking) => void;
}

const HOURS = Array.from({ length: 24 }, (_, i) => i);

const DEFAULT_COLOR: CollaboratorColor = {
    bg: 'bg-gray-50',
    hoverBg: 'hover:bg-gray-100',
    text: 'text-gray-700',
    accent: 'text-gray-500',
    dot: 'bg-gray-400',
};

function formatHourLabel(hour: number): string {
    if (hour === 0) return '12AM';
    if (hour < 12) return `${hour}AM`;
    if (hour === 12) return '12PM';
    return `${hour - 12}PM`;
}

export function WeekView({ bookings, date, timezone, colorMap, onBookingClick }: WeekViewProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const parsedDate = parseISO(date);
    const weekStart = startOfWeek(parsedDate, { weekStartsOn: 1 });
    const weekDays = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));

    const showTimeIndicator = isTodayInRange(timezone, weekDays[0], weekDays[6]);
    const todayColIdx = showTimeIndicator ? getTodayColumnIndex(weekDays, timezone) : -1;

    // Group bookings by day
    const bookingsByDay = new Map<string, DashboardBooking[]>();
    for (const booking of bookings) {
        const dayKey = getDateInTimezone(booking.starts_at, timezone);
        const arr = bookingsByDay.get(dayKey) ?? [];
        arr.push(booking);
        bookingsByDay.set(dayKey, arr);
    }

    // Auto-scroll to 7 AM on mount
    useEffect(() => {
        if (containerRef.current) {
            const hourHeight = containerRef.current.scrollHeight / 24;
            containerRef.current.scrollTop = hourHeight * 7;
        }
    }, [date]);

    return (
        <div className="isolate flex h-full flex-col overflow-auto bg-white" ref={containerRef}>
            <div style={{ width: '165%' }} className="flex max-w-full flex-none flex-col sm:max-w-none md:max-w-full">
                {/* Day headers */}
                <div className="sticky top-0 z-30 flex-none bg-white shadow-sm ring-1 ring-black/5 sm:pr-8">
                    {/* Mobile day strip */}
                    <div className="grid grid-cols-7 text-sm/6 text-gray-500 sm:hidden">
                        {weekDays.map((day) => (
                            <button
                                key={day.toISOString()}
                                type="button"
                                className="flex flex-col items-center pt-2 pb-3"
                            >
                                {format(day, 'EEEEE')}
                                <span
                                    className={`mt-1 flex size-8 items-center justify-center font-semibold ${
                                        isToday(day)
                                            ? 'rounded-full bg-indigo-600 text-white'
                                            : 'text-gray-900'
                                    }`}
                                >
                                    {format(day, 'd')}
                                </span>
                            </button>
                        ))}
                    </div>

                    {/* Desktop day headers */}
                    <div className="-mr-px hidden grid-cols-7 divide-x divide-gray-100 border-r border-gray-100 text-sm/6 text-gray-500 sm:grid">
                        <div className="col-end-1 w-14" />
                        {weekDays.map((day) => (
                            <div key={day.toISOString()} className="flex items-center justify-center py-3">
                                <span className={isToday(day) ? 'flex items-baseline' : ''}>
                                    {format(day, 'EEE')}{' '}
                                    <span
                                        className={
                                            isToday(day)
                                                ? 'ml-1.5 flex size-8 items-center justify-center rounded-full bg-indigo-600 font-semibold text-white'
                                                : 'items-center justify-center font-semibold text-gray-900'
                                        }
                                    >
                                        {format(day, 'd')}
                                    </span>
                                </span>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Time grid */}
                <div className="flex flex-auto">
                    <div className="sticky left-0 z-10 w-14 flex-none bg-white ring-1 ring-gray-100" />
                    <div className="grid flex-auto grid-cols-1 grid-rows-1">
                        {/* Hour lines */}
                        <div
                            style={{ gridTemplateRows: 'repeat(48, minmax(3.5rem, 1fr))' }}
                            className="col-start-1 col-end-2 row-start-1 grid divide-y divide-gray-100"
                        >
                            <div className="row-end-1 h-7" />
                            {HOURS.map((hour) => (
                                <Fragment key={hour}>
                                    <div>
                                        <div className="sticky left-0 z-20 -mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">
                                            {formatHourLabel(hour)}
                                        </div>
                                    </div>
                                    <div />
                                </Fragment>
                            ))}
                        </div>

                        {/* Vertical day dividers */}
                        <div className="col-start-1 col-end-2 row-start-1 hidden grid-rows-1 divide-x divide-gray-100 sm:grid sm:grid-cols-7">
                            <div className="col-start-1 row-span-full" />
                            <div className="col-start-2 row-span-full" />
                            <div className="col-start-3 row-span-full" />
                            <div className="col-start-4 row-span-full" />
                            <div className="col-start-5 row-span-full" />
                            <div className="col-start-6 row-span-full" />
                            <div className="col-start-7 row-span-full" />
                            <div className="col-start-8 row-span-full w-8" />
                        </div>

                        {/* Events + current time indicator */}
                        <ol
                            style={{ gridTemplateRows: '1.75rem repeat(288, minmax(0, 1fr)) auto' }}
                            className="col-start-1 col-end-2 row-start-1 grid grid-cols-1 sm:grid-cols-7 sm:pr-8"
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
                                            const color = colorMap.get(booking.collaborator.id) ?? DEFAULT_COLOR;

                                            return (
                                                <li
                                                    key={booking.id}
                                                    className="relative mt-px hidden sm:flex"
                                                    style={{
                                                        gridRow: `${gridRow} / span ${gridSpan}`,
                                                        gridColumnStart: dayIndex + 1,
                                                        width: booking.totalColumns > 1
                                                            ? `${100 / booking.totalColumns}%`
                                                            : undefined,
                                                        marginLeft: booking.totalColumns > 1
                                                            ? `${(booking.column * 100) / booking.totalColumns}%`
                                                            : undefined,
                                                    }}
                                                >
                                                    <CalendarEvent
                                                        booking={booking}
                                                        color={color}
                                                        onClick={onBookingClick}
                                                        compact={gridSpan < 6}
                                                    />
                                                </li>
                                            );
                                        })}
                                    </Fragment>
                                );
                            })}

                            {/* Current time indicator */}
                            {showTimeIndicator && todayColIdx >= 0 && (
                                <li
                                    className="relative hidden sm:flex"
                                    style={{
                                        gridRow: '1 / -1',
                                        gridColumnStart: todayColIdx + 1,
                                    }}
                                >
                                    <CurrentTimeIndicator timezone={timezone} />
                                </li>
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
