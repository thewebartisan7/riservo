import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { Card, CardPanel } from '@/components/ui/card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';
import {
    SectionHeading,
    SectionTitle,
    SectionRule,
} from '@/components/ui/section-heading';
import { useTrans } from '@/hooks/use-trans';
import { Link, useHttp } from '@inertiajs/react';
import { useState } from 'react';
import {
    AlertTriangleIcon,
    CheckCircle2Icon,
    XCircleIcon,
    ExternalLinkIcon,
    WalletIcon,
} from 'lucide-react';
import { loginLink as loginLinkAction } from '@/actions/App/Http/Controllers/Dashboard/PayoutsController';
import { show as connectedAccountShow } from '@/actions/App/Http/Controllers/Dashboard/Settings/ConnectedAccountController';

interface AccountState {
    status: 'pending' | 'incomplete' | 'active' | 'disabled' | 'unsupported_market';
    country: string | null;
    defaultCurrency: string | null;
    chargesEnabled: boolean;
    payoutsEnabled: boolean;
    detailsSubmitted: boolean;
    requirementsCurrentlyDue: string[];
    requirementsDisabledReason: string | null;
    stripeAccountIdLast4: string;
}

interface BalanceArm {
    amount: number;
    currency: string;
}

interface PayoutRow {
    id: string;
    amount: number;
    currency: string;
    status: string;
    arrival_date: number | null;
    created_at: number;
}

interface PayoutSchedule {
    interval: 'manual' | 'daily' | 'weekly' | 'monthly' | string;
    delay_days: number | null;
    weekly_anchor: string | null;
    monthly_anchor: number | null;
}

interface PayoutsPayload {
    available: BalanceArm[];
    pending: BalanceArm[];
    payouts: PayoutRow[];
    schedule: PayoutSchedule | null;
    tax_status: string | null;
    fetched_at: string | null;
    stale: boolean;
    error: 'unreachable' | null;
}

interface Props {
    account: AccountState | null;
    payouts: PayoutsPayload | null;
    supportedCountries: string[];
}

interface LoginLinkResponse {
    url?: string;
    error?: string;
}

export default function Payouts({ account, payouts, supportedCountries }: Props) {
    const { t } = useTrans();

    return (
        <AuthenticatedLayout
            title={t('Payouts')}
            heading={t('Payouts')}
            description={t(
                "See where your money sits on Stripe — balance, recent payouts, and the connected-account health Stripe reports.",
            )}
        >
            <div className="flex flex-col gap-10">
                {account === null && <NotConnected />}
                {account?.status === 'pending' && <ResumeOnboarding account={account} />}
                {account?.status === 'incomplete' && <ResumeOnboarding account={account} />}
                {account?.status === 'disabled' && <Disabled account={account} />}
                {(account?.status === 'active' || account?.status === 'unsupported_market') && (
                    <Verified
                        account={account}
                        payouts={payouts}
                        supportedCountries={supportedCountries}
                    />
                )}
            </div>
        </AuthenticatedLayout>
    );
}

// =================================================================
// Branches
// =================================================================

function NotConnected() {
    const { t } = useTrans();

    return (
        <Card>
            <CardPanel className="flex flex-col gap-5 p-5 sm:p-6">
                <div className="flex items-start gap-3">
                    <WalletIcon
                        aria-hidden="true"
                        className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                    />
                    <div className="flex flex-col gap-2">
                        <h2 className="text-base font-semibold">
                            {t('Connect Stripe to see your payouts')}
                        </h2>
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            {t(
                                'Once you connect a Stripe Express account, this page will show your balance, recent payouts, and account health.',
                            )}
                        </p>
                    </div>
                </div>
                <div>
                    <Link
                        href={connectedAccountShow().url}
                        className="inline-flex items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90"
                    >
                        {t('Set up Stripe Connect')}
                    </Link>
                </div>
            </CardPanel>
        </Card>
    );
}

function ResumeOnboarding({ account }: { account: AccountState }) {
    const { t } = useTrans();
    const isPending = account.status === 'pending';

    return (
        <Card>
            <CardPanel className="flex flex-col gap-5 p-5 sm:p-6">
                <Alert variant="warning" role="status">
                    <AlertTriangleIcon aria-hidden="true" />
                    <AlertTitle>
                        {isPending
                            ? t('Stripe onboarding not yet started')
                            : t('Stripe needs more details')}
                    </AlertTitle>
                    <AlertDescription>
                        <p>
                            {isPending
                                ? t(
                                      'Finish identity verification on Stripe to start receiving payouts.',
                                  )
                                : t(
                                      'Stripe is still asking for some details before this account can receive payouts.',
                                  )}
                        </p>
                    </AlertDescription>
                </Alert>
                <div>
                    <Link
                        href={connectedAccountShow().url}
                        className="inline-flex items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90"
                    >
                        {t('Continue in Stripe')}
                    </Link>
                </div>
            </CardPanel>
        </Card>
    );
}

