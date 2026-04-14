import OnboardingLayout from '@/layouts/onboarding-layout';
import { Card, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Display } from '@/components/ui/display';
import { WeekScheduleEditor } from '@/components/onboarding/week-schedule-editor';
import type { DaySchedule } from '@/components/onboarding/day-row';
import { useTrans } from '@/hooks/use-trans';
import { router, usePage } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/OnboardingController';
import type { FormEvent } from 'react';
import { useState } from 'react';
import type { PageProps } from '@/types';

interface Props {
    hours: DaySchedule[];
}

export default function Step2({ hours: initialHours }: Props) {
    const { t } = useTrans();
    const [hours, setHours] = useState<DaySchedule[]>(initialHours);
    const [processing, setProcessing] = useState(false);
    const pageErrors = usePage<PageProps>().props.errors as Record<string, string> | undefined;

    function submit(e: FormEvent) {
        e.preventDefault();
        // TODO fix TS2345: Argument of type Record<string, unknown> is not assignable to parameter of type RequestPayload | undefined
        router.post(store.url(2), { hours } as Record<string, unknown>, {
            preserveState: true,
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        });
    }

    const hasErrors = pageErrors && Object.keys(pageErrors).length > 0;

    return (
        <OnboardingLayout
            step={2}
            title={t('Working hours')}
            eyebrow={t('Your weekly rhythm')}
            heading={t('When are you open?')}
            description={t('Toggle each day on or off. Add a second window for lunch breaks or split shifts — your booking page will only show times inside these ranges.')}
        >
            <Card>
                <form onSubmit={submit}>
                    <CardPanel className="px-4 sm:px-6">
                        <WeekScheduleEditor hours={hours} onChange={setHours} />
                        {hasErrors && (
                            <div className="mt-5 rounded-lg border border-primary/32 bg-honey-soft/40 px-4 py-3">
                                <p className="text-xs font-medium uppercase tracking-widest text-primary">
                                    {t('Please review these hours')}
                                </p>
                                <ul className="mt-2 flex flex-col gap-1 text-xs text-foreground">
                                    {Object.values(pageErrors!).map((error: string, i: number) => (
                                        <li key={i}>{error}</li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </CardPanel>
                    <CardFooter>
                        <Button
                            type="submit"
                            size="xl"
                            loading={processing}
                            disabled={processing}
                            className="h-12 w-full text-sm sm:h-12"
                        >
                            <Display className="tracking-tight">
                                {t('Continue to services')}
                            </Display>
                        </Button>
                    </CardFooter>
                </form>
            </Card>
        </OnboardingLayout>
    );
}
