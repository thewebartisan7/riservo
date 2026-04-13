import OnboardingLayout from '@/layouts/onboarding-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { InputError } from '@/components/input-error';
import { WeekScheduleEditor } from '@/components/onboarding/week-schedule-editor';
import type { DaySchedule } from '@/components/onboarding/day-row';
import { useTrans } from '@/hooks/use-trans';
import { router, useHttp } from '@inertiajs/react';
import { store, show } from '@/actions/App/Http/Controllers/OnboardingController';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';

interface Props {
    hours: DaySchedule[];
}

export default function Step2({ hours: initialHours }: Props) {
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
            http.post(store.url(2), {
                onSuccess: () => {
                    router.visit(show(3));
                },
            });
        }
    }, [http.data.hours]);

    return (
        <OnboardingLayout step={2} title={t('Working Hours')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Working Hours')}</CardTitle>
                    <CardDescription>{t('Set your weekly business hours. You can add breaks by creating multiple time windows per day.')}</CardDescription>
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
                    <CardFooter className="flex justify-between">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.visit(show(1))}
                        >
                            {t('Back')}
                        </Button>
                        <Button type="submit" disabled={http.processing}>
                            {t('Continue')}
                        </Button>
                    </CardFooter>
                </form>
            </Card>
        </OnboardingLayout>
    );
}