function Disabled({ account }: { account: AccountState }) {
    const { t } = useTrans();

    return (
        <Card>
            <CardPanel className="flex flex-col gap-5 p-5 sm:p-6">
                <Alert variant="error" role="alert">
                    <XCircleIcon aria-hidden="true" />
                    <AlertTitle>{t('This Stripe account is disabled')}</AlertTitle>
                    <AlertDescription>
                        <p>
                            {t(
                                'Stripe has disabled this connected account. New charges and payouts are blocked. Please contact support.',
                            )}
                        </p>
                        {account.requirementsDisabledReason && (
                            <p className="mt-2 text-xs">
                                <span className="font-medium">{t('Stripe reason')}:</span>{' '}
                                <code className="font-mono text-xs">
                                    {account.requirementsDisabledReason}
                                </code>
                            </p>
                        )}
                    </AlertDescription>
                </Alert>
                <div>
                    <a
                        href="mailto:support@riservo.ch"
                        className="inline-flex items-center justify-center gap-2 rounded-md border border-border bg-background px-4 py-2 text-sm font-medium text-foreground transition-colors hover:bg-accent"
                    >
                        {t('Contact support')}
                    </a>
                </div>
            </CardPanel>
        </Card>
    );
}

function Verified({
    account,
    payouts,
    supportedCountries,
}: {
    account: AccountState;
    payouts: PayoutsPayload | null;
    supportedCountries: string[];
}) {
    const { t } = useTrans();

    const isUnsupportedMarket = account.status === 'unsupported_market';
    const isTaxNotConfigured =
        payouts !== null && payouts.error === null && payouts.tax_status !== 'active';
    const isStale = payouts?.stale === true;
    const isUnreachable = payouts?.error === 'unreachable';

    return (
        <div className="flex flex-col gap-8">
            <HealthStrip account={account} />

            {isUnsupportedMarket && (
                <Alert variant="warning" role="status" data-testid="unsupported-market-banner">
                    <AlertTriangleIcon aria-hidden="true" />
                    <AlertTitle>{t('Online payments not available for your country yet')}</AlertTitle>
                    <AlertDescription>
                        <p>
                            {t(
                                "Online payments in MVP support :countries-located businesses only. Your account country is :country.",
                                {
                                    countries: supportedCountries.join(', '),
                                    country: account.country ?? '—',
                                },
                            )}
                        </p>
                    </AlertDescription>
                </Alert>
            )}

            {isTaxNotConfigured && (
                <Alert variant="warning" role="status" data-testid="tax-not-configured-banner">
                    <AlertTriangleIcon aria-hidden="true" />
                    <AlertTitle>{t('Stripe Tax not yet configured')}</AlertTitle>
                    <AlertDescription>
                        <p>
                            {t(
                                "Your customers' receipts won't show VAT until you configure Stripe Tax on your connected account.",
                            )}
                        </p>
                        <p className="mt-2 text-xs">
                            <a
                                href="https://dashboard.stripe.com/tax"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-1 underline-offset-4 hover:underline"
                            >
                                {t('Configure in Stripe')}
                                <ExternalLinkIcon aria-hidden="true" className="size-3" />
                            </a>
                        </p>
                    </AlertDescription>
                </Alert>
            )}

            {isStale && !isUnreachable && (
                <Alert variant="warning" role="status" data-testid="stale-banner">
                    <AlertTriangleIcon aria-hidden="true" />
                    <AlertTitle>{t("Couldn't refresh payout data")}</AlertTitle>
                    <AlertDescription>
                        <p>
                            {t(
                                'Showing the last known state. Stripe might be temporarily unreachable — refresh in a moment to try again.',
                            )}
                        </p>
                    </AlertDescription>
                </Alert>
            )}

            {isUnreachable && (
                <Alert variant="error" role="alert" data-testid="unreachable-banner">
                    <XCircleIcon aria-hidden="true" />
                    <AlertTitle>{t("Couldn't load payout state")}</AlertTitle>
                    <AlertDescription>
                        <p>
                            {t(
                                "We couldn't reach Stripe to load your payouts. Refresh the page to try again — your money is safe in Stripe regardless.",
                            )}
                        </p>
                    </AlertDescription>
                </Alert>
            )}

            <BalanceCards payouts={payouts} />

            <ScheduleAndLoginCard account={account} payouts={payouts} />

            <RecentPayoutsTable payouts={payouts} />
        </div>
    );
}

// =================================================================
// Sections (Verified branch)
// =================================================================

