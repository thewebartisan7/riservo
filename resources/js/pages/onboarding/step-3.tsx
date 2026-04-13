import OnboardingLayout from '@/layouts/onboarding-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { InputError } from '@/components/input-error';
import { useTrans } from '@/hooks/use-trans';
import { Form, router } from '@inertiajs/react';
import { store, show } from '@/actions/App/Http/Controllers/OnboardingController';
import { useState } from 'react';

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
    const [priceOnRequest, setPriceOnRequest] = useState(service?.price === null && service !== null);
    const [price, setPrice] = useState<string | number>(service?.price ?? '');

    return (
        <OnboardingLayout step={3} title={t('First Service')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('First Service')}</CardTitle>
                    <CardDescription>{t('Create your first bookable service. You can add more later.')}</CardDescription>
                </CardHeader>
                <Form
                    action={store(3)}
                    transform={(data) => ({
                        name: data.name,
                        duration_minutes: data.duration_minutes,
                        price: priceOnRequest ? null : (price === '' ? null : price),
                        buffer_before: data.buffer_before,
                        buffer_after: data.buffer_after,
                        slot_interval_minutes: data.slot_interval_minutes,
                    })}
                >
                    {({ errors, processing }) => (
                        <>
                            <CardPanel className="flex flex-col gap-4">
                                <div className="flex flex-col gap-2">
                                    <label htmlFor="name" className="text-sm font-medium">{t('Service name')}</label>
                                    <Input
                                        id="name"
                                        name="name"
                                        defaultValue={service?.name ?? ''}
                                        required
                                        autoFocus
                                        placeholder={t('e.g. Haircut, Consultation, Massage')}
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div className="flex flex-col gap-2">
                                        <label htmlFor="duration" className="text-sm font-medium">{t('Duration (minutes)')}</label>
                                        <Input
                                            id="duration"
                                            name="duration_minutes"
                                            type="number"
                                            min={5}
                                            max={480}
                                            step={5}
                                            defaultValue={service?.duration_minutes ?? 60}
                                            required
                                        />
                                        <InputError message={errors.duration_minutes} />
                                    </div>

                                    <div className="flex flex-col gap-2">
                                        <label htmlFor="price" className="text-sm font-medium">{t('Price (CHF)')}</label>
                                        <Input
                                            id="price"
                                            name="price"
                                            type="number"
                                            min={0}
                                            step="0.01"
                                            value={priceOnRequest ? '' : price}
                                            onChange={(e) => setPrice(e.target.value)}
                                            disabled={priceOnRequest}
                                            placeholder={priceOnRequest ? t('On request') : '0.00'}
                                        />
                                        <label className="flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={priceOnRequest}
                                                onChange={(e) => {
                                                    setPriceOnRequest(e.target.checked);
                                                    if (e.target.checked) setPrice('');
                                                }}
                                                className="rounded"
                                            />
                                            {t('Price on request')}
                                        </label>
                                        <InputError message={errors.price} />
                                    </div>
                                </div>

                                <div className="flex flex-col gap-2">
                                    <label htmlFor="slot_interval" className="text-sm font-medium">{t('Slot interval (minutes)')}</label>
                                    <p className="text-xs text-muted-foreground">
                                        {t('How often start times are offered. A 60-min service with a 15-min interval offers slots at 09:00, 09:15, 09:30, etc.')}
                                    </p>
                                    <select
                                        id="slot_interval"
                                        name="slot_interval_minutes"
                                        defaultValue={service?.slot_interval_minutes ?? 15}
                                        className="h-9 rounded-lg border border-input bg-background px-3 text-sm shadow-xs sm:h-8"
                                    >
                                        {[5, 10, 15, 20, 30, 60].map((val) => (
                                            <option key={val} value={val}>
                                                {val} {t('minutes')}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.slot_interval_minutes} />
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
                                                name="buffer_before"
                                                type="number"
                                                min={0}
                                                max={120}
                                                step={5}
                                                defaultValue={service?.buffer_before ?? 0}
                                            />
                                            <p className="text-xs text-muted-foreground">{t('Setup time before the appointment')}</p>
                                            <InputError message={errors.buffer_before} />
                                        </div>
                                        <div className="flex flex-col gap-2">
                                            <label htmlFor="buffer_after" className="text-sm font-medium">{t('Buffer after (min)')}</label>
                                            <Input
                                                id="buffer_after"
                                                name="buffer_after"
                                                type="number"
                                                min={0}
                                                max={120}
                                                step={5}
                                                defaultValue={service?.buffer_after ?? 0}
                                            />
                                            <p className="text-xs text-muted-foreground">{t('Cleanup time after the appointment')}</p>
                                            <InputError message={errors.buffer_after} />
                                        </div>
                                    </div>
                                </details>
                            </CardPanel>
                            <CardFooter className="flex justify-between">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => router.visit(show(2))}
                                >
                                    {t('Back')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {t('Continue')}
                                </Button>
                            </CardFooter>
                        </>
                    )}
                </Form>
            </Card>
        </OnboardingLayout>
    );
}
