import OnboardingLayout from '@/layouts/onboarding-layout';
import { Card, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Display } from '@/components/ui/display';
import { Avatar, AvatarImage, AvatarFallback } from '@/components/ui/avatar';
import { useTrans } from '@/hooks/use-trans';
import { router } from '@inertiajs/react';
import { store, show } from '@/actions/App/Http/Controllers/OnboardingController';
import { useState } from 'react';
import { CheckIcon, ClipboardIcon, PencilIcon, ArrowUpRightIcon } from 'lucide-react';

const DAY_NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const DAY_SHORT = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

interface Props {
    business: {
        name: string;
        slug: string;
        description: string | null;
        logo: string | null;
        phone: string | null;
        email: string | null;
        address: string | null;
    };
    logoUrl: string | null;
    hours: Array<{
        day_of_week: number;
        windows: Array<{ open_time: string; close_time: string }>;
    }>;
    service: {
        name: string;
        duration_minutes: number;
        price: number | null;
    } | null;
    invitations: Array<{
        email: string;
        service_ids: number[] | null;
    }>;
    publicUrl: string;
}

export default function Step5({ business, logoUrl, hours, service, invitations, publicUrl }: Props) {
    const { t } = useTrans();
    const [processing, setProcessing] = useState(false);
    const [copied, setCopied] = useState(false);

    function launch() {
        setProcessing(true);
        router.post(store(5), {}, {
            onFinish: () => setProcessing(false),
        });
    }

    function copyUrl() {
        navigator.clipboard.writeText(publicUrl);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }

    function formatPrice(price: number | null): string {
        if (price === null) return t('On request');
        if (price === 0) return t('Free');
        return `CHF ${Number(price).toFixed(2)}`;
    }

    const initials = business.name
        ? business.name
              .split(/\s+/)
              .slice(0, 2)
              .map((w) => w[0])
              .join('')
              .toUpperCase()
        : '·';

    const displayUrl = publicUrl.replace(/^https?:\/\//, '');

    return (
        <OnboardingLayout
            step={5}
            title={t('Review & launch')}
            eyebrow={t("One last look")}
            heading={t('Ready when you are')}
            description={t('Your booking page will be live the moment you hit launch. You can keep editing from settings afterwards — nothing is set in stone.')}
        >
            <Card>
                <CardPanel className="flex flex-col gap-8">
                    {/* Public URL — the reveal */}
                    <div className="flex flex-col gap-3 rounded-xl border border-primary/24 bg-honey-soft/50 px-5 py-5">
                        <div className="flex items-center justify-between gap-4">
                            <p className="text-[10px] uppercase tracking-[0.22em] text-primary">
                                {t('Your booking page')}
                            </p>
                            <a
                                href={publicUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-1 text-[11px] uppercase tracking-[0.22em] text-muted-foreground transition-colors hover:text-foreground"
                            >
                                {t('Preview')}
                                <ArrowUpRightIcon className="size-3" />
                            </a>
                        </div>
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                            <Avatar className="size-10 shrink-0 rounded-lg border border-border bg-card">
                                {logoUrl && (
                                    <AvatarImage src={logoUrl} alt="" className="rounded-lg object-cover" />
                                )}
                                <AvatarFallback className="rounded-lg bg-card font-display text-xs font-semibold text-muted-foreground">
                                    {initials}
                                </AvatarFallback>
                            </Avatar>
                            <div className="min-w-0 flex-1">
                                <Display className="block truncate text-base font-semibold text-foreground">
                                    {displayUrl}
                                </Display>
                                {business.description && (
                                    <p className="truncate text-xs text-muted-foreground">
                                        {business.description}
                                    </p>
                                )}
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={copyUrl}
                                className={copied ? 'w-full sm:w-auto text-primary' : 'w-full sm:w-auto'}
                            >
                                {copied ? (
                                    <>
                                        <CheckIcon />
                                        {t('Copied')}
                                    </>
                                ) : (
                                    <>
                                        <ClipboardIcon />
                                        {t('Copy link')}
                                    </>
                                )}
                            </Button>
                        </div>
                    </div>

                    {/* Business profile */}
                    <SummarySection title={t('Business profile')} editStep={1}>
                        <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
                            <SummaryRow label={t('Name')} value={business.name} />
                            {business.phone && <SummaryRow label={t('Phone')} value={business.phone} />}
                            {business.email && <SummaryRow label={t('Email')} value={business.email} />}
                            {business.address && <SummaryRow label={t('Address')} value={business.address} />}
                        </dl>
                    </SummarySection>

                    {/* Working hours */}
                    <SummarySection title={t('Working hours')} editStep={2}>
                        <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-1.5 text-sm">
                            {Array.from({ length: 7 }, (_, i) => i + 1).map((day) => {
                                const dayHours = hours.find((h) => h.day_of_week === day);
                                const isOpen = dayHours && dayHours.windows.length > 0;
                                return (
                                    <div key={day} className="contents">
                                        <dt className="text-muted-foreground">
                                            <span className="hidden sm:inline">{t(DAY_NAMES[day - 1])}</span>
                                            <span className="sm:hidden">{t(DAY_SHORT[day - 1])}</span>
                                        </dt>
                                        <dd className={isOpen ? 'tabular-nums text-foreground' : 'text-muted-foreground/70'}>
                                            {isOpen
                                                ? dayHours!.windows.map((w) => `${w.open_time} – ${w.close_time}`).join(' · ')
                                                : t('Closed')}
                                        </dd>
                                    </div>
                                );
                            })}
                        </dl>
                    </SummarySection>

                    {/* Service */}
                    {service && (
                        <SummarySection title={t('First service')} editStep={3}>
                            <div className="flex items-center justify-between gap-4 text-sm">
                                <div>
                                    <p className="font-medium text-foreground">{service.name}</p>
                                    <p className="text-muted-foreground">
                                        {t(':n minutes', { n: service.duration_minutes })}
                                    </p>
                                </div>
                                <p className="tabular-nums font-medium text-foreground">
                                    {formatPrice(service.price)}
                                </p>
                            </div>
                        </SummarySection>
                    )}

                    {/* Invitations */}
                    {invitations.length > 0 && (
                        <SummarySection title={t('Team invites')} editStep={4}>
                            <ul className="flex flex-col gap-1 text-sm">
                                {invitations.map((inv, i) => (
                                    <li key={i} className="text-muted-foreground">
                                        {inv.email}
                                    </li>
                                ))}
                            </ul>
                        </SummarySection>
                    )}
                </CardPanel>
                <CardFooter>
                    <Button
                        type="button"
                        onClick={launch}
                        size="xl"
                        loading={processing}
                        disabled={processing}
                        className="h-12 w-full text-sm sm:h-12"
                    >
                        <Display className="tracking-tight">
                            {t('Launch your booking page →')}
                        </Display>
                    </Button>
                </CardFooter>
            </Card>
        </OnboardingLayout>
    );
}

function SummarySection({
    title,
    editStep,
    children,
}: {
    title: string;
    editStep: number;
    children: React.ReactNode;
}) {
    const { t } = useTrans();

    return (
        <section className="flex flex-col gap-3">
            <div className="flex items-center justify-between gap-2 border-b border-border/60 pb-2">
                <h3 className="text-[11px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                    {title}
                </h3>
                <button
                    type="button"
                    onClick={() => router.visit(show(editStep))}
                    className="inline-flex items-center gap-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
                >
                    <PencilIcon className="size-3" />
                    {t('Edit')}
                </button>
            </div>
            {children}
        </section>
    );
}

function SummaryRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="contents">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-foreground">{value}</dd>
        </div>
    );
}
