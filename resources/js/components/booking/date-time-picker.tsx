import { useCallback, useEffect, useMemo, useState } from 'react';
import { useHttp } from '@inertiajs/react';
import {
    availableDates as availableDatesAction,
    slots as slotsAction,
} from '@/actions/App/Http/Controllers/Booking/PublicBookingController';
import { useTrans } from '@/hooks/use-trans';
import type { AvailableDatesResponse, AvailableSlotsResponse } from '@/types';
import { ChevronLeft, ChevronRight, Sunrise, Sun, Moon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardPanel } from '@/components/ui/card';
import { Display } from '@/components/ui/display';
import { Skeleton } from '@/components/ui/skeleton';
import { formatYmd, pad } from '@/lib/booking-format';

interface DateTimePickerProps {
    slug: string;
    serviceId: number;
    collaboratorId: number | null;
    onSelect: (date: string, time: string) => void;
}

const WEEKDAY_KEYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'] as const;

function daysInMonth(year: number, month: number) {
    return new Date(year, month + 1, 0).getDate();
}

function groupSlots(times: string[]) {
    const morning: string[] = [];
    const afternoon: string[] = [];
    const evening: string[] = [];
    for (const t of times) {
        const h = parseInt(t.slice(0, 2), 10);
        if (h < 12) morning.push(t);
        else if (h < 17) afternoon.push(t);
        else evening.push(t);
    }
    return { morning, afternoon, evening };
}

