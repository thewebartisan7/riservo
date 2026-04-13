import { Fragment, useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { index as calendarIndex } from '@/actions/App/Http/Controllers/Dashboard/CalendarController';
import {
    addDays,
    parseISO,
    format,
    isToday,
    startOfMonth,
    endOfMonth,
    startOfWeek,
    endOfWeek,
    eachDayOfInterval,
    isSameMonth,
    isSameDay,
} from 'date-fns';
import type { DashboardBooking } from '@/types';
import type { CollaboratorColor } from '@/lib/calendar-colors';
import { CalendarEvent, getBookingGridPosition, layoutOverlappingEvents } from './calendar-event';
import { CurrentTimeIndicator } from './current-time-indicator';

interface DayViewProps {
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

export function DayView({ bookings, date, timezone, colorMap, onBookingClick }: DayViewProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const parsedDate = parseISO(date);
    const showTimeIndicator = isToday(parsedDate);
    const layoutEvents = layoutOverlappingEvents(bookings);

    // Auto-scroll to 7 AM on mount
    useEffect(() => {
        if (containerRef.current) {
            const hourHeight = containerRef.current.scrollHeight / 24;
            containerRef.current.scrollTop = hourHeight * 7;
        }
    }, [date]);

    function navigateToDay(day: Date) {
        router.get(
            calendarIndex.url(),
            { view: 'day', date: format(day, 'yyyy-MM-dd') },
            { preserveState: true, preserveScroll: true, only: ['bookings', 'view', 'date'] },
        );
    }

    return (
        <div className="isolate flex h-full flex-auto overflow-hidden bg-white">
            <div className="flex flex-auto flex-col overflow-auto" ref={containerRef}>
                {/* Mobile day strip */}
                <div className="sticky top-0 z-10 grid flex-none grid-cols-7 bg-white text-xs text-gray-500 shadow-sm ring-1 ring-black/5 md:hidden">
                    <MobileDayStrip date={parsedDate} onSelectDay={navigateToDay} />
                </div>

                {/* Time grid */}
                <div className="flex w-full flex-auto">
                    <div className="w-14 flex-none bg-white ring-1 ring-gray-100" />
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
                                        <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">
                                            {formatHourLabel(hour)}
                                        </div>
                                    </div>
                                    <div />
                                </Fragment>
                            ))}
                        </div>

                        {/* Events */}
                        <ol
                            style={{ gridTemplateRows: '1.75rem repeat(288, minmax(0, 1fr)) auto' }}
                            className="col-start-1 col-end-2 row-start-1 grid grid-cols-1"
                        >
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
                                        className="relative mt-px flex"
                                        style={{
                                            gridRow: `${gridRow} / span ${gridSpan}`,
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

                            {/* Current time indicator */}
                            {showTimeIndicator && (
                                <li className="relative flex" style={{ gridRow: '1 / -1' }}>
                                    <CurrentTimeIndicator timezone={timezone} />
                                </li>
                            )}
                        </ol>
                    </div>
                </div>
            </div>

            {/* Sidebar mini calendar (desktop only) */}
            <div className="hidden w-1/2 max-w-md flex-none border-l border-gray-100 px-8 py-10 md:block">
                <MiniCalendar date={parsedDate} onSelectDay={navigateToDay} />
            </div>
        </div>
    );
}

function MobileDayStrip({ date, onSelectDay }: { date: Date; onSelectDay: (d: Date) => void }) {
    const weekStart = startOfWeek(date, { weekStartsOn: 1 });
    const days = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));

    return (
        <>
            {days.map((day) => (
                <button
                    key={day.toISOString()}
                    type="button"
                    onClick={() => onSelectDay(day)}
                    className="flex flex-col items-center pt-3 pb-1.5"
                >
                    <span>{format(day, 'EEEEE')}</span>
                    <span
                        className={`mt-3 flex size-8 items-center justify-center rounded-full text-base font-semibold ${
                            isSameDay(day, date)
                                ? 'bg-gray-900 text-white'
                                : isToday(day)
                                    ? 'text-indigo-600'
                                    : 'text-gray-900'
                        }`}
                    >
                        {format(day, 'd')}
                    </span>
                </button>
            ))}
        </>
    );
}

