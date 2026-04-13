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
import type { DashboardBooking } from '@/types';
import type { CollaboratorColor } from '@/lib/calendar-colors';
import { getDateInTimezone } from './calendar-event';
import { useTrans } from '@/hooks/use-trans';

interface MonthViewProps {
    bookings: DashboardBooking[];
    date: string;
    timezone: string;
    colorMap: Map<number, CollaboratorColor>;
    onBookingClick: (booking: DashboardBooking) => void;
}

const DEFAULT_COLOR: CollaboratorColor = {
    bg: 'bg-gray-50',
    hoverBg: 'hover:bg-gray-100',
    text: 'text-gray-700',
    accent: 'text-gray-500',
    dot: 'bg-gray-400',
};

export function MonthView({ bookings, date, timezone, colorMap, onBookingClick }: MonthViewProps) {
    const { t } = useTrans();
    const parsedDate = parseISO(date);
    const monthStart = startOfMonth(parsedDate);
    const monthEnd = endOfMonth(parsedDate);
    const calendarStart = startOfWeek(monthStart, { weekStartsOn: 1 });
    const calendarEnd = endOfWeek(monthEnd, { weekStartsOn: 1 });
    const days = eachDayOfInterval({ start: calendarStart, end: calendarEnd });

    // Mobile: track selected day for event list below calendar
    const [selectedDay, setSelectedDay] = useState<Date>(parsedDate);

    // Group bookings by day
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

    return (
        <div className="h-full overflow-auto lg:flex lg:flex-col">
            <div className="shadow-sm ring-1 ring-black/5 lg:flex lg:flex-auto lg:flex-col">
                {/* Day of week headers */}
                <div className="grid grid-cols-7 gap-px border-b border-gray-300 bg-gray-200 text-center text-xs/6 font-semibold text-gray-700 lg:flex-none">
                    {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((day) => (
                        <div key={day} className="flex justify-center bg-white py-2">
                            <span>{day.charAt(0)}</span>
                            <span className="sr-only sm:not-sr-only">{day.slice(1)}</span>
                        </div>
                    ))}
                </div>

                {/* Calendar grid */}
                <div className="flex bg-gray-200 text-xs/6 text-gray-700 lg:flex-auto">
                    {/* Desktop grid */}
                    <div className="hidden w-full lg:grid lg:grid-cols-7 lg:gap-px" style={{ gridTemplateRows: `repeat(${rowCount}, minmax(0, 1fr))` }}>
                        {days.map((day) => {
                            const dayKey = format(day, 'yyyy-MM-dd');
                            const dayBookings = bookingsByDay.get(dayKey) ?? [];
                            const isCurrentMonth = isSameMonth(day, parsedDate);
                            const isTodayDate = isToday(day);

                            return (
                                <div
                                    key={dayKey}
                                    className={`group relative px-3 py-2 ${
                                        isCurrentMonth ? 'bg-white' : 'bg-gray-50 text-gray-500'
                                    }`}
                                >
                                    <time
                                        dateTime={dayKey}
                                        className={`relative ${
                                            !isCurrentMonth ? 'opacity-75' : ''
                                        } ${
                                            isTodayDate
                                                ? 'flex size-6 items-center justify-center rounded-full bg-indigo-600 font-semibold text-white'
                                                : ''
                                        }`}
                                    >
                                        {format(day, 'd')}
                                    </time>
                                    {dayBookings.length > 0 && (
                                        <ol className="mt-2">
                                            {dayBookings.slice(0, 2).map((booking) => {
                                                const color = colorMap.get(booking.collaborator.id) ?? DEFAULT_COLOR;
                                                const startTime = new Date(booking.starts_at).toLocaleTimeString([], {
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                    hour12: false,
                                                    timeZone: timezone,
                                                });

                                                return (
                                                    <li key={booking.id}>
                                                        <button
                                                            type="button"
                                                            onClick={() => onBookingClick(booking)}
                                                            className="group/event flex w-full text-left"
                                                        >
                                                            <span className={`mr-1.5 mt-1.5 size-2 flex-none rounded-full ${color.dot}`} />
                                                            <p className="flex-auto truncate font-medium text-gray-900 group-hover/event:text-indigo-600">
                                                                {booking.service.name}
                                                            </p>
                                                            <time
                                                                dateTime={booking.starts_at}
                                                                className="ml-3 hidden flex-none text-gray-500 group-hover/event:text-indigo-600 xl:block"
                                                            >
                                                                {startTime}
                                                            </time>
                                                        </button>
                                                    </li>
                                                );
                                            })}
                                            {dayBookings.length > 2 && (
                                                <li className="text-gray-500">+ {dayBookings.length - 2} {t('more')}</li>
                                            )}
                                        </ol>
                                    )}
                                </div>
                            );
                        })}
                    </div>

                    {/* Mobile grid */}
                    <div className="isolate grid w-full grid-cols-7 gap-px lg:hidden" style={{ gridTemplateRows: `repeat(${rowCount}, minmax(0, 1fr))` }}>
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
                                    className={`group relative flex h-14 flex-col px-3 py-2 hover:bg-gray-100 focus:z-10 ${
                                        isCurrentMonth ? 'bg-white' : 'bg-gray-50'
                                    } ${isSelected ? 'font-semibold text-white' : ''} ${
                                        isTodayDate && !isSelected ? 'font-semibold text-indigo-600' : ''
                                    } ${!isSelected && isCurrentMonth && !isTodayDate ? 'text-gray-900' : ''} ${
                                        !isSelected && !isCurrentMonth && !isTodayDate ? 'text-gray-500' : ''
                                    }`}
                                >
                                    <time
                                        dateTime={dayKey}
                                        className={`ml-auto ${!isCurrentMonth ? 'opacity-75' : ''} ${
                                            isSelected
                                                ? `flex size-6 items-center justify-center rounded-full ${
                                                    isTodayDate ? 'bg-indigo-600' : 'bg-gray-900'
                                                }`
                                                : ''
                                        }`}
                                    >
                                        {format(day, 'd')}
                                    </time>
                                    <span className="sr-only">{dayBookings.length} events</span>
                                    {dayBookings.length > 0 && (
                                        <span className="-mx-0.5 mt-auto flex flex-wrap-reverse">
                                            {dayBookings.map((b) => {
                                                const color = colorMap.get(b.collaborator.id) ?? DEFAULT_COLOR;
                                                return (
                                                    <span
                                                        key={b.id}
                                                        className={`mx-0.5 mb-1 size-1.5 rounded-full ${color.dot}`}
                                                    />
                                                );
                                            })}
                                        </span>
                                    )}
                                </button>
                            );
                        })}
                    </div>
                </div>
            </div>

            {/* Mobile: selected day's events */}
            <div className="relative px-4 py-10 sm:px-6 lg:hidden">
                {selectedDayBookings.length > 0 ? (
                    <ol className="divide-y divide-gray-100 overflow-hidden rounded-lg bg-white text-sm shadow-sm outline-1 outline-black/5">
                        {selectedDayBookings.map((booking) => {
                            const color = colorMap.get(booking.collaborator.id) ?? DEFAULT_COLOR;
                            const startTime = new Date(booking.starts_at).toLocaleTimeString([], {
                                hour: '2-digit',
                                minute: '2-digit',
                                hour12: false,
                                timeZone: timezone,
                            });

                            return (
                                <li
                                    key={booking.id}
                                    className="group flex p-4 pr-6 focus-within:bg-gray-50 hover:bg-gray-50"
                                >
                                    <div className="flex-auto">
                                        <p className="font-semibold text-gray-900">
                                            <span className={`mr-2 inline-block size-2 rounded-full ${color.dot}`} />
                                            {booking.service.name}
                                        </p>
                                        <p className="mt-1 text-gray-500">{booking.customer.name}</p>
                                        <time dateTime={booking.starts_at} className="mt-2 flex items-center text-gray-700">
                                            <svg className="mr-2 size-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                                                <path fillRule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 0 0 0-1.5h-3.25V5Z" clipRule="evenodd" />
                                            </svg>
                                            {startTime}
                                        </time>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => onBookingClick(booking)}
                                        className="ml-6 flex-none self-center rounded-md bg-white px-3 py-2 font-semibold text-gray-900 opacity-0 shadow-xs ring-1 ring-gray-300 ring-inset group-hover:opacity-100 hover:ring-gray-400 focus:opacity-100"
                                    >
                                        {t('View')}<span className="sr-only">, {booking.service.name}</span>
                                    </button>
                                </li>
                            );
                        })}
                    </ol>
                ) : (
                    <p className="text-center text-sm text-gray-500">
                        {t('No bookings on this day')}
                    </p>
                )}
            </div>
        </div>
    );
}
