import OnboardingLayout from '@/layouts/onboarding-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, FieldLabel, FieldError, FieldDescription } from '@/components/ui/field';
import { useTrans } from '@/hooks/use-trans';
import { Select, SelectItem, SelectPopup, SelectTrigger, SelectValue } from '@/components/ui/select';
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
                                <Field>
                                    <FieldLabel>{t('Service name')}</FieldLabel>
                                    <Input
                                        name="name"
                                        defaultValue={service?.name ?? ''}
                                        required
                                        autoFocus
                                        placeholder={t('e.g. Haircut, Consultation, Massage')}
                                    />
                                    {errors.name && <FieldError match>{errors.name}</FieldError>}
                                </Field>

                                <div className="grid grid-cols-2 gap-4">
                                    <Field>
                                        <FieldLabel>{t('Duration (minutes)')}</FieldLabel>
                                        <Input
                                            name="duration_minutes"
                                            type="number"
                                            min={5}
                                            max={480}
                                            step={5}
                                            defaultValue={service?.duration_minutes ?? 60}
                                            required
                                        />
                                        {errors.duration_minutes && <FieldError match>{errors.duration_minutes}</FieldError>}
                                    </Field>

                                    <Field>
                                        <FieldLabel>{t('Price (CHF)')}</FieldLabel>
                                        <Input
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
                                        {errors.price && <FieldError match>{errors.price}</FieldError>}
                                    </Field>
                                </div>

                                <Field>
                                    <FieldLabel>{t('Slot interval (minutes)')}</FieldLabel>
                                    <FieldDescription>
                                        {t('How often start times are offered. A 60-min service with a 15-min interval offers slots at 09:00, 09:15, 09:30, etc.')}
                                    </FieldDescription>
                                    <Select name="slot_interval_minutes" defaultValue={String(service?.slot_interval_minutes ?? 15)}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectPopup>
                                            {[5, 10, 15, 20, 30, 60].map((val) => (
                                                <SelectItem key={val} value={String(val)}>
                                                    {val} {t('minutes')}
                                                </SelectItem>
                                            ))}
                                        </SelectPopup>
                                    </Select>
                                    {errors.slot_interval_minutes && <FieldError match>{errors.slot_interval_minutes}</FieldError>}
                                </Field>

                                <details className="group">
                                    <summary className="cursor-pointer text-sm font-medium text-muted-foreground hover:text-foreground">
                                        {t('Advanced: Buffer times')}
                                    </summary>
                                    <div className="mt-3 grid grid-cols-2 gap-4">
                                        <Field>
                                            <FieldLabel>{t('Buffer before (min)')}</FieldLabel>
                                            <Input
                                                name="buffer_before"
                                                type="number"
                                                min={0}
                                                max={120}
                                                step={5}
                                                defaultValue={service?.buffer_before ?? 0}
                                            />
                                            <FieldDescription>{t('Setup time before the appointment')}</FieldDescription>
                                            {errors.buffer_before && <FieldError match>{errors.buffer_before}</FieldError>}
                                        </Field>
                                        <Field>
                                            <FieldLabel>{t('Buffer after (min)')}</FieldLabel>
                                            <Input
                                                name="buffer_after"
                                                type="number"
                                                min={0}
                                                max={120}
                                                step={5}
                                                defaultValue={service?.buffer_after ?? 0}
                                            />
                                            <FieldDescription>{t('Cleanup time after the appointment')}</FieldDescription>
                                            {errors.buffer_after && <FieldError match>{errors.buffer_after}</FieldError>}
                                        </Field>
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
