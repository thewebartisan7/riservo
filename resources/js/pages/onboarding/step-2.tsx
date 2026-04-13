import OnboardingLayout from '@/layouts/onboarding-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { InputError } from '@/components/input-error';
import { WeekScheduleEditor } from '@/components/onboarding/week-schedule-editor';
import type { DaySchedule } from '@/components/onboarding/day-row';
import { useTrans } from '@/hooks/use-trans';
import { router } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

interface Props {
    hours: DaySchedule[];
}

export default function Step2({ hours: initialHours }: Props) {
    const { t } = useTrans();
    const [hours, setHours] = useState<DaySchedule[]>(initialHours);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    function submit(e: FormEvent) {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        // Use fetch for complex nested data to avoid Inertia v2 type constraints
        const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

        fetch('/onboarding/step/2', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Inertia': 'true',
                'X-Inertia-Version': document.querySelector<HTMLMetaElement>('meta[name="inertia-version"]')?.content ?? '',
                Accept: 'text/html, application/xhtml+xml',
            },
            body: JSON.stringify({ hours }),
        }).then((response) => {
            if (response.status === 422) {
                return response.json().then((data) => {
                    setErrors(data.errors ?? {});
                    setProcessing(false);
                });
            }
            // Inertia redirect response — reload via router visit
            router.visit('/onboarding/step/3');
        }).catch(() => {
            setProcessing(false);
        });
    }

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
                        {Object.keys(errors).length > 0 && (
                            <div className="mt-4">
                                {Object.values(errors).map((error, i) => (
                                    <InputError key={i} message={error} />
                                ))}
                            </div>
                        )}
                    </CardPanel>
                    <CardFooter className="flex justify-between">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.visit('/onboarding/step/1')}
                        >
                            {t('Back')}
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {t('Continue')}
                        </Button>
                    </CardFooter>
                </form>
            </Card>
        </OnboardingLayout>
    );
}
