import { useState } from 'react';
import {
    parseISO,
    format,
    isToday,
    isSameMonth,
    startOfMonth,
    endOfMonth,
    startOfWeek,
    endOfWeek,
    eachDayOfInterval,
    isSameDay,
} from 'date-fns';
import { ClockIcon } from 'lucide-react';
import type { DashboardBooking } from '@/types';
import type { CollaboratorColor } from '@/lib/calendar-colors';
import { getDateInTimezone } from './calendar-event';
import { useTrans } from '@/hooks/use-trans';
import { formatTimeShort } from '@/lib/datetime-format';

interface MonthViewProps {
    bookings: DashboardBooking[];
    date: string;
    timezone: string;
    colorMap: Map<number, CollaboratorColor>;
    onBookingClick: (booking: DashboardBooking) => void;
}

const DEFAULT_COLOR: CollaboratorColor = {
    bg: 'bg-muted',
    hoverBg: 'hover:bg-muted/80',
    text: 'text-foreground',
    accent: 'text-muted-foreground',
    dot: 'bg-muted-foreground',
};

const WEEKDAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const;

export function MonthView({ bookings, date, timezone, colorMap, onBookingClick }: MonthViewProps) {
    const { t } = useTrans();
    const parsedDate = parseISO(date);
    const monthStart = startOfMonth(parsedDate);
    const monthEnd = endOfMonth(parsedDate);
    const calendarStart = startOfWeek(monthStart, { weekStartsOn: 1 });
    const calendarEnd = endOfWeek(monthEnd, { weekStartsOn: 1 });
    const days = eachDayOfInterval({ start: calendarStart, end: calendarEnd });

    const [selectedDay, setSelectedDay] = useState<Date>(parsedDate);

    const bookingsByDay = new Map<string, DashboardBooking[]>();
    for (const booking of bookings) {
        const dayKey = getDateInTimezone(booking.starts_at, timezone);
        const arr = bookingsByDay.get(dayKey) ?? [];
        arr.push(booking);
        bookingsByDay.set(dayKey, arr);
    }

    const selectedDayKey = format(selectedDay, 'yyyy-MM-dd');
    const selectedDayBookings = bookingsByDay.get(selectedDayKey) ?? [];

    const rowCount = Math.ceil(days.length / 7);

    const weekdayLabels: Record<(typeof WEEKDAY_KEYS)[number], { short: string; initial: string }> = {
        mon: { short: t('Mon'), initial: t('M') },
        tue: { short: t('Tue'), initial: t('T') },
        wed: { short: t('Wed'), initial: t('W') },
        thu: { short: t('Thu'), initial: t('T') },
        fri: { short: t('Fri'), initial: t('F') },
        sat: { short: t('Sat'), initial: t('S') },
        sun: { short: t('Sun'), initial: t('S') },
    };

    return (
        <div className="flex min-h-0 flex-1 flex-col overflow-hidden bg-background lg:flex-col">
            <div className="flex min-h-0 flex-1 flex-col overflow-auto lg:overflow-hidden">
                {/* Day of week headers */}
                <div className="sticky top-0 z-10 grid flex-none grid-cols-7 border-b border-border/70 bg-background/95 text-center text-[10px] font-medium uppercase tracking-[0.14em] text-muted-foreground backdrop-blur-sm lg:sticky-none">
                    {WEEKDAY_KEYS.map((key) => {
                        const label = weekdayLabels[key];
                        return (
                            <div key={key} className="flex justify-center py-2.5">
                                <span className="sm:hidden">{label.initial}</span>
                                <span className="hidden sm:inline">{label.short}</span>
                            </div>
                        );
                    })}
                </div>

                {/* Calendar grid */}
                <div className="flex min-h-0 flex-1 text-xs">
                    {/* Desktop grid */}
                    <div
                        className="hidden w-full bg-border/50 lg:grid lg:flex-1 lg:grid-cols-7 lg:gap-px"
                        style={{ gridTemplateRows: `repeat(${rowCount}, minmax(0, 1fr))` }}
                    >
                        {days.map((day) => {
                            const dayKey = format(day, 'yyyy-MM-dd');
                            const dayBookings = bookingsByDay.get(dayKey) ?? [];
                            const isCurrentMonth = isSameMonth(day, parsedDate);
                            const isTodayDate = isToday(day);

                            return (
                                <div
                                    key={dayKey}
                                    className={`group relative flex flex-col gap-1 overflow-hidden px-2.5 py-2 ${
                                        isCurrentMonth ? 'bg-background' : 'bg-muted/60'
                                    } ${isTodayDate ? 'bg-honey-soft/60' : ''}`}
                                >
                                    <time
                                        dateTime={dayKey}
                                        className={`flex h-6 w-min items-center rounded-full font-display text-sm font-semibold tabular-nums ${
                                            isTodayDate
                                                ? 'min-w-6 justify-center bg-primary px-1.5 text-primary-foreground'
                                                : isCurrentMonth
                                                    ? 'text-foreground'
                                                    : 'text-muted-foreground/70'
                                        }`}
                                    >
                                        {format(day, 'd')}
                                    </time>
                                    {dayBookings.length > 0 && (
                                        <ol className="flex flex-col gap-0.5 overflow-hidden">
                                            {dayBookings.slice(0, 3).map((booking) => {
                                                const color =
                                                    colorMap.get(booking.collaborator.id) ??
                                                    DEFAULT_COLOR;
                                                const startTime = formatTimeShort(booking.starts_at, timezone);

                                                return (
                                                    <li key={booking.id}>
                                                        <button
                                                            type="button"
                                                            onClick={() => onBookingClick(booking)}
                                                            className="group/event flex w-full items-center gap-1.5 rounded-md px-1 py-0.5 text-left text-[11px] transition-colors hover:bg-accent/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                        >
                                                            <span
                                                                aria-hidden="true"
                                                                className={`size-1.5 shrink-0 rounded-full ${color.dot}`}
                                                            />
                                                            <span className="flex-auto truncate font-medium text-foreground">
                                                                {booking.service.name}
                                                            </span>
                                                            <time
                                                                dateTime={booking.starts_at}
                                                                className="hidden flex-none tabular-nums text-muted-foreground xl:block"
                                                            >
                                                                {startTime}
                                                            </time>
                                                        </button>
                                                    </li>
                                                );
                                            })}
                                            {dayBookings.length > 3 && (
                                                <li className="px-1 text-[11px] text-muted-foreground">
                                                    {t('+ :count more', {
                                                        count: dayBookings.length - 3,
                                                    })}
                                                </li>
                                            )}
                                        </ol>
                                    )}
                                </div>
                            );
                        })}
                    </div>

                    {/* Mobile grid */}
                    <div
                        className="grid w-full grid-cols-7 gap-px bg-border/50 lg:hidden"
                        style={{ gridTemplateRows: `repeat(${rowCount}, minmax(0, 1fr))` }}
                    >
                        {days.map((day) => {
                            const dayKey = format(day, 'yyyy-MM-dd');
                            const dayBookings = bookingsByDay.get(dayKey) ?? [];
                            const isCurrentMonth = isSameMonth(day, parsedDate);
                            const isTodayDate = isToday(day);
                            const isSelected = isSameDay(day, selectedDay);

                            return (
                                <button
                                    key={dayKey}
                                    type="button"
                                    onClick={() => setSelectedDay(day)}
                                    aria-pressed={isSelected}
                                    className={`group relative flex h-14 flex-col items-end justify-between px-2 py-1.5 transition-colors focus-visible:z-10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset ${
                                        isCurrentMonth ? 'bg-background' : 'bg-muted/60'
                                    } ${isTodayDate && !isSelected ? 'bg-honey-soft/60' : ''}`}
                                >
                                    <time
                                        dateTime={dayKey}
                                        className={`flex size-6 items-center justify-center rounded-full text-sm font-semibold tabular-nums ${
                                            isSelected
                                                ? 'bg-foreground text-background'
                                                : isTodayDate
                                                    ? 'bg-primary text-primary-foreground'
                                                    : isCurrentMonth
                                                        ? 'text-foreground'
                                                        : 'text-muted-foreground/70'
                                        }`}
                                    >
                                        {format(day, 'd')}
                                    </time>
                                    <span className="sr-only">
                                        {t(':count events', { count: dayBookings.length })}
                                    </span>
                                    {dayBookings.length > 0 && (
                                        <span className="flex gap-0.5">
                                            {dayBookings.slice(0, 4).map((b) => {
                                                const color = colorMap.get(b.collaborator.id) ?? DEFAULT_COLOR;
                                                return (
                                                    <span
                                                        key={b.id}
                                                        aria-hidden="true"
                                                        className={`size-1 rounded-full ${color.dot}`}
                                                    />
                                                );
                                            })}
                                            {dayBookings.length > 4 && (
                                                <span
                                                    aria-hidden="true"
                                                    className="size-1 rounded-full bg-muted-foreground/60"
                                                />
                                            )}
                                        </span>
                                    )}
                                </button>
                            );
                        })}
                    </div>
                </div>
            </div>

            {/* Mobile: selected day's events */}
            <div className="flex max-h-[55%] flex-none flex-col border-t border-border/70 bg-muted/40 px-4 py-6 sm:px-6 lg:hidden">
                <p className="mb-3 flex-none text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                    {format(selectedDay, 'EEEE, MMM d')}
                </p>
                {selectedDayBookings.length > 0 ? (
                    <ol className="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto rounded-xl border border-border/60 bg-background p-1 text-sm shadow-xs/5">
                        {selectedDayBookings.map((booking) => {
                            const color = colorMap.get(booking.collaborator.id) ?? DEFAULT_COLOR;
                            const startTime = formatTimeShort(booking.starts_at, timezone);

                            return (
                                <li key={booking.id}>
                                    <button
                                        type="button"
                                        onClick={() => onBookingClick(booking)}
                                        className="group flex w-full items-center gap-3 rounded-lg p-3 text-left transition-colors hover:bg-accent/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className={`size-2 shrink-0 rounded-full ${color.dot}`}
                                        />
                                        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                            <p className="truncate font-semibold text-foreground">
                                                {booking.service.name}
                                            </p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {booking.customer.name}
                                            </p>
                                        </div>
                                        <time
                                            dateTime={booking.starts_at}
                                            className="flex shrink-0 items-center gap-1 text-xs font-medium tabular-nums text-muted-foreground"
                                        >
                                            <ClockIcon className="size-3.5" strokeWidth={1.75} aria-hidden="true" />
                                            {startTime}
                                        </time>
                                    </button>
                                </li>
                            );
                        })}
                    </ol>
                ) : (
                    <p className="rounded-xl border border-dashed border-border/70 bg-background/60 p-6 text-center text-sm text-muted-foreground">
                        {t('No bookings on this day')}
                    </p>
                )}
            </div>
        </div>
    );
}