export default function DateTimePicker({
    slug,
    serviceId,
    collaboratorId,
    onSelect,
}: DateTimePickerProps) {
    const { t } = useTrans();
    const now = useMemo(() => new Date(), []);
    const [viewYear, setViewYear] = useState<number>(now.getFullYear());
    const [viewMonth, setViewMonth] = useState<number>(now.getMonth());
    const [selectedDate, setSelectedDate] = useState<Date | null>(null);
    const [availableDates, setAvailableDates] = useState<Record<string, boolean>>({});
    const [slots, setSlots] = useState<string[]>([]);

    const datesHttp = useHttp({});
    const slotsHttp = useHttp({});

    const today = useMemo(() => {
        const d = new Date();
        d.setHours(0, 0, 0, 0);
        return d;
    }, []);

    useEffect(() => {
        const month = `${viewYear}-${pad(viewMonth + 1)}`;
        const query: Record<string, string | number> = { service_id: serviceId, month };
        if (collaboratorId) query.collaborator_id = collaboratorId;
        datesHttp.get(availableDatesAction.url(slug, { query }), {
            onSuccess: (response: unknown) => {
                const data = response as AvailableDatesResponse;
                setAvailableDates(data.dates);
            },
        });
    }, [slug, serviceId, collaboratorId, viewYear, viewMonth]);

    useEffect(() => {
        if (!selectedDate) {
            setSlots([]);
            return;
        }
        const query: Record<string, string | number> = {
            service_id: serviceId,
            date: formatYmd(selectedDate),
        };
        if (collaboratorId) query.collaborator_id = collaboratorId;
        slotsHttp.get(slotsAction.url(slug, { query }), {
            onSuccess: (response: unknown) => {
                const data = response as AvailableSlotsResponse;
                setSlots(data.slots);
            },
        });
    }, [slug, serviceId, collaboratorId, selectedDate]);

    const firstOfMonth = new Date(viewYear, viewMonth, 1);
    const lastOfMonth = new Date(viewYear, viewMonth, daysInMonth(viewYear, viewMonth));
    const leadingBlanks = (firstOfMonth.getDay() + 6) % 7;
    const gridDays: (Date | null)[] = [];
    for (let i = 0; i < leadingBlanks; i++) gridDays.push(null);
    for (let d = 1; d <= lastOfMonth.getDate(); d++)
        gridDays.push(new Date(viewYear, viewMonth, d));
    while (gridDays.length % 7 !== 0) gridDays.push(null);

    const hasAvailableInMonth = Object.values(availableDates).some(Boolean);
    const canGoPrev =
        viewYear > now.getFullYear() ||
        (viewYear === now.getFullYear() && viewMonth > now.getMonth());

    const goPrev = useCallback(() => {
        if (!canGoPrev) return;
        setSelectedDate(null);
        if (viewMonth === 0) {
            setViewMonth(11);
            setViewYear(viewYear - 1);
        } else {
            setViewMonth(viewMonth - 1);
        }
    }, [canGoPrev, viewMonth, viewYear]);

    const goNext = useCallback(() => {
        setSelectedDate(null);
        if (viewMonth === 11) {
            setViewMonth(0);
            setViewYear(viewYear + 1);
        } else {
            setViewMonth(viewMonth + 1);
        }
    }, [viewMonth, viewYear]);

    const monthLabel = new Intl.DateTimeFormat(undefined, {
        month: 'long',
        year: 'numeric',
    }).format(firstOfMonth);

    const selectedDateLabel = selectedDate
        ? new Intl.DateTimeFormat(undefined, {
              weekday: 'long',
              day: 'numeric',
              month: 'long',
          }).format(selectedDate)
        : null;

    const { morning, afternoon, evening } = groupSlots(slots);
    const groups: { label: string; icon: typeof Sun; list: string[] }[] = [
        { label: t('Morning'), icon: Sunrise, list: morning },
        { label: t('Afternoon'), icon: Sun, list: afternoon },
        { label: t('Evening'), icon: Moon, list: evening },
    ].filter((g) => g.list.length > 0);

    return (
        <div className="flex flex-col gap-7">
            <div>
                <Display
                    render={<h2 />}
                    className="text-2xl font-semibold leading-tight text-foreground"
                >
                    {t('When works for you?')}
                </Display>
                <p className="mt-1.5 text-sm text-muted-foreground">
                    {t('Dim days have no openings.')}
                </p>
            </div>

            {/* Calendar */}
            <Card>
                <CardPanel className="p-4 sm:p-5">
                    <div className="flex items-center justify-between">
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label={t('Previous month')}
                            onClick={goPrev}
                            disabled={!canGoPrev}
                        >
                            <ChevronLeft className="h-4 w-4" />
                        </Button>
                        <Display
                            render={<p />}
                            className="text-sm font-semibold capitalize text-foreground"
                        >
                            {monthLabel}
                        </Display>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label={t('Next month')}
                            onClick={goNext}
                        >
                            <ChevronRight className="h-4 w-4" />
                        </Button>
                    </div>

                    <div className="mt-4 grid grid-cols-7 gap-y-1 text-center text-xs uppercase tracking-widest text-muted-foreground">
                        {WEEKDAY_KEYS.map((k) => (
                            <div key={k} className="py-1">
                                {t(k)}
                            </div>
                        ))}
                    </div>

                    <div className="mt-1 grid grid-cols-7 gap-1">
                        {gridDays.map((d, i) => {
                            if (!d) return <div key={i} />;
                            const ymd = formatYmd(d);
                            const isPast = d < today;
                            const hasAvail = availableDates[ymd] === true;
                            const disabled = isPast || !hasAvail;
                            const isSelected =
                                !!selectedDate && formatYmd(selectedDate) === ymd;
                            const isToday = formatYmd(d) === formatYmd(today);

                            return (
                                <button
                                    key={ymd}
                                    type="button"
                                    disabled={disabled}
                                    aria-pressed={isSelected}
                                    {...(isToday ? { 'data-today': '' } : {})}
                                    onClick={() => !disabled && setSelectedDate(d)}
                                    className="tabular-nums relative mx-auto flex aspect-square w-full max-w-[44px] items-center justify-center rounded-full text-sm text-foreground transition-all aria-pressed:bg-primary aria-pressed:font-semibold aria-pressed:text-primary-foreground not-aria-pressed:enabled:hover:bg-accent not-aria-pressed:focus-visible:shadow-[0_0_0_2px_var(--ring)] focus-visible:outline-none data-[today]:font-semibold disabled:cursor-default disabled:text-muted-foreground disabled:opacity-35 enabled:cursor-pointer"
                                >
                                    {d.getDate()}
                                    {isToday && !isSelected && (
                                        <span
                                            className="absolute bottom-1 h-1 w-1 rounded-full bg-primary"
                                            aria-hidden
                                        />
                                    )}
                                </button>
                            );
                        })}
                    </div>
                </CardPanel>
            </Card>

            {/* No availability this month */}
            {!hasAvailableInMonth && !datesHttp.processing && !selectedDate && (
                <div className="flex items-center justify-between rounded-lg border border-border bg-muted px-4 py-3 text-sm text-secondary-foreground">
                    <span>{t('No openings this month.')}</span>
                    <Button
                        variant="link"
                        className="h-auto p-0 font-semibold text-primary"
                        onClick={goNext}
                    >
                        <Display>{t('Try next →')}</Display>
                    </Button>
                </div>
            )}

            {/* Slots */}
            {selectedDate && (
                <div>
                    <div className="flex items-baseline justify-between">
                        <Display
                            render={<p />}
                            className="text-sm font-semibold capitalize text-foreground"
                        >
                            {selectedDateLabel}
                        </Display>
                        <p className="tabular-nums text-xs uppercase tracking-widest text-muted-foreground">
                            {slots.length > 0
                                ? `${slots.length} ${t('open')}`
                                : slotsHttp.processing
                                  ? t('loading')
                                  : ''}
                        </p>
                    </div>

                    {slotsHttp.processing && (
                        <div className="mt-3 grid grid-cols-4 gap-2 sm:grid-cols-5">
                            {[1, 2, 3, 4, 5, 6, 7, 8].map((i) => (
                                <Skeleton key={i} className="h-10 rounded-md" />
                            ))}
                        </div>
                    )}

                    {!slotsHttp.processing && slots.length === 0 && (
                        <p className="mt-3 text-sm text-muted-foreground">
                            {t('Nothing open this day — try another.')}
                        </p>
                    )}

                    {!slotsHttp.processing && slots.length > 0 && (
                        <div className="mt-4 flex flex-col gap-5">
                            {groups.map(({ label, icon: Icon, list }) => (
                                <div key={label}>
                                    <div className="mb-2 flex items-center gap-2 text-xs uppercase tracking-widest text-muted-foreground">
                                        <Icon className="h-3 w-3" aria-hidden />
                                        <span>{label}</span>
                                    </div>
                                    <div className="grid grid-cols-4 gap-2 sm:grid-cols-5">
                                        {list.map((time) => (
                                            <Button
                                                key={time}
                                                variant="outline"
                                                size="sm"
                                                className="h-10 sm:h-10 tabular-nums hover:border-primary hover:bg-honey-soft hover:text-primary-foreground"
                                                onClick={() =>
                                                    onSelect(
                                                        formatYmd(selectedDate),
                                                        time,
                                                    )
                                                }
                                            >
                                                {time}
                                            </Button>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