function HealthStrip({ account }: { account: AccountState }) {
    const { t } = useTrans();
    const requirementsCount = account.requirementsCurrentlyDue.length;

    return (
        <section className="flex flex-col gap-4">
            <SectionHeading>
                <SectionTitle>{t('Account health')}</SectionTitle>
                <SectionRule />
            </SectionHeading>
            <div className="flex flex-wrap gap-2">
                <HealthChip
                    on={account.chargesEnabled}
                    onLabel={t('Charges enabled')}
                    offLabel={t('Charges disabled')}
                />
                <HealthChip
                    on={account.payoutsEnabled}
                    onLabel={t('Payouts enabled')}
                    offLabel={t('Payouts disabled')}
                />
                {requirementsCount === 0 ? (
                    <HealthChip on={true} onLabel={t('No requirements due')} offLabel="" />
                ) : (
                    <Tooltip>
                        <TooltipTrigger
                            render={
                                <span
                                    className="inline-flex cursor-help items-center gap-1.5 rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                                    data-testid="requirements-due-chip"
                                >
                                    <AlertTriangleIcon
                                        aria-hidden="true"
                                        className="size-3.5"
                                    />
                                    {t(':count requirement(s) due — see Stripe', {
                                        count: requirementsCount,
                                    })}
                                </span>
                            }
                        />
                        <TooltipContent className="max-w-72 p-3" side="bottom">
                            <div className="flex flex-col gap-1">
                                <p className="text-xs font-semibold">
                                    {t('Requirements currently due')}
                                </p>
                                <ul className="flex flex-col gap-0.5 text-xs">
                                    {account.requirementsCurrentlyDue
                                        .slice(0, 3)
                                        .map((req) => (
                                            <li key={req}>
                                                <code className="font-mono text-[11px]">
                                                    {req}
                                                </code>
                                            </li>
                                        ))}
                                    {requirementsCount > 3 && (
                                        <li className="text-muted-foreground">
                                            {t('+ :count more', {
                                                count: requirementsCount - 3,
                                            })}
                                        </li>
                                    )}
                                </ul>
                            </div>
                        </TooltipContent>
                    </Tooltip>
                )}
            </div>
        </section>
    );
}

function HealthChip({
    on,
    onLabel,
    offLabel,
}: {
    on: boolean;
    onLabel: string;
    offLabel: string;
}) {
    if (on) {
        return (
            <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">
                <CheckCircle2Icon aria-hidden="true" className="size-3.5" />
                {onLabel}
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-1.5 rounded-full bg-rose-100 px-3 py-1 text-xs font-medium text-rose-900 dark:bg-rose-950 dark:text-rose-200">
            <XCircleIcon aria-hidden="true" className="size-3.5" />
            {offLabel}
        </span>
    );
}

function BalanceCards({ payouts }: { payouts: PayoutsPayload | null }) {
    const { t } = useTrans();

    return (
        <section className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <Card>
                <CardPanel className="flex flex-col gap-2 p-5">
                    <p className="text-xs font-medium uppercase tracking-[0.18em] text-muted-foreground">
                        {t('Available balance')}
                    </p>
                    <p className="text-2xl font-semibold">
                        {formatBalanceArms(payouts?.available ?? [])}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {t('Will be paid out on the next cycle.')}
                    </p>
                </CardPanel>
            </Card>
            <Card>
                <CardPanel className="flex flex-col gap-2 p-5">
                    <p className="text-xs font-medium uppercase tracking-[0.18em] text-muted-foreground">
                        {t('Pending balance')}
                    </p>
                    <p className="text-2xl font-semibold">
                        {formatBalanceArms(payouts?.pending ?? [])}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {t('Still in Stripe’s clearing window.')}
                    </p>
                </CardPanel>
            </Card>
        </section>
    );
}

function ScheduleAndLoginCard({
    account,
    payouts,
}: {
    account: AccountState;
    payouts: PayoutsPayload | null;
}) {
    const { t } = useTrans();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const http = useHttp({});

    const buttonDisabled = busy || account.status === 'disabled';

    function openLoginLink() {
        setError(null);
        setBusy(true);
        http.post(loginLinkAction().url, {
            onSuccess: (response: unknown) => {
                const result = response as LoginLinkResponse;
                if (result.url) {
                    window.open(result.url, '_blank', 'noopener');
                } else {
                    setError(
                        result.error ??
                            t('Could not open Stripe right now. Please try again.'),
                    );
                }
            },
            onError: () => {
                setError(
                    t('Could not open Stripe right now. Please try again.'),
                );
            },
            onFinish: () => {
                setBusy(false);
            },
        });
    }

    return (
        <section className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <Card>
                <CardPanel className="flex flex-col gap-2 p-5">
                    <p className="text-xs font-medium uppercase tracking-[0.18em] text-muted-foreground">
                        {t('Payout schedule')}
                    </p>
                    <p className="text-base font-medium">{formatSchedule(payouts?.schedule ?? null, t)}</p>
                    <p className="text-xs text-muted-foreground">
                        {t('Change the schedule from your Stripe dashboard.')}
                    </p>
                </CardPanel>
            </Card>
            <Card>
                <CardPanel className="flex flex-col gap-3 p-5">
                    <p className="text-xs font-medium uppercase tracking-[0.18em] text-muted-foreground">
                        {t('Manage in Stripe')}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'Open the Stripe Express dashboard in a new tab — change your payout schedule, update bank details, or download invoices.',
                        )}
                    </p>
                    {error && (
                        <Alert variant="error" role="alert" data-testid="login-link-error">
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}
                    <div>
                        <Button
                            type="button"
                            onClick={openLoginLink}
                            loading={busy}
                            disabled={buttonDisabled}
                            aria-label={t('Open Stripe Express dashboard in a new tab')}
                            data-testid="login-link-button"
                        >
                            {t('Manage payouts in Stripe')}
                            <ExternalLinkIcon aria-hidden="true" className="size-4" />
                        </Button>
                    </div>
                </CardPanel>
            </Card>
        </section>
    );
}

