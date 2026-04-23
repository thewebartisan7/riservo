import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardPanel } from '@/components/ui/card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    SectionHeading,
    SectionTitle,
    SectionRule,
} from '@/components/ui/section-heading';
import { useTrans } from '@/hooks/use-trans';
import { Form, Link, usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';
import {
    create,
    disconnect,
    resume,
} from '@/actions/App/Http/Controllers/Dashboard/Settings/ConnectedAccountController';

interface AccountState {
    status:
        | 'pending'
        | 'incomplete'
        | 'active'
        | 'disabled'
        // D-150 (codex Round 13): Stripe capabilities are on but the row's
        // `country` is not in `config('payments.supported_countries')`.
        // Distinct state so the UI can explain the mismatch instead of
        // showing "Verified" while the backend refuses online payments.
        | 'unsupported_market';
    country: string;
    defaultCurrency: string | null;
    chargesEnabled: boolean;
    payoutsEnabled: boolean;
    detailsSubmitted: boolean;
    requirementsCurrentlyDue: string[];
    requirementsDisabledReason: string | null;
    stripeAccountIdLast4: string;
}

interface Props {
    account: AccountState | null;
}

export default function ConnectedAccount({ account }: Props) {
    const { t } = useTrans();
    const flash = usePage<PageProps>().props.flash;

    return (
        <SettingsLayout
            title={t('Online Payments')}
            eyebrow={t('Settings · Business')}
            heading={t('Online payments (Stripe Connect)')}
            description={t(
                'Connect your business to Stripe so customers can pay when they book. Riservo takes zero commission — payments go straight to your Stripe account.',
            )}
        >
            <div className="flex flex-col gap-10">
                {flash.error && (
                    <Alert variant="error" role="alert">
                        <AlertTitle>{t('Stripe Connect error')}</AlertTitle>
                        <AlertDescription>{flash.error}</AlertDescription>
                    </Alert>
                )}
                {flash.success && (
                    <Alert variant="success" role="status">
                        <AlertTitle>{t('Updated')}</AlertTitle>
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                <section className="flex flex-col gap-4">
                    <SectionHeading>
                        <SectionTitle>{t('Stripe connected account')}</SectionTitle>
                        <SectionRule />
                    </SectionHeading>

                    <Card>
                        <CardPanel className="flex flex-col gap-5 p-5 sm:p-6">
                            {account === null && <NotConnected />}
                            {account?.status === 'pending' && <PendingOnboarding />}
                            {account?.status === 'incomplete' && (
                                <Incomplete account={account} />
                            )}
                            {account?.status === 'active' && <Active account={account} />}
                            {account?.status === 'unsupported_market' && (
                                <UnsupportedMarket account={account} />
                            )}
                            {account?.status === 'disabled' && (
                                <Disabled account={account} />
                            )}
                        </CardPanel>
                    </Card>
                </section>
            </div>
        </SettingsLayout>
    );
}

function NotConnected() {
    const { t } = useTrans();

    return (
        <div className="flex flex-col gap-5">
            <p className="max-w-2xl text-sm text-muted-foreground">
                {t(
                    'Stripe handles identity verification, payments, and payouts. We never see your customers\' card details. You\'ll be redirected to Stripe to complete the setup.',
                )}
            </p>
            <ul className="flex flex-col gap-1.5 text-sm text-muted-foreground">
                <li>{t('• Each business needs its own Stripe account — if you manage more than one, onboard each one separately.')}</li>
                <li>{t('• Riservo charges zero commission. The full price (minus Stripe fees) lands in your account.')}</li>
                <li>{t('• Onboarding now is the prerequisite for online payments. The booking flow that actually charges customers will activate in a later release.')}</li>
            </ul>
            <Form action={create()} options={{ preserveScroll: true }}>
                {({ processing }) => (
                    <Button
                        type="submit"
                        loading={processing}
                        aria-label={t('Enable online payments via Stripe')}
                        className="self-start"
                    >
                        {t('Enable online payments')}
                    </Button>
                )}
            </Form>
        </div>
    );
}

function PendingOnboarding() {
    const { t } = useTrans();

    return (
        <div className="flex flex-col gap-5">
            <div className="flex flex-col gap-1">
                <p className="text-[10px] font-medium uppercase tracking-[0.22em] text-warning">
                    {t('Onboarding incomplete')}
                </p>
                <p className="text-sm text-muted-foreground">
                    {t('You started Stripe onboarding but didn\'t finish. Pick up where you left off.')}
                </p>
            </div>
            <div className="flex flex-wrap gap-2">
                <Form action={resume()} options={{ preserveScroll: true }}>
                    {({ processing }) => (
                        <Button
                            type="submit"
                            loading={processing}
                            aria-label={t('Continue Stripe onboarding')}
                        >
                            {t('Continue Stripe onboarding')}
                        </Button>
                    )}
                </Form>
                <DisconnectButton />
            </div>
        </div>
    );
}

function Incomplete({ account }: { account: AccountState }) {
    const { t } = useTrans();

    return (
        <div className="flex flex-col gap-5">
            <div className="flex flex-col gap-1">
                <p className="text-[10px] font-medium uppercase tracking-[0.22em] text-warning">
                    {t('Stripe is verifying your account')}
                </p>
                <p className="text-sm text-muted-foreground">
                    {t('Stripe still needs a few details before you can take online payments.')}
                </p>
            </div>
            {account.requirementsCurrentlyDue.length > 0 && (
                <div className="flex flex-col gap-2">
                    <p className="text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                        {t('Stripe needs')}
                    </p>
                    <ul className="flex flex-wrap gap-1.5">
                        {account.requirementsCurrentlyDue.map((req) => (
                            <li key={req}>
                                <Badge variant="secondary">{req}</Badge>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            <div className="flex flex-wrap gap-2">
                <Form action={resume()} options={{ preserveScroll: true }}>
                    {({ processing }) => (
                        <Button type="submit" loading={processing}>
                            {t('Continue in Stripe')}
                        </Button>
                    )}
                </Form>
                <DisconnectButton />
            </div>
        </div>
    );
}

function Active({ account }: { account: AccountState }) {
    const { t } = useTrans();

    return (
        <div className="flex flex-col gap-5">
            <div className="flex flex-col gap-1">
                <p className="text-[10px] font-medium uppercase tracking-[0.22em] text-primary">
                    {t('Verified')}
                </p>
                <p className="text-sm text-foreground">
                    {t('Your Stripe account is verified. Charging customers will activate in a later release.')}
                </p>
            </div>
            <dl className="grid grid-cols-1 gap-3 border-t border-border/60 pt-5 sm:grid-cols-2">
                <div className="flex flex-col gap-0.5">
                    <dt className="text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                        {t('Country')}
                    </dt>
                    <dd className="text-sm text-foreground">{account.country}</dd>
                </div>
                {account.defaultCurrency && (
                    <div className="flex flex-col gap-0.5">
                        <dt className="text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                            {t('Default currency')}
                        </dt>
                        <dd className="text-sm text-foreground">
                            {account.defaultCurrency.toUpperCase()}
                        </dd>
                    </div>
                )}
                <div className="flex flex-col gap-0.5">
                    <dt className="text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                        {t('Capabilities')}
                    </dt>
                    <dd className="flex flex-wrap gap-1.5">
                        <CapabilityChip label={t('Charges')} enabled={account.chargesEnabled} />
                        <CapabilityChip label={t('Payouts')} enabled={account.payoutsEnabled} />
                    </dd>
                </div>
                <div className="flex flex-col gap-0.5">
                    <dt className="text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                        {t('Account ID')}
                    </dt>
                    <dd className="text-sm text-foreground">…{account.stripeAccountIdLast4}</dd>
                </div>
            </dl>
            <div className="flex flex-wrap gap-2 border-t border-border/60 pt-5">
                <DisconnectButton />
            </div>
        </div>
    );
}

function UnsupportedMarket({ account }: { account: AccountState }) {
    const { t } = useTrans();

    return (
        <div className="flex flex-col gap-5">
            <Alert variant="warning" role="alert">
                <AlertTitle>{t('Online payments not available for your country yet')}</AlertTitle>
                <AlertDescription>
                    <p>
                        {t(
                            'Stripe verification is complete, but riservo does not yet support online payments for accounts in your country. Your Stripe account is valid and can be re-activated once we expand coverage.',
                        )}
                    </p>
                    {account.country && (
                        <p className="mt-1 font-mono text-xs">
                            {t('Country')}: {account.country}
                        </p>
                    )}
                </AlertDescription>
            </Alert>
            <div className="flex flex-wrap gap-2">
                <a
                    href="mailto:support@riservo.ch"
                    className="inline-flex"
                    aria-label={t('Email riservo support')}
                >
                    <Button type="button" variant="secondary">
                        {t('Contact support')}
                    </Button>
                </a>
                <DisconnectButton />
            </div>
        </div>
    );
}

function Disabled({ account }: { account: AccountState }) {
    const { t } = useTrans();

    return (
        <div className="flex flex-col gap-5">
            <Alert variant="error" role="alert">
                <AlertTitle>{t('Stripe disabled this account')}</AlertTitle>
                <AlertDescription>
                    <p>
                        {t(
                            'Stripe paused this connected account and reported the reason below. Reach out to support so we can help unblock it.',
                        )}
                    </p>
                    {account.requirementsDisabledReason && (
                        <p className="mt-1 font-mono text-xs">
                            {account.requirementsDisabledReason}
                        </p>
                    )}
                </AlertDescription>
            </Alert>
            <div className="flex flex-wrap gap-2">
                <a
                    href="mailto:support@riservo.ch"
                    className="inline-flex"
                    aria-label={t('Email riservo support')}
                >
                    <Button type="button" variant="secondary">
                        {t('Contact support')}
                    </Button>
                </a>
                <DisconnectButton />
            </div>
        </div>
    );
}

function DisconnectButton() {
    const { t } = useTrans();

    return (
        <Form action={disconnect()} options={{ preserveScroll: true }}>
            {({ processing }) => (
                <Button
                    type="submit"
                    variant="destructive-outline"
                    size="sm"
                    disabled={processing}
                    aria-label={t('Disconnect Stripe Connect')}
                    onClick={(event) => {
                        if (!window.confirm(t('Disconnect Stripe? Online payments will be disabled. Existing bookings stay valid; you can re-connect later.'))) {
                            event.preventDefault();
                        }
                    }}
                >
                    {t('Disconnect')}
                </Button>
            )}
        </Form>
    );
}

function CapabilityChip({ label, enabled }: { label: string; enabled: boolean }) {
    const { t } = useTrans();

    return (
        <Badge
            variant={enabled ? 'secondary' : 'outline'}
            className={enabled ? 'text-primary' : 'text-muted-foreground'}
        >
            <span aria-hidden="true">{enabled ? '✓' : '•'}</span>
            <span className="ml-1">
                {label} — {enabled ? t('on') : t('off')}
            </span>
        </Badge>
    );
}
