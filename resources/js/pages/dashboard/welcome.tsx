import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { Card, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Display } from '@/components/ui/display';
import { Avatar, AvatarImage, AvatarFallback } from '@/components/ui/avatar';
import { useTrans } from '@/hooks/use-trans';
import { Link } from '@inertiajs/react';
import { dashboard } from '@/routes/index';
import { services as settingsServices, staff as settingsStaff, booking as settingsBooking } from '@/routes/settings';
import { useState } from 'react';
import {
    CheckIcon,
    ClipboardIcon,
    ArrowRightIcon,
    ArrowUpRightIcon,
} from 'lucide-react';
import { getInitials } from '@/lib/booking-format';

interface Props {
    publicUrl: string;
    businessName: string;
    logoUrl?: string | null;
}

export default function Welcome({ publicUrl, businessName, logoUrl = null }: Props) {
    const { t } = useTrans();
    const [copied, setCopied] = useState(false);

    function copyUrl() {
        navigator.clipboard.writeText(publicUrl);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }

    const initials = getInitials(businessName) || '·';
    const displayUrl = publicUrl.replace(/^https?:\/\//, '');

    const nextSteps = [
        {
            eyebrow: '01',
            title: t('Shape your services'),
            description: t(
                'Add treatments, adjust durations, set prices. The richer the menu, the smoother the booking.',
            ),
            href: settingsServices().url,
        },
        {
            eyebrow: '02',
            title: t('Invite your team'),
            description: t(
                'Send invites so each team member manages their own calendar and customers.',
            ),
            href: settingsStaff().url,
        },
        {
            eyebrow: '03',
            title: t('Tune your reminders'),
            description: t(
                'Set when confirmation and reminder emails go out, in your tone of voice.',
            ),
            href: settingsBooking().url,
        },
    ];

    return (
        <AuthenticatedLayout
            title={t('Welcome')}
            eyebrow={t('All set')}
            heading={t(":business is live.", { business: businessName })}
            description={t(
                'Your booking page is ready for customers. Share the link, then take a moment to refine the details when you have time.',
            )}
        >
            <div className="flex flex-col gap-8">
                <Card>
                    <CardPanel className="flex flex-col gap-5 p-5 sm:p-6">
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
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                            <Avatar className="size-12 shrink-0 rounded-xl border border-border bg-muted">
                                {logoUrl && (
                                    <AvatarImage
                                        src={logoUrl}
                                        alt=""
                                        className="rounded-xl object-cover"
                                    />
                                )}
                                <AvatarFallback className="rounded-xl bg-muted font-display text-sm font-semibold text-muted-foreground">
                                    {initials}
                                </AvatarFallback>
                            </Avatar>
                            <div className="min-w-0 flex-1">
                                <Display className="block truncate text-base font-semibold text-foreground">
                                    {displayUrl}
                                </Display>
                                <p className="truncate text-xs text-muted-foreground">
                                    {t('Share this link wherever your customers find you.')}
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={copyUrl}
                                className={copied ? 'w-full text-primary sm:w-auto' : 'w-full sm:w-auto'}
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
                    </CardPanel>
                    <CardFooter className="justify-end border-t bg-muted/72 px-5 py-3 sm:px-6">
                        <Link
                            href={dashboard()}
                            className="inline-flex items-center gap-2 text-sm font-medium text-foreground underline-offset-4 hover:underline"
                        >
                            {t('Open your dashboard')}
                            <ArrowRightIcon className="size-3.5" />
                        </Link>
                    </CardFooter>
                </Card>

                <section className="flex flex-col gap-4">
                    <div className="flex items-center gap-3">
                        <h2 className="text-[11px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                            {t('Next up')}
                        </h2>
                        <span className="h-px flex-1 bg-border" aria-hidden="true" />
                    </div>
                    <ul className="flex flex-col divide-y divide-border/70 border-y border-border/70">
                        {nextSteps.map((step) => (
                            <li key={step.eyebrow}>
                                <Link
                                    href={step.href}
                                    className="group flex items-start gap-5 py-4 transition-colors hover:bg-muted/40"
                                >
                                    <span className="font-display text-sm tabular-nums text-muted-foreground/80">
                                        {step.eyebrow}
                                    </span>
                                    <span className="flex flex-1 flex-col gap-1">
                                        <span className="text-sm font-medium text-foreground">
                                            {step.title}
                                        </span>
                                        <span className="max-w-xl text-sm leading-relaxed text-muted-foreground">
                                            {step.description}
                                        </span>
                                    </span>
                                    <ArrowRightIcon
                                        aria-hidden="true"
                                        className="mt-1 size-4 shrink-0 text-muted-foreground/60 transition-all group-hover:translate-x-0.5 group-hover:text-foreground"
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
