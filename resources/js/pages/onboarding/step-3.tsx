import OnboardingLayout from '@/layouts/onboarding-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { InputError } from '@/components/input-error';
import { useTrans } from '@/hooks/use-trans';
import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

interface Props {
    service: {
        id: number;
        name: string;
        duration_minutes: number;
        price: number | null;
        buffer_before: number;
        buffer_after: number;
        slot_interval_minutes: number;
    } | null;
}

export default function Step3({ service }: Props) {
    const { t } = useTrans();

    const form = useForm({
        name: service?.name ?? '',
        duration_minutes: service?.duration_minutes ?? 60,
        price: service?.price ?? '',
        price_on_request: service?.price === null && service !== null,
        buffer_before: service?.buffer_before ?? 0,
        buffer_after: service?.buffer_after ?? 0,
        slot_interval_minutes: service?.slot_interval_minutes ?? 15,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((data) => ({
            name: data.name,
            duration_minutes: data.duration_minutes,
            price: data.price_on_request ? null : (data.price === '' ? null : data.price),
            buffer_before: data.buffer_before,
            buffer_after: data.buffer_after,
            slot_interval_minutes: data.slot_interval_minutes,
        }));
        form.post('/onboarding/step/3');
    }

    return (
        <OnboardingLayout step={3} title={t('First Service')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('First Service')}</CardTitle>
                    <CardDescription>{t('Create your first bookable service. You can add more later.')}</CardDescription>
                </CardHeader>
                <form onSubmit={submit}>
                    <CardPanel className="flex flex-col gap-4">
                        <div className="flex flex-col gap-2">
                            <label htmlFor="name" className="text-sm font-medium">{t('Service name')}</label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                required
                                autoFocus
                                placeholder={t('e.g. Haircut, Consultation, Massage')}
                            />
                            <InputError message={form.errors.name} />
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="flex flex-col gap-2">
                                <label htmlFor="duration" className="text-sm font-medium">{t('Duration (minutes)')}</label>
                                <Input
                                    id="duration"
                                    type="number"
                                    min={5}
                                    max={480}
                                    step={5}
                                    value={form.data.duration_minutes}
                                    onChange={(e) => form.setData('duration_minutes', Number(e.target.value))}
                                    required
                                />
                                <InputError message={form.errors.duration_minutes} />
                            </div>

                            <div className="flex flex-col gap-2">
                                <label htmlFor="price" className="text-sm font-medium">{t('Price (CHF)')}</label>
                                <Input
                                    id="price"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={form.data.price_on_request ? '' : form.data.price}
                                    onChange={(e) => form.setData('price', e.target.value)}
                                    disabled={form.data.price_on_request}
                                    placeholder={form.data.price_on_request ? t('On request') : '0.00'}
                                />
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.data.price_on_request}
                                        onChange={(e) => {
                                            form.setData('price_on_request', e.target.checked);
                                            if (e.target.checked) form.setData('price', '');
                                        }}
                                        className="rounded"
                                    />
                                    {t('Price on request')}
                                </label>
                                <InputError message={form.errors.price} />
                            </div>
                        </div>

                        <div className="flex flex-col gap-2">
                            <label htmlFor="slot_interval" className="text-sm font-medium">{t('Slot interval (minutes)')}</label>
                            <p className="text-xs text-muted-foreground">
                                {t('How often start times are offered. A 60-min service with a 15-min interval offers slots at 09:00, 09:15, 09:30, etc.')}
                            </p>
                            <select
                                id="slot_interval"
                                value={form.data.slot_interval_minutes}
                                onChange={(e) => form.setData('slot_interval_minutes', Number(e.target.value))}
                                className="h-9 rounded-lg border border-input bg-background px-3 text-sm shadow-xs sm:h-8"
                            >
                                {[5, 10, 15, 20, 30, 60].map((val) => (
                                    <option key={val} value={val}>
                                        {val} {t('minutes')}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.slot_interval_minutes} />
                        </div>

                        <details className="group">
                            <summary className="cursor-pointer text-sm font-medium text-muted-foreground hover:text-foreground">
                                {t('Advanced: Buffer times')}
                            </summary>
                            <div className="mt-3 grid grid-cols-2 gap-4">
                                <div className="flex flex-col gap-2">
                                    <label htmlFor="buffer_before" className="text-sm font-medium">{t('Buffer before (min)')}</label>
                                    <Input
                                        id="buffer_before"
                                        type="number"
                                        min={0}
                                        max={120}
                                        step={5}
                                        value={form.data.buffer_before}
                                        onChange={(e) => form.setData('buffer_before', Number(e.target.value))}
                                    />
                                    <p className="text-xs text-muted-foreground">{t('Setup time before the appointment')}</p>
                                    <InputError message={form.errors.buffer_before} />
                                </div>
                                <div className="flex flex-col gap-2">
                                    <label htmlFor="buffer_after" className="text-sm font-medium">{t('Buffer after (min)')}</label>
                                    <Input
                                        id="buffer_after"
                                        type="number"
                                        min={0}
                                        max={120}
                                        step={5}
                                        value={form.data.buffer_after}
                                        onChange={(e) => form.setData('buffer_after', Number(e.target.value))}
                                    />
                                    <p className="text-xs text-muted-foreground">{t('Cleanup time after the appointment')}</p>
                                    <InputError message={form.errors.buffer_after} />
                                </div>
                            </div>
                        </details>
                    </CardPanel>
                    <CardFooter className="flex justify-between">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.visit('/onboarding/step/2')}
                        >
                            {t('Back')}
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {t('Continue')}
                        </Button>
                    </CardFooter>
                </form>
            </Card>
        </OnboardingLayout>
    );
}
