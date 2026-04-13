import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { InputError } from '@/components/input-error';
import { useTrans } from '@/hooks/use-trans';
import { router, useHttp } from '@inertiajs/react';
import { update } from '@/actions/App/Http/Controllers/Dashboard/Settings/WorkingHoursController';
import { WeekScheduleEditor } from '@/components/onboarding/week-schedule-editor';
import type { DaySchedule } from '@/components/onboarding/day-row';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';

interface Props {
    hours: DaySchedule[];
}

export default function WorkingHours({ hours: initialHours }: Props) {
    const { t } = useTrans();
    const [hours, setHours] = useState<DaySchedule[]>(initialHours);
    const http = useHttp({ hours: [] as DaySchedule[] });
    const pendingSubmit = useRef(false);

    function submit(e: FormEvent) {
        e.preventDefault();
        http.setData('hours', hours);
        pendingSubmit.current = true;
    }

    useEffect(() => {
        if (pendingSubmit.current && http.data.hours.length > 0) {
            pendingSubmit.current = false;
            http.put(update.url(), {
                onSuccess: () => {
                    router.reload();
                },
            });
        }
    }, [http.data.hours]);

    return (
        <SettingsLayout title={t('Working Hours')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Working Hours')}</CardTitle>
                    <CardDescription>{t('Set when your business is open. You can add breaks by creating multiple time windows per day.')}</CardDescription>
                </CardHeader>
                <form onSubmit={submit}>
                    <CardPanel>
                        <WeekScheduleEditor hours={hours} onChange={setHours} />
                        {http.hasErrors && (
                            <div className="mt-4">
                                {Object.values(http.errors).map((error: string, i: number) => (
                                    <InputError key={i} message={error} />
                                ))}
                            </div>
                        )}
                    </CardPanel>
                    <CardFooter className="flex justify-end">
                        <Button type="submit" disabled={http.processing}>{t('Save changes')}</Button>
                    </CardFooter>
                </form>
            </Card>
        </SettingsLayout>
    );
}
