import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { InputError } from '@/components/input-error';
import { useTrans } from '@/hooks/use-trans';
import { router, usePage } from '@inertiajs/react';
import { update } from '@/actions/App/Http/Controllers/Dashboard/Settings/WorkingHoursController';
import { WeekScheduleEditor } from '@/components/onboarding/week-schedule-editor';
import type { DaySchedule } from '@/components/onboarding/day-row';
import type { FormEvent } from 'react';
import { useState } from 'react';
import type { PageProps } from '@/types';

interface Props {
    hours: DaySchedule[];
}

export default function WorkingHours({ hours: initialHours }: Props) {
    const { t } = useTrans();
    const [hours, setHours] = useState<DaySchedule[]>(initialHours);
    const [processing, setProcessing] = useState(false);
    const pageErrors = usePage<PageProps>().props.errors as Record<string, string> | undefined;

    function submit(e: FormEvent) {
        e.preventDefault();
        router.put(update.url(), { hours } as Record<string, unknown>, {
            preserveState: true,
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        });
    }

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
                        {pageErrors && Object.keys(pageErrors).length > 0 && (
                            <div className="mt-4">
                                {Object.values(pageErrors).map((error: string, i: number) => (
                                    <InputError key={i} message={error} />
                                ))}
                            </div>
                        )}
                    </CardPanel>
                    <CardFooter className="flex justify-end">
                        <Button type="submit" disabled={processing}>{t('Save changes')}</Button>
                    </CardFooter>
                </form>
            </Card>
        </SettingsLayout>
    );
}
