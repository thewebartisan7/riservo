import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
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
    const errorEntries = pageErrors ? Object.values(pageErrors) : [];

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
        <SettingsLayout
            title={t('Working Hours')}
            eyebrow={t('Settings · Schedule')}
            heading={t('Working hours')}
            description={t(
                'When your business is open by default. Add multiple windows per day to carve out a break.',
            )}
        >
            <form onSubmit={submit}>
                <Card>
                    <CardPanel className="p-5 sm:p-6">
                        <WeekScheduleEditor hours={hours} onChange={setHours} />
                        {errorEntries.length > 0 && (
                            <ul className="mt-5 flex flex-col gap-1 rounded-lg border border-primary/20 bg-honey-soft/60 px-4 py-3">
                                {errorEntries.map((error, i) => (
                                    <li key={i} className="text-xs text-primary">
                                        {error}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardPanel>
                    <CardFooter className="justify-end border-t bg-muted/50 px-5 py-3 sm:px-6">
                        <Button type="submit" loading={processing}>
                            {t('Save changes')}
                        </Button>
                    </CardFooter>
                </Card>
            </form>
        </SettingsLayout>
    );
}