function RecentPayoutsTable({ payouts }: { payouts: PayoutsPayload | null }) {
    const { t } = useTrans();
    const rows = payouts?.payouts ?? [];

    return (
        <section className="flex flex-col gap-4">
            <SectionHeading>
                <SectionTitle>{t('Recent payouts')}</SectionTitle>
                <SectionRule />
            </SectionHeading>
            <Card>
                <CardPanel className="p-0">
                    {rows.length === 0 ? (
                        <p className="p-5 text-sm text-muted-foreground">
                            {t('No payouts yet. They will appear here once Stripe issues your first payout.')}
                        </p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead className="border-b border-border text-left text-xs uppercase tracking-[0.18em] text-muted-foreground">
                                <tr>
                                    <th className="px-5 py-3 font-medium">{t('Date')}</th>
                                    <th className="px-5 py-3 font-medium">{t('Amount')}</th>
                                    <th className="px-5 py-3 font-medium">{t('Status')}</th>
                                    <th className="px-5 py-3 font-medium">{t('Arrival')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((payout) => (
                                    <tr
                                        key={payout.id}
                                        className="border-b border-border last:border-b-0"
                                    >
                                        <td className="px-5 py-3">
                                            {formatUnixDate(payout.created_at)}
                                        </td>
                                        <td className="px-5 py-3 font-medium">
                                            {formatAmount(payout.amount, payout.currency)}
                                        </td>
                                        <td className="px-5 py-3">
                                            <PayoutStatusBadge status={payout.status} />
                                        </td>
                                        <td className="px-5 py-3 text-muted-foreground">
                                            {payout.arrival_date
                                                ? formatUnixDate(payout.arrival_date)
                                                : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </CardPanel>
            </Card>
        </section>
    );
}

function PayoutStatusBadge({ status }: { status: string }) {
    const tone: Record<string, 'success' | 'warning' | 'error' | 'secondary'> = {
        paid: 'success',
        in_transit: 'warning',
        pending: 'secondary',
        failed: 'error',
        canceled: 'error',
    };
    const variant = tone[status] ?? 'secondary';
    return <Badge variant={variant}>{status}</Badge>;
}

// =================================================================
// Helpers
// =================================================================

function formatBalanceArms(arms: BalanceArm[]): string {
    if (arms.length === 0) {
        return '—';
    }
    return arms.map((arm) => formatAmount(arm.amount, arm.currency)).join(' · ');
}

function formatAmount(amountCents: number, currency: string): string {
    const code = (currency || 'CHF').toUpperCase();
    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: code,
            minimumFractionDigits: 2,
        }).format(amountCents / 100);
    } catch {
        return `${(amountCents / 100).toFixed(2)} ${code}`;
    }
}

function formatUnixDate(timestamp: number): string {
    if (!timestamp) {
        return '—';
    }
    return new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    }).format(new Date(timestamp * 1000));
}

function formatSchedule(
    schedule: PayoutSchedule | null,
    t: (key: string, replacements?: Record<string, string | number>) => string,
): string {
    if (schedule === null) {
        return t('Unknown');
    }

    switch (schedule.interval) {
        case 'daily':
            return schedule.delay_days !== null
                ? t('Daily — :days day(s) after the charge', { days: schedule.delay_days })
                : t('Daily');
        case 'weekly':
            return schedule.weekly_anchor
                ? t('Weekly — every :anchor', { anchor: schedule.weekly_anchor })
                : t('Weekly');
        case 'monthly':
            return schedule.monthly_anchor !== null
                ? t('Monthly — on day :day', { day: schedule.monthly_anchor })
                : t('Monthly');
        case 'manual':
            return t('Manual — initiated from your Stripe dashboard');
        default:
            return schedule.interval;
    }
}
