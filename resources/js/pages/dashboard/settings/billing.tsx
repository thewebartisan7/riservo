import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardPanel } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { SectionHeading, SectionTitle, SectionRule } from '@/components/ui/section-heading';
import { useTrans } from '@/hooks/use-trans';
import { Form } from '@inertiajs/react';
import {
    cancel,
    portal,
    resume,
    subscribe,
} from '@/actions/App/Http/Controllers/Dashboard/Settings/BillingController';
import type { SubscriptionState } from '@/types';
import { AlertTriangleIcon, CheckCircle2Icon, ExternalLinkIcon } from 'lucide-react';

interface PlanRow {
    price_id: string | null;
    amount: number;
    currency: string;
    interval: 'month' | 'year';
}

interface Props {
    subscription: SubscriptionState;
    plans: { monthly: PlanRow; annual: PlanRow };
    has_stripe_keys: boolean;
}

export default function BillingPage({ subscription, plans, has_stripe_keys }: Props) {
    const { t } = useTrans();
    const status = subscription.status;
    const periodEnds = subscription.current_period_ends_at
        ? new Date(subscription.current_period_ends_at).toLocaleDateString(undefined, {
              year: 'numeric',
              month: 'long',
              day: 'numeric',
          })
        : null;

    return (
        <SettingsLayout
            title={t('Billing')}
            eyebrow={t('Settings · Business')}
            heading={t('Subscription & billing')}
            description={t(
                'Manage your subscription, payment method, and download invoices.',
            )}
        >
            <div className="flex flex-col gap-6">
                {!has_stripe_keys && (
                    <Alert variant="info">
                        <AlertTriangleIcon aria-hidden="true" />
                        <AlertTitle>{t('Billing setup incomplete')}</AlertTitle>
                        <AlertDescription>
                            {t(
                                'Stripe keys are not configured yet. Subscribe and portal actions are unavailable until an admin completes setup.',
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                <Card>
                    <CardPanel className="flex flex-col gap-6 p-5 sm:p-6">
                        <SectionHeading>
                            <SectionTitle>{t('Current plan')}</SectionTitle>
                            <SectionRule />
                        </SectionHeading>

                        <StatusSummary
                            status={status}
                            periodEnds={periodEnds}
                            t={t}
                        />
                    </CardPanel>
                </Card>

                {(status === 'trial' || status === 'read_only') && (
                    <PlanPicker plans={plans} disabled={!has_stripe_keys} t={t} status={status} />
                )}

                {status !== 'trial' && (
                    <Card>
                        <CardPanel className="flex flex-col gap-4 p-5 sm:p-6">
                            <SectionHeading>
                                <SectionTitle>{t('Manage billing')}</SectionTitle>
                                <SectionRule />
                            </SectionHeading>

                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'Open the Stripe customer portal to update payment methods, download invoices, or change plans.',
                                )}
                            </p>

                            <div className="flex flex-wrap items-center gap-3">
                                <Form action={portal()} method="post">
                                    {({ processing }) => (
                                        <Button type="submit" variant="outline" loading={processing}>
                                            <ExternalLinkIcon aria-hidden="true" />
                                            {t('Open Stripe portal')}
                                        </Button>
                                    )}
                                </Form>

                                {(status === 'active' || status === 'past_due') && (
                                    <Form action={cancel()} method="post">
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="destructive-outline"
                                                loading={processing}
                                            >
                                                {t('Cancel subscription')}
                                            </Button>
                                        )}
                                    </Form>
                                )}

                                {status === 'canceled' && (
                                    <Form action={resume()} method="post">
                                        {({ processing }) => (
                                            <Button type="submit" loading={processing}>
                                                {t('Resume subscription')}
                                            </Button>
                                        )}
                                    </Form>
                                )}
                            </div>
                        </CardPanel>
                    </Card>
                )}
            </div>
        </SettingsLayout>
    );
}

interface StatusSummaryProps {
    status: SubscriptionState['status'];
    periodEnds: string | null;
    t: (s: string) => string;
}

