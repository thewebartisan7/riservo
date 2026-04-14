import { router } from '@inertiajs/react';
import { index as calendarIndex } from '@/actions/App/Http/Controllers/Dashboard/CalendarController';
import {
    addDays,
    addWeeks,
    addMonths,
    subDays,
    subWeeks,
    subMonths,
    format,
    startOfWeek,
    endOfWeek,
    parseISO,
} from 'date-fns';
import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';
import { useTrans } from '@/hooks/use-trans';
import { Button } from '@/components/ui/button';
import { Display } from '@/components/ui/display';
import {
    Select,
    SelectItem,
    SelectPopup,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

interface CalendarHeaderProps {
    view: 'day' | 'week' | 'month';
    date: string;
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

    function changeView(newView: 'day' | 'week' | 'month' | null) {
        if (newView === null) {
            return;
        }
        navigateCalendar(newView, date);
    }

    const prevLabel =
        view === 'day' ? t('Previous day') : view === 'week' ? t('Previous week') : t('Previous month');
    const nextLabel =
        view === 'day' ? t('Next day') : view === 'week' ? t('Next week') : t('Next month');

    return (
        <header className="flex flex-none items-center justify-between gap-3 border-b border-border/70 bg-background px-5 py-4 sm:px-7">
            <div className="flex min-w-0 flex-col gap-1">
                <p className="text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                    {t('Calendar')}
                </p>
                <Display
                    render={<h1 />}
                    className="truncate text-lg font-semibold leading-tight text-foreground sm:text-xl"
                >
                    {formatTitle(view, date)}
                </Display>
            </div>

            <div className="flex items-center gap-2 sm:gap-3">
                <div className="inline-flex items-center rounded-lg border border-border/70 bg-card shadow-xs/5">
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        onClick={goPrev}
                        aria-label={prevLabel}
                        className="rounded-r-none border-none text-muted-foreground hover:text-foreground"
                    >
                        <ChevronLeftIcon aria-hidden="true" strokeWidth={1.75} />
                    </Button>
                    <span aria-hidden="true" className="h-4 w-px bg-border/70" />
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={goToday}
                        className="hidden rounded-none border-none px-3 text-foreground hover:bg-accent/50 sm:inline-flex"
                    >
                        {t('Today')}
                    </Button>
                    <span aria-hidden="true" className="hidden h-4 w-px bg-border/70 sm:block" />
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        onClick={goNext}
                        aria-label={nextLabel}
                        className="rounded-l-none border-none text-muted-foreground hover:text-foreground"
                    >
                        <ChevronRightIcon aria-hidden="true" strokeWidth={1.75} />
                    </Button>
                </div>

                <Button
                    variant="ghost"
                    size="sm"
                    onClick={goToday}
                    className="sm:hidden"
                >
                    {t('Today')}
                </Button>

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
                    <Button onClick={onNewBooking} className="hidden md:inline-flex">
                        {t('New booking')}
                    </Button>
                )}
            </div>
        </header>
    );
}
