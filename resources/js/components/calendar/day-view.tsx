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
import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';
import type { DashboardBooking } from '@/types';
import type { CollaboratorColor } from '@/lib/calendar-colors';
import {
    CalendarEvent,
    getBookingGridPosition,
    layoutOverlappingEvents,
} from './calendar-event';
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

export function DayView({ bookings, date, timezone, colorMap, onBookingClick }: DayViewProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const parsedDate = parseISO(date);
    const showTimeIndicator = isToday(parsedDate);
    const layoutEvents = layoutOverlappingEvents(bookings);

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
        <div className="isolate flex min-h-0 flex-1 overflow-hidden bg-background">
            <div className="flex flex-auto flex-col overflow-auto" ref={containerRef}>
                {/* Mobile day strip */}
                <div className="sticky top-0 z-10 grid flex-none grid-cols-7 border-b border-border/80 bg-background/95 text-xs text-muted-foreground backdrop-blur-sm md:hidden">
                    <MobileDayStrip date={parsedDate} onSelectDay={navigateToDay} />
                </div>

                {/* Time grid */}
                <div className="flex w-full flex-auto">
                    <div className="w-14 flex-none border-r border-border/60 bg-background" />
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
                                        <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-[10px] font-medium uppercase tracking-wider text-muted-foreground tabular-nums">
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
                            className="col-start-1 col-end-2 row-start-1 grid grid-cols-1 pr-4"
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

                            {/* Current time indicator */}
                            {showTimeIndicator && (
                                <CurrentTimeIndicator timezone={timezone} />
                            )}
                        </ol>
                    </div>
                </div>
            </div>

            {/* Sidebar mini calendar (desktop only) */}
            <div className="hidden w-80 flex-none border-l border-border/70 bg-background px-6 py-8 md:block">
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
            {days.map((day) => {
                const isSelectedDay = isSameDay(day, date);
                const todayDay = isToday(day);
                return (
                    <button
                        key={day.toISOString()}
                        type="button"
                        onClick={() => onSelectDay(day)}
                        className="flex flex-col items-center gap-1 py-2 text-xs font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
                    >
                        <span className="text-[10px] uppercase tracking-[0.14em] text-muted-foreground">
                            {format(day, 'EEEEE')}
                        </span>
                        <span
                            className={`flex size-7 items-center justify-center rounded-full text-sm font-semibold tabular-nums ${
                                isSelectedDay
                                    ? 'bg-foreground text-background'
                                    : todayDay
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-foreground'
                            }`}
                        >
                            {format(day, 'd')}
                        </span>
                    </button>
                );
            })}
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
            <div className="flex items-center text-foreground">
                <button
                    type="button"
                    onClick={() =>
                        setDisplayMonth((d) => new Date(d.getFullYear(), d.getMonth() - 1, 1))
                    }
                    className="-m-1.5 inline-flex size-7 flex-none items-center justify-center rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <span className="sr-only">Previous month</span>
                    <ChevronLeftIcon className="size-4" strokeWidth={1.75} aria-hidden="true" />
                </button>
                <div className="flex-auto text-center font-display text-sm font-semibold tracking-tight">
                    {format(displayMonth, 'MMMM yyyy')}
                </div>
                <button
                    type="button"
                    onClick={() =>
                        setDisplayMonth((d) => new Date(d.getFullYear(), d.getMonth() + 1, 1))
                    }
                    className="-m-1.5 inline-flex size-7 flex-none items-center justify-center rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <span className="sr-only">Next month</span>
                    <ChevronRightIcon className="size-4" strokeWidth={1.75} aria-hidden="true" />
                </button>
            </div>
            <div className="mt-5 grid grid-cols-7 text-center text-[10px] font-medium uppercase tracking-[0.14em] text-muted-foreground">
                <div>M</div>
                <div>T</div>
                <div>W</div>
                <div>T</div>
                <div>F</div>
                <div>S</div>
                <div>S</div>
            </div>
            <div className="mt-2 grid grid-cols-7 gap-0.5 text-sm">
                {days.map((day) => {
                    const isCurrentMonth = isSameMonth(day, displayMonth);
                    const isSelected = isSameDay(day, date);
                    const isTodayDate = isToday(day);

                    return (
                        <button
                            key={day.toISOString()}
                            type="button"
                            onClick={() => onSelectDay(day)}
                            aria-pressed={isSelected}
                            className={`flex aspect-square items-center justify-center rounded-md font-medium tabular-nums transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset ${
                                isSelected
                                    ? 'bg-foreground text-background'
                                    : isTodayDate
                                        ? 'bg-honey-soft text-primary-foreground hover:bg-honey-soft/70'
                                        : isCurrentMonth
                                            ? 'text-foreground hover:bg-accent/50'
                                            : 'text-muted-foreground/60 hover:bg-accent/30'
                            }`}
                        >
                            <time dateTime={format(day, 'yyyy-MM-dd')}>{format(day, 'd')}</time>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