function StatusSummary({ status, periodEnds, t }: StatusSummaryProps) {
    if (status === 'trial') {
        return (
            <div className="flex flex-col gap-2">
                <p className="text-sm font-medium text-foreground">
                    {t('Free trial — no payment information on file')}
                </p>
                <p className="text-sm text-muted-foreground">
                    {t(
                        "You're on an indefinite trial. Subscribe whenever you're ready — your data stays the way you left it.",
                    )}
                </p>
            </div>
        );
    }

    if (status === 'active') {
        return (
            <div className="flex items-start gap-3">
                <CheckCircle2Icon
                    aria-hidden="true"
                    className="mt-0.5 size-5 text-success"
                />
                <div className="flex flex-col gap-1">
                    <p className="text-sm font-medium text-foreground">{t('Active')}</p>
                    <p className="text-sm text-muted-foreground">
                        {t('Your subscription renews automatically.')}
                    </p>
                </div>
            </div>
        );
    }

    if (status === 'past_due') {
        return (
            <div className="flex flex-col gap-2">
                <p className="text-sm font-medium text-warning">
                    {t('Payment failed — Stripe is retrying')}
                </p>
                <p className="text-sm text-muted-foreground">
                    {t(
                        'Open the Stripe portal to update your payment method before access is suspended.',
                    )}
                </p>
            </div>
        );
    }

    if (status === 'canceled') {
        return (
            <div className="flex flex-col gap-2">
                <p className="text-sm font-medium text-foreground">
                    {t('Subscription will end')}
                    {periodEnds ? ` — ${periodEnds}` : ''}
                </p>
                <p className="text-sm text-muted-foreground">
                    {t(
                        'You retain full access until the end of the current billing period. Resume any time before then to keep your subscription active.',
                    )}
                </p>
            </div>
        );
    }

    // read_only
    return (
        <div className="flex flex-col gap-2">
            <p className="text-sm font-medium text-destructive">
                {t('Subscription ended — dashboard is read-only')}
            </p>
            <p className="text-sm text-muted-foreground">
                {t(
                    'Pick a plan below to resubscribe and restore full access. Your existing data and bookings are unchanged.',
                )}
            </p>
        </div>
    );
}

interface PlanPickerProps {
    plans: Props['plans'];
    disabled: boolean;
    status: SubscriptionState['status'];
    t: (s: string) => string;
}

function PlanPicker({ plans, disabled, status, t }: PlanPickerProps) {
    const heading =
        status === 'read_only' ? t('Resubscribe') : t('Choose a plan');

    return (
        <Card>
            <CardPanel className="flex flex-col gap-6 p-5 sm:p-6">
                <SectionHeading>
                    <SectionTitle>{heading}</SectionTitle>
                    <SectionRule />
                </SectionHeading>

                <div className="grid gap-4 sm:grid-cols-2">
                    <PlanCard
                        plan={plans.monthly}
                        planKey="monthly"
                        ribbon={null}
                        disabled={disabled}
                        t={t}
                    />
                    <PlanCard
                        plan={plans.annual}
                        planKey="annual"
                        ribbon={t('Save 2 months')}
                        disabled={disabled}
                        t={t}
                    />
                </div>

                <p className="text-xs text-muted-foreground">
                    {t(
                        'Prices shown exclude VAT. Stripe calculates and adds Swiss VAT at checkout based on your address.',
                    )}
                </p>
            </CardPanel>
        </Card>
    );
}

interface PlanCardProps {
    plan: PlanRow;
    planKey: 'monthly' | 'annual';
    ribbon: string | null;
    disabled: boolean;
    t: (s: string) => string;
}

function PlanCard({ plan, planKey, ribbon, disabled, t }: PlanCardProps) {
    const intervalLabel = plan.interval === 'year' ? t('per year') : t('per month');
    const planLabel = planKey === 'annual' ? t('Annual') : t('Monthly');

    return (
        <Form action={subscribe()} method="post">
            {({ processing }) => (
                <div className="flex h-full flex-col gap-4 rounded-xl border border-border/70 bg-background p-4">
                    <div className="flex items-start justify-between gap-2">
                        <span className="text-sm font-medium uppercase tracking-[0.18em] text-muted-foreground">
                            {planLabel}
                        </span>
                        {ribbon && (
                            <span className="rounded-full bg-honey-soft/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-foreground">
                                {ribbon}
                            </span>
                        )}
                    </div>
                    <div className="flex items-baseline gap-1">
                        <span className="text-3xl font-semibold text-foreground tabular-nums">
                            {plan.currency} {plan.amount}
                        </span>
                        <span className="text-sm text-muted-foreground">
                            {intervalLabel}
                        </span>
                    </div>
                    <input type="hidden" name="plan" value={planKey} />
                    <Button
                        type="submit"
                        loading={processing}
                        disabled={disabled || plan.price_id === null}
                        className="mt-auto"
                    >
                        {t('Subscribe')}
                    </Button>
                </div>
            )}
        </Form>
    );
}
