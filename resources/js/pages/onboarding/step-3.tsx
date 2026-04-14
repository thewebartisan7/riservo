import OnboardingLayout from '@/layouts/onboarding-layout';
import { Card, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { InputGroup, InputGroupAddon, InputGroupInput, InputGroupText } from '@/components/ui/input-group';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel, FieldError, FieldDescription } from '@/components/ui/field';
import { Display } from '@/components/ui/display';
import {
    NumberField,
    NumberFieldDecrement,
    NumberFieldGroup,
    NumberFieldIncrement,
    NumberFieldInput,
} from '@/components/ui/number-field';
import { useTrans } from '@/hooks/use-trans';
import { Select, SelectItem, SelectPopup, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Form } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/OnboardingController';
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

const slotIntervalItems = [
    { label: '5 min', value: '5' },
    { label: '10 min', value: '10' },
    { label: '15 min', value: '15' },
    { label: '20 min', value: '20' },
    { label: '30 min', value: '30' },
    { label: '60 min', value: '60' },
];

export default function Step3({ service }: Props) {
    const { t } = useTrans();
    const [duration, setDuration] = useState<number>(service?.duration_minutes ?? 60);
    const [bufferBefore, setBufferBefore] = useState<number>(service?.buffer_before ?? 0);
    const [bufferAfter, setBufferAfter] = useState<number>(service?.buffer_after ?? 0);
    const [priceOnRequest, setPriceOnRequest] = useState<boolean>(service?.price === null && service !== null);
    const [price, setPrice] = useState<string | number>(service?.price ?? '');
    const [advancedOpen, setAdvancedOpen] = useState<boolean>(
        (service?.buffer_before ?? 0) > 0 || (service?.buffer_after ?? 0) > 0,
    );

    return (
        <OnboardingLayout
            step={3}
            title={t('First service')}
            eyebrow={t('Your first offering')}
            heading={t('Create a bookable service')}
            description={t('Start with one — a haircut, a 30-minute consultation, a deep-tissue session. You can add variants and more services after launch.')}
        >
            <Card>
                <Form
                    action={store(3)}
                    transform={(data) => ({
                        name: data.name,
                        duration_minutes: duration,
                        price: priceOnRequest ? null : price === '' ? null : price,
                        buffer_before: bufferBefore,
                        buffer_after: bufferAfter,
                        slot_interval_minutes: data.slot_interval_minutes,
                    })}
                >
                    {({ errors, processing }) => (
                        <>
                            <CardPanel className="flex flex-col gap-5">
                                <Field>
                                    <FieldLabel>{t('Service name')}</FieldLabel>
                                    <Input
                                        name="name"
                                        defaultValue={service?.name ?? ''}
                                        required
                                        autoFocus
                                        placeholder={t('e.g. Haircut, Consultation, Massage')}
                                        aria-invalid={!!errors.name}
                                    />
                                    {errors.name && <FieldError match>{errors.name}</FieldError>}
                                </Field>

                                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                    <Field>
                                        <FieldLabel>{t('Duration')}</FieldLabel>
                                        <NumberField
                                            name="duration_minutes"
                                            value={duration}
                                            onValueChange={(v) => setDuration(v ?? 60)}
                                            min={5}
                                            max={480}
                                            step={5}
                                            aria-invalid={!!errors.duration_minutes}
                                        >
                                            <NumberFieldGroup>
                                                <NumberFieldDecrement />
                                                <NumberFieldInput />
                                                <NumberFieldIncrement />
                                            </NumberFieldGroup>
                                        </NumberField>
                                        <FieldDescription>{t('Minutes')}</FieldDescription>
                                        {errors.duration_minutes && (
                                            <FieldError match>{errors.duration_minutes}</FieldError>
                                        )}
                                    </Field>

                                    <Field>
                                        <FieldLabel className="justify-between">
                                            <span>{t('Price')}</span>
                                            <label className="inline-flex cursor-pointer items-center gap-2 text-xs font-normal text-muted-foreground">
                                                <Checkbox
                                                    checked={priceOnRequest}
                                                    onCheckedChange={(checked) => {
                                                        const val = checked === true;
                                                        setPriceOnRequest(val);
                                                        if (val) setPrice('');
                                                    }}
                                                />
                                                {t('On request')}
                                            </label>
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupAddon align="inline-start">
                                                <InputGroupText>CHF</InputGroupText>
                                            </InputGroupAddon>
                                            <InputGroupInput
                                                name="price"
                                                type="number"
                                                min={0}
                                                step="0.01"
                                                value={priceOnRequest ? '' : price}
                                                onChange={(e) => setPrice(e.target.value)}
                                                disabled={priceOnRequest}
                                                placeholder={priceOnRequest ? t('On request') : '0.00'}
                                                aria-invalid={!!errors.price}
                                            />
                                        </InputGroup>
                                        <FieldDescription>
                                            {priceOnRequest
                                                ? t('Shown as "On request" on your booking page.')
                                                : t('Leave blank to hide the price.')}
                                        </FieldDescription>
                                        {errors.price && <FieldError match>{errors.price}</FieldError>}
                                    </Field>
                                </div>

                                <Field>
                                    <FieldLabel>{t('Slot interval')}</FieldLabel>
                                    <Select
                                        name="slot_interval_minutes"
                                        defaultValue={String(service?.slot_interval_minutes ?? 15)}
                                        items={slotIntervalItems}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectPopup>
                                            {slotIntervalItems.map((item) => (
                                                <SelectItem key={item.value} value={item.value}>
                                                    {item.label}
                                                </SelectItem>
                                            ))}
                                        </SelectPopup>
                                    </Select>
                                    <FieldDescription>
                                        {t('How often a start time appears. A 60-minute service with 15-minute intervals offers 09:00, 09:15, 09:30…')}
                                    </FieldDescription>
                                    {errors.slot_interval_minutes && (
                                        <FieldError match>{errors.slot_interval_minutes}</FieldError>
                                    )}
                                </Field>

                                <div className="rounded-lg border border-border/80 bg-muted/40">
                                    <button
                                        type="button"
                                        aria-expanded={advancedOpen}
                                        onClick={() => setAdvancedOpen((s) => !s)}
                                        className="flex w-full items-center justify-between gap-2 px-4 py-3 text-left text-sm font-medium text-foreground transition-colors hover:bg-muted/60"
                                    >
                                        <span className="flex items-center gap-2">
                                            <span className="text-[10px] uppercase tracking-[0.22em] text-muted-foreground">
                                                {t('Optional')}
                                            </span>
                                            <span>{t('Buffer times')}</span>
                                        </span>
                                        <span
                                            aria-hidden="true"
                                            className={`text-xs text-muted-foreground transition-transform ${advancedOpen ? 'rotate-180' : ''}`}
                                        >
                                            ⌄
                                        </span>
                                    </button>
                                    {advancedOpen && (
                                        <div className="border-t border-border/80 px-4 py-4">
                                            <p className="mb-3 text-xs leading-relaxed text-muted-foreground">
                                                {t('Protected time before and after each appointment — for setup, travel, or a breath. Bookings won\'t be offered that overlap these buffers.')}
                                            </p>
                                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                                <Field>
                                                    <FieldLabel>{t('Before')}</FieldLabel>
                                                    <NumberField
                                                        name="buffer_before"
                                                        value={bufferBefore}
                                                        onValueChange={(v) => setBufferBefore(v ?? 0)}
                                                        min={0}
                                                        max={120}
                                                        step={5}
                                                    >
                                                        <NumberFieldGroup>
                                                            <NumberFieldDecrement />
                                                            <NumberFieldInput />
                                                            <NumberFieldIncrement />
                                                        </NumberFieldGroup>
                                                    </NumberField>
                                                    <FieldDescription>{t('Minutes of setup')}</FieldDescription>
                                                    {errors.buffer_before && (
                                                        <FieldError match>{errors.buffer_before}</FieldError>
                                                    )}
                                                </Field>
                                                <Field>
                                                    <FieldLabel>{t('After')}</FieldLabel>
                                                    <NumberField
                                                        name="buffer_after"
                                                        value={bufferAfter}
                                                        onValueChange={(v) => setBufferAfter(v ?? 0)}
                                                        min={0}
                                                        max={120}
                                                        step={5}
                                                    >
                                                        <NumberFieldGroup>
                                                            <NumberFieldDecrement />
                                                            <NumberFieldInput />
                                                            <NumberFieldIncrement />
                                                        </NumberFieldGroup>
                                                    </NumberField>
                                                    <FieldDescription>{t('Minutes of cleanup')}</FieldDescription>
                                                    {errors.buffer_after && (
                                                        <FieldError match>{errors.buffer_after}</FieldError>
                                                    )}
                                                </Field>
                                            </div>
                                        </div>
                                    )}
                                </div>
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
                                        {t('Continue to team')}
                                    </Display>
                                </Button>
                            </CardFooter>
                        </>
                    )}
                </Form>
            </Card>
        </OnboardingLayout>
    );
}
