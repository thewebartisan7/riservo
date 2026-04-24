import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Field, FieldLabel, FieldError, FieldDescription } from '@/components/ui/field';
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
import { Switch } from '@/components/ui/switch';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { SectionHeading, SectionTitle, SectionRule } from '@/components/ui/section-heading';
import { useTrans } from '@/hooks/use-trans';
import { Form } from '@inertiajs/react';
import { update } from '@/actions/App/Http/Controllers/Dashboard/Settings/BookingSettingsController';
import { useState } from 'react';

type PaymentModeValue = 'offline' | 'online' | 'customer_choice';
type ConfirmationModeValue = 'auto' | 'manual';

interface PaymentEligibility {
    has_verified_account: boolean;
    country_supported: boolean;
    can_accept_online_payments: boolean;
    connected_account_country: string | null;
    supported_countries: string[];
}

interface Props {
    settings: {
        confirmation_mode: ConfirmationModeValue;
        allow_provider_choice: boolean;
        cancellation_window_hours: number;
        payment_mode: PaymentModeValue;
        assignment_strategy: string;
        reminder_hours: number[];
    };
    paymentEligibility: PaymentEligibility;
}

export default function BookingSettings({ settings, paymentEligibility }: Props) {
    const { t } = useTrans();
    const [providerChoice, setProviderChoice] = useState(settings.allow_provider_choice);

    // PAYMENTS Session 5: the confirmation_mode × payment_mode combination
    // drives the locked-decision-#29 inline hint. Both need to be state so
    // the hint re-renders live as the admin edits either control.
    const [confirmationMode, setConfirmationMode] = useState<ConfirmationModeValue>(
        settings.confirmation_mode,
    );
    const [paymentMode, setPaymentMode] = useState<PaymentModeValue>(settings.payment_mode);

    const confirmationModeItems: { value: ConfirmationModeValue; label: string }[] = [
        { value: 'auto', label: t('Auto-confirm') },
        { value: 'manual', label: t('Manual confirmation') },
    ];
    const assignmentStrategyItems = [
        { value: 'first_available', label: t('First available') },
        { value: 'round_robin', label: t('Round robin (least busy)') },
    ];

    // PAYMENTS Session 5: priority-ordered tooltip copy for disabled options.
    // Priority: not connected → non-supported country → reserved future gates.
    // The `can_accept_online_payments` aggregate is the authoritative final
    // bit; the other two flags select the correct explanation.
    //
    // Note on CH-centric copy (locked roadmap decision #43): the non-CH
    // reason below is DELIBERATELY CH-specific for MVP. D-43 keeps the
    // `supported_countries` config as the gate STATE but marks copy / UX
    // / tax assumptions as CH-centric; the fast-follow roadmap does a
    // "locale-list audit" when the config extends. Do not rewrite this
    // to render the config list dynamically — YAGNI for MVP (config is
    // `['CH']`) and contradicts D-43's locale-audit contract.
    const disabledReason = (): string | null => {
        if (!paymentEligibility.has_verified_account) {
            return t('Connect Stripe and finish onboarding to enable online payments.');
        }
        if (!paymentEligibility.country_supported) {
            return t('Online payments in MVP support CH-located businesses only.');
        }
        return null;
    };

    const nonOfflineDisabled = !paymentEligibility.can_accept_online_payments;
    const nonOfflineTooltip = nonOfflineDisabled ? disabledReason() : null;

    const paymentModeItems: {
        value: PaymentModeValue;
        label: string;
        disabled: boolean;
        reason: string | null;
    }[] = [
        {
            value: 'offline',
            label: t('Customers pay on-site'),
            disabled: false,
            reason: null,
        },
        {
            value: 'online',
            label: t('Customers pay when booking'),
            disabled: nonOfflineDisabled,
            reason: nonOfflineTooltip,
        },
        {
            value: 'customer_choice',
            label: t('Customers choose at checkout'),
            disabled: nonOfflineDisabled,
            reason: nonOfflineTooltip,
        },
    ];

    // Locked decision #29: when confirmation is manual AND payment is
    // online / customer_choice, the customer is charged at booking and an
    // admin rejection triggers an automatic full refund. Surface the
    // contract inline so the admin sees the consequence of the combination.
    const showManualOnlineHint =
        confirmationMode === 'manual'
        && (paymentMode === 'online' || paymentMode === 'customer_choice');

    return (
        <SettingsLayout
            title={t('Booking Settings')}
            eyebrow={t('Settings · Business')}
            heading={t('Booking rules')}
            description={t(
                'Decide how customers book, who they book with, and when they can change plans.',
            )}
        >
            <Form action={update()}>
                {({ errors, processing }) => (
                    <Card>
                        <CardPanel className="flex flex-col gap-8 p-5 sm:p-6">
                            <section className="flex flex-col gap-4">
                                <SectionHeading>
                                    <SectionTitle>{t('Confirmation')}</SectionTitle>
                                    <SectionRule />
                                </SectionHeading>

                                <Field>
                                    <FieldLabel>{t('Confirmation mode')}</FieldLabel>
                                    <Select
                                        name="confirmation_mode"
                                        defaultValue={settings.confirmation_mode}
                                        items={confirmationModeItems}
                                        onValueChange={(value) =>
                                            setConfirmationMode(value as ConfirmationModeValue)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectPopup>
                                            {confirmationModeItems.map((item) => (
                                                <SelectItem key={item.value} value={item.value}>
                                                    {item.label}
                                                </SelectItem>
                                            ))}
                                        </SelectPopup>
                                    </Select>
                                    <FieldDescription>
                                        {t('Auto-confirm books the slot immediately. Manual waits for your approval before it goes on the calendar.')}
                                    </FieldDescription>
                                    {errors.confirmation_mode && <FieldError match>{errors.confirmation_mode}</FieldError>}
                                </Field>
                            </section>

                            <section className="flex flex-col gap-4">
                                <SectionHeading>
                                    <SectionTitle>{t('Team')}</SectionTitle>
                                    <SectionRule />
                                </SectionHeading>

                                <div className="flex items-start justify-between gap-4 rounded-xl border border-border/70 bg-muted/40 px-4 py-3.5">
                                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                                        <Label
                                            htmlFor="allow_provider_choice"
                                            className="text-sm font-medium text-foreground"
                                        >
                                            {t('Customers can pick a provider')}
                                        </Label>
                                        <p className="text-xs leading-relaxed text-muted-foreground">
                                            {t('Show a provider picker during booking so customers can request a favourite.')}
                                        </p>
                                    </div>
                                    <Switch
                                        id="allow_provider_choice"
                                        checked={providerChoice}
                                        onCheckedChange={setProviderChoice}
                                    />
                                    <input type="hidden" name="allow_provider_choice" value={providerChoice ? '1' : '0'} />
                                </div>

                                <Field>
                                    <FieldLabel>{t('Assignment strategy')}</FieldLabel>
                                    <Select
                                        name="assignment_strategy"
                                        defaultValue={settings.assignment_strategy}
                                        items={assignmentStrategyItems}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectPopup>
                                            {assignmentStrategyItems.map((item) => (
                                                <SelectItem key={item.value} value={item.value}>
                                                    {item.label}
                                                </SelectItem>
                                            ))}
                                        </SelectPopup>
                                    </Select>
                                    <FieldDescription>
                                        {t('Used when the customer does not choose a provider.')}
                                    </FieldDescription>
                                    {errors.assignment_strategy && <FieldError match>{errors.assignment_strategy}</FieldError>}
                                </Field>
                            </section>

                            <section className="flex flex-col gap-4">
                                <SectionHeading>
                                    <SectionTitle>{t('Timing')}</SectionTitle>
                                    <SectionRule />
                                </SectionHeading>

                                <Field>
                                    <FieldLabel>{t('Cancellation window')}</FieldLabel>
                                    <div className="flex items-center gap-3">
                                        <NumberField
                                            name="cancellation_window_hours"
                                            defaultValue={settings.cancellation_window_hours}
                                            min={0}
                                            max={168}
                                            className="w-36"
                                        >
                                            <NumberFieldGroup>
                                                <NumberFieldDecrement />
                                                <NumberFieldInput />
                                                <NumberFieldIncrement />
                                            </NumberFieldGroup>
                                        </NumberField>
                                        <span className="text-sm text-muted-foreground">{t('hours before the appointment')}</span>
                                    </div>
                                    <FieldDescription>
                                        {t('How much notice customers need to cancel on their own. Set to 0 to let them cancel any time.')}
                                    </FieldDescription>
                                    {errors.cancellation_window_hours && <FieldError match>{errors.cancellation_window_hours}</FieldError>}
                                </Field>
                            </section>

                            <section className="flex flex-col gap-4">
                                <SectionHeading>
                                    <SectionTitle>{t('Payment')}</SectionTitle>
                                    <SectionRule />
                                </SectionHeading>

                                <Field>
                                    <FieldLabel>{t('Payment mode')}</FieldLabel>
                                    <Select
                                        name="payment_mode"
                                        defaultValue={settings.payment_mode}
                                        items={paymentModeItems}
                                        onValueChange={(value) =>
                                            setPaymentMode(value as PaymentModeValue)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectPopup>
                                            {paymentModeItems.map((item) => (
                                                <SelectItem
                                                    key={item.value}
                                                    value={item.value}
                                                    disabled={item.disabled}
                                                    title={item.reason ?? undefined}
                                                >
                                                    <span className="flex flex-col gap-0.5">
                                                        <span>{item.label}</span>
                                                        {item.disabled && item.reason && (
                                                            <span className="text-xs text-muted-foreground">
                                                                {item.reason}
                                                            </span>
                                                        )}
                                                    </span>
                                                </SelectItem>
                                            ))}
                                        </SelectPopup>
                                    </Select>
                                    {showManualOnlineHint && (
                                        <FieldDescription>
                                            {t("Customers will be charged at booking; if you reject a booking, they'll receive an automatic full refund.")}
                                        </FieldDescription>
                                    )}
                                    {errors.payment_mode && <FieldError match>{errors.payment_mode}</FieldError>}
                                </Field>
                            </section>

                            <section className="flex flex-col gap-4">
                                <SectionHeading>
                                    <SectionTitle>{t('Reminders')}</SectionTitle>
                                    <SectionRule />
                                </SectionHeading>

                                <Field>
                                    <FieldLabel>{t('Send a reminder before the appointment')}</FieldLabel>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        <Label className="flex items-center gap-2.5 rounded-lg border border-border/70 bg-background px-3.5 py-2.5 text-sm transition-colors hover:border-border not-has-[:checked]:hover:bg-muted/40 has-[:checked]:border-primary/40 has-[:checked]:bg-honey-soft/60">
                                            <Checkbox
                                                name="reminder_hours[]"
                                                value="24"
                                                defaultChecked={settings.reminder_hours.includes(24)}
                                            />
                                            <span className="text-foreground">{t('24 hours before')}</span>
                                        </Label>
                                        <Label className="flex items-center gap-2.5 rounded-lg border border-border/70 bg-background px-3.5 py-2.5 text-sm transition-colors hover:border-border not-has-[:checked]:hover:bg-muted/40 has-[:checked]:border-primary/40 has-[:checked]:bg-honey-soft/60">
                                            <Checkbox
                                                name="reminder_hours[]"
                                                value="1"
                                                defaultChecked={settings.reminder_hours.includes(1)}
                                            />
                                            <span className="text-foreground">{t('1 hour before')}</span>
                                        </Label>
                                    </div>
                                    <FieldDescription>
                                        {t('Emails go out automatically. Customers can opt out per booking.')}
                                    </FieldDescription>
                                    {errors.reminder_hours && <FieldError match>{errors.reminder_hours}</FieldError>}
                                </Field>
                            </section>
                        </CardPanel>
                        <CardFooter className="justify-end border-t bg-muted/50 px-5 py-3 sm:px-6">
                            <Button type="submit" loading={processing}>
                                {t('Save changes')}
                            </Button>
                        </CardFooter>
                    </Card>
                )}
            </Form>
        </SettingsLayout>
    );
}
