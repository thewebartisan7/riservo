import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, FieldLabel, FieldError, FieldDescription } from '@/components/ui/field';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Card, CardPanel, CardFooter } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import {
    NumberField,
    NumberFieldDecrement,
    NumberFieldGroup,
    NumberFieldIncrement,
    NumberFieldInput,
} from '@/components/ui/number-field';
import {
    Select,
    SelectItem,
    SelectPopup,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { SectionHeading, SectionTitle, SectionRule } from '@/components/ui/section-heading';
import { useTrans } from '@/hooks/use-trans';
import { Form } from '@inertiajs/react';
import { useState } from 'react';

interface Provider {
    id: number;
    name: string;
}

interface ServiceFormProps {
    action: { url: string; method: 'get' | 'post' | 'put' | 'patch' | 'delete' };
    service?: {
        name: string;
        description: string | null;
        duration_minutes: number;
        price: number | null;
        buffer_before: number;
        buffer_after: number;
        slot_interval_minutes: number;
        is_active: boolean;
        provider_ids: number[];
    };
    providers: Provider[];
    submitLabel: string;
}

const slotIntervalItems = [
    { label: '5 min', value: '5' },
    { label: '10 min', value: '10' },
    { label: '15 min', value: '15' },
    { label: '20 min', value: '20' },
    { label: '30 min', value: '30' },
    { label: '60 min', value: '60' },
];

export function ServiceForm({ action, service, providers, submitLabel }: ServiceFormProps) {
    const { t } = useTrans();
    const [selectedProviders, setSelectedProviders] = useState<number[]>(service?.provider_ids ?? []);
    const [isActive, setIsActive] = useState(service?.is_active ?? true);

    function toggleProvider(id: number) {
        setSelectedProviders((prev) =>
            prev.includes(id) ? prev.filter((c) => c !== id) : [...prev, id],
        );
    }

    return (
        <Form action={action}>
            {({ errors, processing }) => (
                <Card>
                    <CardPanel className="flex flex-col gap-8 p-5 sm:p-6">
                        <section className="flex flex-col gap-4">
                            <SectionHeading>
                                <SectionTitle>{t('Details')}</SectionTitle>
                                <SectionRule />
                            </SectionHeading>

                            <Field>
                                <FieldLabel>{t('Service name')}</FieldLabel>
                                <Input
                                    name="name"
                                    defaultValue={service?.name ?? ''}
                                    placeholder={t('e.g. Classic haircut')}
                                    required
                                />
                                <FieldDescription>
                                    {t('How this service appears on your booking page and in confirmations.')}
                                </FieldDescription>
                                {errors.name && <FieldError match>{errors.name}</FieldError>}
                            </Field>

                            <Field>
                                <FieldLabel>{t('Description')}</FieldLabel>
                                <Textarea
                                    name="description"
                                    defaultValue={service?.description ?? ''}
                                    rows={3}
                                    placeholder={t('What the customer gets. Keep it short and concrete.')}
                                />
                                <FieldDescription>
                                    {t('Optional. Shown alongside the price on the service list.')}
                                </FieldDescription>
                                {errors.description && <FieldError match>{errors.description}</FieldError>}
                            </Field>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field>
                                    <FieldLabel>{t('Duration')}</FieldLabel>
                                    <div className="flex items-center gap-2">
                                        <NumberField
                                            name="duration_minutes"
                                            defaultValue={service?.duration_minutes ?? 60}
                                            min={5}
                                            max={480}
                                            className="flex-1"
                                        >
                                            <NumberFieldGroup>
                                                <NumberFieldDecrement />
                                                <NumberFieldInput />
                                                <NumberFieldIncrement />
                                            </NumberFieldGroup>
                                        </NumberField>
                                        <span className="text-sm text-muted-foreground">{t('min')}</span>
                                    </div>
                                    {errors.duration_minutes && <FieldError match>{errors.duration_minutes}</FieldError>}
                                </Field>
                                <Field>
                                    <FieldLabel>{t('Price')}</FieldLabel>
                                    <div className="flex items-center gap-2">
                                        <span className="font-display text-sm text-muted-foreground">CHF</span>
                                        <Input
                                            name="price"
                                            type="number"
                                            min={0}
                                            step="0.01"
                                            defaultValue={service?.price ?? ''}
                                            placeholder={t('Leave empty for "on request"')}
                                            className="flex-1"
                                        />
                                    </div>
                                    {errors.price && <FieldError match>{errors.price}</FieldError>}
                                </Field>
                            </div>

                            <div className="flex items-start justify-between gap-4 rounded-xl border border-border/70 bg-muted/40 px-4 py-3.5">
                                <div className="flex min-w-0 flex-1 flex-col gap-1">
                                    <Label
                                        htmlFor="is_active"
                                        className="text-sm font-medium text-foreground"
                                    >
                                        {t('Visible to customers')}
                                    </Label>
                                    <p className="text-xs leading-relaxed text-muted-foreground">
                                        {t('Inactive services stay on your list but are hidden from the booking page.')}
                                    </p>
                                </div>
                                <Switch id="is_active" checked={isActive} onCheckedChange={setIsActive} />
                                <input type="hidden" name="is_active" value={isActive ? '1' : '0'} />
                            </div>
                        </section>

                        <section className="flex flex-col gap-4">
                            <SectionHeading>
                                <SectionTitle>{t('Scheduling')}</SectionTitle>
                                <SectionRule />
                            </SectionHeading>

                            <div className="grid gap-4 sm:grid-cols-3">
                                <Field>
                                    <FieldLabel>{t('Buffer before')}</FieldLabel>
                                    <NumberField name="buffer_before" defaultValue={service?.buffer_before ?? 0} min={0} max={120}>
                                        <NumberFieldGroup>
                                            <NumberFieldDecrement />
                                            <NumberFieldInput />
                                            <NumberFieldIncrement />
                                        </NumberFieldGroup>
                                    </NumberField>
                                    {errors.buffer_before && <FieldError match>{errors.buffer_before}</FieldError>}
                                </Field>
                                <Field>
                                    <FieldLabel>{t('Buffer after')}</FieldLabel>
                                    <NumberField name="buffer_after" defaultValue={service?.buffer_after ?? 0} min={0} max={120}>
                                        <NumberFieldGroup>
                                            <NumberFieldDecrement />
                                            <NumberFieldInput />
                                            <NumberFieldIncrement />
                                        </NumberFieldGroup>
                                    </NumberField>
                                    {errors.buffer_after && <FieldError match>{errors.buffer_after}</FieldError>}
                                </Field>
                                <Field>
                                    <FieldLabel>{t('Slot interval')}</FieldLabel>
                                    <Select
                                        name="slot_interval_minutes"
                                        defaultValue={String(service?.slot_interval_minutes ?? 30)}
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
                                    {errors.slot_interval_minutes && <FieldError match>{errors.slot_interval_minutes}</FieldError>}
                                </Field>
                            </div>

                            <p className="text-muted-foreground text-xs">
                                {t('Buffers keep time between bookings. Slot interval controls how often new appointments can start.')}
                            </p>
                        </section>

                        {providers.length > 0 && (
                            <section className="flex flex-col gap-4">
                                <SectionHeading>
                                    <SectionTitle>{t('Team')}</SectionTitle>
                                    <SectionRule />
                                </SectionHeading>

                                <Field>
                                    <div className="flex items-baseline justify-between gap-3">
                                        <FieldLabel>{t('Who performs this service')}</FieldLabel>
                                        <span className="font-display text-[11px] tabular-nums text-muted-foreground">
                                            {t(':n of :total selected', {
                                                n: selectedProviders.length,
                                                total: providers.length,
                                            })}
                                        </span>
                                    </div>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {providers.map((c) => {
                                            const checked = selectedProviders.includes(c.id);
                                            return (
                                                <Label
                                                    key={c.id}
                                                    className="flex items-center gap-2.5 rounded-lg border border-border/70 bg-background px-3.5 py-2.5 text-sm transition-colors hover:border-border not-has-[:checked]:hover:bg-muted/40 has-[:checked]:border-primary/40 has-[:checked]:bg-honey-soft/60"
                                                >
                                                    <Checkbox
                                                        checked={checked}
                                                        onCheckedChange={() => toggleProvider(c.id)}
                                                    />
                                                    <span className="text-foreground">{c.name}</span>
                                                </Label>
                                            );
                                        })}
                                    </div>
                                    <FieldDescription>
                                        {t('At least one provider must be assigned for the service to appear on the booking page.')}
                                    </FieldDescription>
                                    {selectedProviders.map((id) => (
                                        <input key={id} type="hidden" name="provider_ids[]" value={id} />
                                    ))}
                                    {selectedProviders.length === 0 && (
                                        <input type="hidden" name="provider_ids" value="" />
                                    )}
                                </Field>
                            </section>
                        )}
                    </CardPanel>
                    <CardFooter className="justify-end border-t bg-muted/50 px-5 py-3 sm:px-6">
                        <Button type="submit" loading={processing}>{submitLabel}</Button>
                    </CardFooter>
                </Card>
            )}
        </Form>
    );
}