function MiniCalendar({ date, onSelectDay }: { date: Date; onSelectDay: (d: Date) => void }) {
    const [displayMonth, setDisplayMonth] = useState(date);

    const monthStart = startOfMonth(displayMonth);
    const monthEnd = endOfMonth(displayMonth);
    const calendarStart = startOfWeek(monthStart, { weekStartsOn: 1 });
    const calendarEnd = endOfWeek(monthEnd, { weekStartsOn: 1 });
    const days = eachDayOfInterval({ start: calendarStart, end: calendarEnd });

    return (
        <div>
            <div className="flex items-center text-center text-gray-900">
                <button
                    type="button"
                    onClick={() => setDisplayMonth((d) => new Date(d.getFullYear(), d.getMonth() - 1, 1))}
                    className="-m-1.5 flex flex-none items-center justify-center p-1.5 text-gray-400 hover:text-gray-500"
                >
                    <span className="sr-only">Previous month</span>
                    <svg className="size-5" viewBox="0 0 20 20" fill="currentColor">
                        <path fillRule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clipRule="evenodd" />
                    </svg>
                </button>
                <div className="flex-auto text-sm font-semibold">{format(displayMonth, 'MMMM yyyy')}</div>
                <button
                    type="button"
                    onClick={() => setDisplayMonth((d) => new Date(d.getFullYear(), d.getMonth() + 1, 1))}
                    className="-m-1.5 flex flex-none items-center justify-center p-1.5 text-gray-400 hover:text-gray-500"
                >
                    <span className="sr-only">Next month</span>
                    <svg className="size-5" viewBox="0 0 20 20" fill="currentColor">
                        <path fillRule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clipRule="evenodd" />
                    </svg>
                </button>
            </div>
            <div className="mt-6 grid grid-cols-7 text-center text-xs/6 text-gray-500">
                <div>M</div>
                <div>T</div>
                <div>W</div>
                <div>T</div>
                <div>F</div>
                <div>S</div>
                <div>S</div>
            </div>
            <div className="isolate mt-2 grid grid-cols-7 gap-px rounded-lg bg-gray-200 text-sm shadow-sm ring-1 ring-gray-200">
                {days.map((day, i) => {
                    const isCurrentMonth = isSameMonth(day, displayMonth);
                    const isSelected = isSameDay(day, date);
                    const isTodayDate = isToday(day);

                    return (
                        <button
                            key={day.toISOString()}
                            type="button"
                            onClick={() => onSelectDay(day)}
                            className={`py-1.5 hover:bg-gray-100 focus:z-10 ${
                                isCurrentMonth ? 'bg-white' : 'bg-gray-50'
                            } ${i === 0 ? 'rounded-tl-lg' : ''} ${i === 6 ? 'rounded-tr-lg' : ''} ${
                                i === days.length - 7 ? 'rounded-bl-lg' : ''
                            } ${i === days.length - 1 ? 'rounded-br-lg' : ''} ${
                                isSelected ? 'font-semibold text-white' : ''
                            } ${isTodayDate && !isSelected ? 'font-semibold text-indigo-600' : ''} ${
                                !isSelected && isCurrentMonth && !isTodayDate ? 'text-gray-900' : ''
                            } ${!isSelected && !isCurrentMonth && !isTodayDate ? 'text-gray-400' : ''}`}
                        >
                            <time
                                dateTime={format(day, 'yyyy-MM-dd')}
                                className={`mx-auto flex size-7 items-center justify-center rounded-full ${
                                    isSelected && isTodayDate
                                        ? 'bg-indigo-600'
                                        : isSelected
                                            ? 'bg-gray-900'
                                            : ''
                                }`}
                            >
                                {format(day, 'd')}
                            </time>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
