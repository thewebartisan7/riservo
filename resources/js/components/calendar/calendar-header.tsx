import { router } from '@inertiajs/react';
import { index as calendarIndex } from '@/actions/App/Http/Controllers/Dashboard/CalendarController';
import { addDays, addWeeks, addMonths, subDays, subWeeks, subMonths, format, startOfWeek, endOfWeek, parseISO } from 'date-fns';
import { useTrans } from '@/hooks/use-trans';
import { Button } from '@/components/ui/button';
import { Select, SelectTrigger, SelectValue, SelectPopup, SelectItem } from '@/components/ui/select';

interface CalendarHeaderProps {
    view: 'day' | 'week' | 'month';
    date: string; // Y-m-d
    isAdmin: boolean;
    onNewBooking: () => void;
}

function formatTitle(view: 'day' | 'week' | 'month', dateStr: string): string {
    const date = parseISO(dateStr);

    switch (view) {
        case 'day':
            return format(date, 'EEEE, MMMM d, yyyy');
        case 'week': {
            const weekStart = startOfWeek(date, { weekStartsOn: 1 });
            const weekEnd = endOfWeek(date, { weekStartsOn: 1 });
            if (weekStart.getMonth() === weekEnd.getMonth()) {
                return `${format(weekStart, 'MMM d')} – ${format(weekEnd, 'd, yyyy')}`;
            }
            if (weekStart.getFullYear() === weekEnd.getFullYear()) {
                return `${format(weekStart, 'MMM d')} – ${format(weekEnd, 'MMM d, yyyy')}`;
            }
            return `${format(weekStart, 'MMM d, yyyy')} – ${format(weekEnd, 'MMM d, yyyy')}`;
        }
        case 'month':
            return format(date, 'MMMM yyyy');
    }
}

function navigateCalendar(view: string, date: string) {
    router.get(
        calendarIndex.url(),
        { view, date },
        { preserveState: true, preserveScroll: true, only: ['bookings', 'view', 'date'] },
    );
}

export function CalendarHeader({ view, date, isAdmin, onNewBooking }: CalendarHeaderProps) {
    const { t } = useTrans();
    const parsedDate = parseISO(date);

    function goToday() {
        navigateCalendar(view, format(new Date(), 'yyyy-MM-dd'));
    }

    function goPrev() {
        const newDate =
            view === 'day'
                ? subDays(parsedDate, 1)
                : view === 'week'
                    ? subWeeks(parsedDate, 1)
                    : subMonths(parsedDate, 1);
        navigateCalendar(view, format(newDate, 'yyyy-MM-dd'));
    }

    function goNext() {
        const newDate =
            view === 'day'
                ? addDays(parsedDate, 1)
                : view === 'week'
                    ? addWeeks(parsedDate, 1)
                    : addMonths(parsedDate, 1);
        navigateCalendar(view, format(newDate, 'yyyy-MM-dd'));
    }

    function changeView(newView: string) {
        navigateCalendar(newView, date);
    }

    const prevLabel = view === 'day' ? t('Previous day') : view === 'week' ? t('Previous week') : t('Previous month');
    const nextLabel = view === 'day' ? t('Next day') : view === 'week' ? t('Next week') : t('Next month');

    return (
        <header className="flex flex-none items-center justify-between border-b border-gray-200 px-6 py-4">
            <div>
                <h1 className="text-base font-semibold text-gray-900">
                    {formatTitle(view, date)}
                </h1>
            </div>
            <div className="flex items-center gap-4">
                <div className="relative flex items-center rounded-md bg-white shadow-xs outline -outline-offset-1 outline-gray-300">
                    <button
                        type="button"
                        onClick={goPrev}
                        className="flex h-9 w-9 items-center justify-center rounded-l-md text-gray-400 hover:text-gray-500 hover:bg-gray-50 focus:relative"
                    >
                        <span className="sr-only">{prevLabel}</span>
                        <svg className="size-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fillRule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clipRule="evenodd" />
                        </svg>
                    </button>
                    <button
                        type="button"
                        onClick={goToday}
                        className="hidden px-3.5 text-sm font-semibold text-gray-900 hover:bg-gray-50 focus:relative md:block"
                    >
                        {t('Today')}
                    </button>
                    <span className="relative -mx-px h-5 w-px bg-gray-300 md:hidden" />
                    <button
                        type="button"
                        onClick={goNext}
                        className="flex h-9 w-9 items-center justify-center rounded-r-md text-gray-400 hover:text-gray-500 hover:bg-gray-50 focus:relative"
                    >
                        <span className="sr-only">{nextLabel}</span>
                        <svg className="size-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fillRule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clipRule="evenodd" />
                        </svg>
                    </button>
                </div>

                <div className="hidden md:block">
                    <Select value={view} onValueChange={changeView}>
                        <SelectTrigger className="w-[130px]">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectPopup>
                            <SelectItem value="day">{t('Day view')}</SelectItem>
                            <SelectItem value="week">{t('Week view')}</SelectItem>
                            <SelectItem value="month">{t('Month view')}</SelectItem>
                        </SelectPopup>
                    </Select>
                </div>

                {isAdmin && (
                    <>
                        <div className="hidden h-6 w-px bg-gray-300 md:block" />
                        <Button onClick={onNewBooking} className="hidden md:inline-flex">
                            {t('New booking')}
                        </Button>
                    </>
                )}

                {/* Mobile: compact actions */}
                <div className="flex items-center gap-2 md:hidden">
                    <button
                        type="button"
                        onClick={goToday}
                        className="text-sm font-semibold text-gray-900"
                    >
                        {t('Today')}
                    </button>
                </div>
            </div>
        </header>
    );
}
