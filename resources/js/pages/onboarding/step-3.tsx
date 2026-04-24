import OnboardingLayout from '@/layouts/onboarding-layout';
import { Card, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { InputGroup, InputGroupAddon, InputGroupInput, InputGroupText } from '@/components/ui/input-group';
import { Checkbox } from '@/components/ui/checkbox';
import { Switch } from '@/components/ui/switch';
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
import { Form, Link, router, usePage } from '@inertiajs/react';
import type { FormDataConvertible } from '@inertiajs/core';
import { store, enableOwnerAsProvider } from '@/actions/App/Http/Controllers/OnboardingController';
import { WeekScheduleEditor } from '@/components/onboarding/week-schedule-editor';
import type { DaySchedule } from '@/components/onboarding/day-row';
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
    adminProvider: {
        exists: boolean;
        schedule: DaySchedule[];
        serviceIds: number[];
    };
    businessHoursSchedule: DaySchedule[];
    hasOtherProviders: boolean;
}

interface LaunchBlockedFlash {
    services: Array<{ id: number; name: string }>;
}

const slotIntervalItems = [
    { label: '5 min', value: '5' },
    { label: '10 min', value: '10' },
    { label: '15 min', value: '15' },
    { label: '20 min', value: '20' },
    { label: '30 min', value: '30' },
    { label: '60 min', value: '60' },
];

export default function Step3({ service, adminProvider, businessHoursSchedule, hasOtherProviders }: Props) {
    const { t } = useTrans();
    const page = usePage<{ launchBlocked?: LaunchBlockedFlash }>();
    const launchBlocked = page.props.launchBlocked ?? null;

    const initialAttached = service ? adminProvider.serviceIds.includes(service.id) : adminProvider.exists;

    const [duration, setDuration] = useState<number>(service?.duration_minutes ?? 60);
    const [bufferBefore, setBufferBefore] = useState<number>(service?.buffer_before ?? 0);
    const [bufferAfter, setBufferAfter] = useState<number>(service?.buffer_after ?? 0);
    const [priceOnRequest, setPriceOnRequest] = useState<boolean>(service?.price === null && service !== null);
    const [price, setPrice] = useState<string | number>(service?.price ?? '');
    const [advancedOpen, setAdvancedOpen] = useState<boolean>(
        (service?.buffer_before ?? 0) > 0 || (service?.buffer_after ?? 0) > 0,
    );
    const [providerOptIn, setProviderOptIn] = useState<boolean>(initialAttached);
    const [providerSchedule, setProviderSchedule] = useState<DaySchedule[]>(adminProvider.schedule);
    const [enablingOwner, setEnablingOwner] = useState(false);

    const optInLabel = hasOtherProviders
        ? t('I also take bookings for this service')
        : t('I take bookings for this service myself');

    function resetToBusinessHours() {
        setProviderSchedule(businessHoursSchedule);
    }

    function handleEnableOwner() {
        setEnablingOwner(true);
        router.post(
            enableOwnerAsProvider().url,
            {},
            {
                onFinish: () => setEnablingOwner(false),
            },
        );
    }

    return (
        <OnboardingLayout
            step={3}
            title={t('First service')}
            eyebrow={t('Your first offering')}
            heading={t('Create a bookable service')}
            description={t('Start with one — a haircut, a 30-minute consultation, a deep-tissue session. You can add variants and more services after launch.')}
        >
            {launchBlocked && launchBlocked.services.length > 0 && (
                <div
                    role="alert"
                    className="mb-6 flex flex-col gap-3 rounded-xl border border-destructive/40 bg-destructive/5 px-5 py-4"
                >
                    <div className="flex flex-col gap-1">
                        <p className="text-[10px] uppercase tracking-[0.22em] text-destructive">
                            {t('Almost there')}
                        </p>
                        <Display className="text-sm font-medium text-foreground">
                            {t('You need at least one person behind every active service before you can launch.')}
                        </Display>
                        <p className="text-xs text-muted-foreground">
                            {t('Unstaffed services:')}{' '}
                            {launchBlocked.services.map((s) => s.name).join(', ')}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            size="sm"
                            onClick={handleEnableOwner}
                            loading={enablingOwner}
                            disabled={enablingOwner}
                        >
                            {t('Be your own first provider')}
                        </Button>
                        <Link href="/onboarding/step/4">
                            <Button type="button" size="sm" variant="outline">
                                {t('Invite a provider instead')}
                            </Button>
                        </Link>
                    </div>
                </div>
            )}

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
                        provider_opt_in: providerOptIn,
                        // DaySchedule is a closed interface so TS can't verify its
                        // fields satisfy FormDataConvertible's recursive shape.
                        // Runtime-wise these are plain serialisable objects — the
                        // backend already consumes them as JSON via the onboarding
                        // step-3 controller. Cast explicitly so the Form.transform
                        // contract compiles without loosening DaySchedule itself.
                        provider_schedule: (providerOptIn ? providerSchedule : null) as FormDataConvertible,
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

                                <div className="rounded-lg border border-border/80 bg-muted/40">
                                    <label className="flex cursor-pointer items-start justify-between gap-3 px-4 py-3">
                                        <span className="flex flex-col gap-0.5">
                                            <span className="text-sm font-medium text-foreground">
                                                {optInLabel}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {t('Adds you as a bookable provider. You can change this later in Settings → Account.')}
                                            </span>
                                        </span>
                                        <Switch
                                            checked={providerOptIn}
                                            onCheckedChange={(checked) => setProviderOptIn(checked === true)}
                                        />
                                    </label>
                                    {providerOptIn && (
                                        <div className="border-t border-border/80 px-4 py-4">
                                            <div className="mb-4 flex items-center justify-between gap-3">
                                                <p className="text-xs leading-relaxed text-muted-foreground">
                                                    {t('Your bookable hours for this service. Defaults to your business hours.')}
                                                </p>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="xs"
                                                    onClick={resetToBusinessHours}
                                                    className="text-muted-foreground hover:text-foreground"
                                                >
                                                    {t('Match business hours')}
                                                </Button>
                                            </div>
                                            <WeekScheduleEditor
                                                hours={providerSchedule}
                                                onChange={setProviderSchedule}
                                            />
                                            {errors.provider_schedule && (
                                                <p className="mt-3 text-xs text-destructive">
                                                    {errors.provider_schedule}
                                                </p>
                                            )}
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
