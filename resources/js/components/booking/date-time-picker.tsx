import { useCallback, useEffect, useState } from 'react';
import { useHttp } from '@inertiajs/react';
import { availableDates as availableDatesAction, slots as slotsAction } from '@/actions/App/Http/Controllers/Booking/PublicBookingController';
import { Calendar } from '@/components/ui/calendar';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/hooks/use-trans';
import type { AvailableDatesResponse, AvailableSlotsResponse } from '@/types';

interface DateTimePickerProps {
    slug: string;
    serviceId: number;
    collaboratorId: number | null;
    onSelect: (date: string, time: string) => void;
}

export default function DateTimePicker({
    slug,
    serviceId,
    collaboratorId,
    onSelect,
}: DateTimePickerProps) {
    const { t } = useTrans();
    const [selectedDate, setSelectedDate] = useState<Date | undefined>(undefined);
    const [currentMonth, setCurrentMonth] = useState<Date>(new Date());
    const [availableDates, setAvailableDates] = useState<Record<string, boolean>>({});
    const [slots, setSlots] = useState<string[]>([]);

    const datesHttp = useHttp({});
    const slotsHttp = useHttp({});

    const formatMonth = useCallback((date: Date) => {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        return `${y}-${m}`;
    }, []);

    const formatDate = useCallback((date: Date) => {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }, []);

    // Fetch available dates for the current month
    useEffect(() => {
        const month = formatMonth(currentMonth);
        const collabParam = collaboratorId ? `&collaborator_id=${collaboratorId}` : '';
        const datesQuery: Record<string, string | number> = { service_id: serviceId, month };
        if (collaboratorId) datesQuery.collaborator_id = collaboratorId;
        datesHttp.get(
            availableDatesAction.url(slug, { query: datesQuery }),
            {
                onSuccess: (response: unknown) => {
                    const data = response as AvailableDatesResponse;
                    setAvailableDates(data.dates);
                },
            },
        );
    }, [slug, serviceId, collaboratorId, currentMonth]);

    // Fetch slots when a date is selected
    useEffect(() => {
        if (!selectedDate) {
            setSlots([]);
            return;
        }
        const dateStr = formatDate(selectedDate);
        const slotsQuery: Record<string, string | number> = { service_id: serviceId, date: dateStr };
        if (collaboratorId) slotsQuery.collaborator_id = collaboratorId;
        slotsHttp.get(
            slotsAction.url(slug, { query: slotsQuery }),
            {
                onSuccess: (response: unknown) => {
                    const data = response as AvailableSlotsResponse;
                    setSlots(data.slots);
                },
            },
        );
    }, [slug, serviceId, collaboratorId, selectedDate]);

    const disabledDates = useCallback(
        (date: Date) => {
            const dateStr = formatDate(date);
            return availableDates[dateStr] === false;
        },
        [availableDates, formatDate],
    );

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const hasNoSlots = selectedDate && !slotsHttp.processing && slots.length === 0;
    const hasAvailableDaysThisMonth = Object.values(availableDates).some(Boolean);

    return (
        <div className="flex flex-col gap-4">
            <h2 className="text-lg font-semibold">{t('Select date and time')}</h2>

            <div className="flex justify-center">
                <Calendar
                    mode="single"
                    selected={selectedDate}
                    onSelect={(date) => setSelectedDate(date ?? undefined)}
                    onMonthChange={setCurrentMonth}
                    disabled={(date) => date < today || disabledDates(date)}
                />
            </div>

            {!hasAvailableDaysThisMonth && !datesHttp.processing && (
                <div className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">
                    <p>{t('No availability this month.')}</p>
                    <Button
                        variant="link"
                        className="mt-1 h-auto p-0 text-sm"
                        onClick={() => {
                            const next = new Date(currentMonth);
                            next.setMonth(next.getMonth() + 1);
                            setCurrentMonth(next);
                        }}
                    >
                        {t('Try next month')}
                    </Button>
                </div>
            )}

            {selectedDate && slotsHttp.processing && (
                <div className="animate-pulse space-y-2">
                    <div className="h-4 w-32 rounded bg-muted" />
                    <div className="flex flex-wrap gap-2">
                        {[1, 2, 3, 4, 5].map((i) => (
                            <div key={i} className="h-9 w-16 rounded-md bg-muted" />
                        ))}
                    </div>
                </div>
            )}

            {hasNoSlots && (
                <p className="text-center text-sm text-muted-foreground">
                    {t('No available times for this date.')}
                </p>
            )}

            {selectedDate && slots.length > 0 && (
                <div>
                    <p className="mb-2 text-sm font-medium text-muted-foreground">
                        {t('Available times')}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {slots.map((time) => (
                            <Button
                                key={time}
                                variant="outline"
                                size="sm"
                                onClick={() => onSelect(formatDate(selectedDate), time)}
                            >
                                {time}
                            </Button>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
