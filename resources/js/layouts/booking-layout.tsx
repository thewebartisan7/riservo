import { Head } from '@inertiajs/react';
import { home } from '@/routes/index';
import type { PropsWithChildren, ReactNode } from 'react';
import { useTrans } from '@/hooks/use-trans';
import { MapPin, Phone, Clock3 } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Display } from '@/components/ui/display';
import { getInitials } from '@/lib/booking-format';

interface BookingLayoutProps {
    title?: string;
    businessName: string;
    businessLogoUrl?: string | null;
    businessDescription?: string | null;
    businessAddress?: string | null;
    businessPhone?: string | null;
    businessTimezone?: string | null;
    stepIndicator?: ReactNode;
    embed?: boolean;
}

export default function BookingLayout({
    title,
    businessName,
    businessLogoUrl,
    businessDescription,
    businessAddress,
    businessPhone,
    businessTimezone,
    stepIndicator,
    embed,
    children,
}: PropsWithChildren<BookingLayoutProps>) {
    const { t } = useTrans();

    const identity = (
        <div className="flex items-start gap-4">
            <Avatar className="h-12 w-12 shrink-0 ring-1 ring-border">
                {businessLogoUrl && (
                    <AvatarImage src={businessLogoUrl} alt={businessName} />
                )}
                <AvatarFallback className="font-display bg-accent text-base font-semibold text-secondary-foreground">
                    {getInitials(businessName)}
                </AvatarFallback>
            </Avatar>
            <div className="min-w-0 flex-1">
                <p className="text-xs uppercase tracking-widest text-muted-foreground">
                    {t('Book an appointment')}
                </p>
                <Display
                    render={<h1 />}
                    className="mt-1 text-[clamp(1.75rem,1.3rem+1.6vw,2.5rem)] font-semibold leading-[1.05] text-foreground"
                >
                    {businessName}
                </Display>
            </div>
        </div>
    );

    if (embed) {
        return (
            <>
                {title && <Head title={title} />}
                <div className="flex min-h-screen flex-col">
                    <main className="mx-auto w-full max-w-xl flex-1 px-5 pt-6 pb-10">
                        <div className="mb-6">{identity}</div>
                        {stepIndicator && <div className="mb-5">{stepIndicator}</div>}
                        {children}
                    </main>
                </div>
            </>
        );
    }

    return (
        <>
            {title && <Head title={title} />}
            <div className="min-h-screen">
                <div className="mx-auto grid min-h-screen w-full max-w-6xl grid-cols-1 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
                    <aside className="relative flex flex-col justify-between border-b border-border bg-muted lg:border-b-0 lg:border-r">
                        <div className="px-6 pt-10 pb-8 sm:px-10 lg:px-12 lg:pt-16 lg:pb-12">
                            {identity}
                            {businessDescription && (
                                <p className="mt-6 max-w-[36ch] text-sm leading-relaxed text-secondary-foreground">
                                    {businessDescription}
                                </p>
                            )}

                            {(businessAddress || businessPhone || businessTimezone) && (
                                <dl className="mt-8 flex flex-col gap-2.5 text-sm">
                                    {businessAddress && (
                                        <div className="flex gap-3">
                                            <MapPin
                                                className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground"
                                                aria-hidden
                                            />
                                            <dd className="text-secondary-foreground">
                                                {businessAddress}
                                            </dd>
                                        </div>
                                    )}
                                    {businessPhone && (
                                        <div className="flex gap-3">
                                            <Phone
                                                className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground"
                                                aria-hidden
                                            />
                                            <dd className="tabular-nums text-secondary-foreground">
                                                {businessPhone}
                                            </dd>
                                        </div>
                                    )}
                                    {businessTimezone && (
                                        <div className="flex gap-3">
                                            <Clock3
                                                className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground"
                                                aria-hidden
                                            />
                                            <dd className="text-secondary-foreground">
                                                {businessTimezone.replace('_', ' ')}
                                            </dd>
                                        </div>
                                    )}
                                </dl>
                            )}
                        </div>

                        <div className="hidden px-12 pb-10 lg:block">
                            <a
                                href={home.url()}
                                className="group inline-flex items-center gap-1.5 text-xs uppercase tracking-widest text-muted-foreground transition-colors hover:text-foreground"
                            >
                                <span>{t('Powered by')}</span>
                                <Display className="font-semibold text-secondary-foreground">
                                    riservo
                                </Display>
                            </a>
                        </div>
                    </aside>

                    <section className="relative flex min-h-full flex-col">
                        <div className="flex-1 px-6 pt-8 pb-12 sm:px-10 lg:px-14 lg:pt-16">
                            {stepIndicator && (
                                <div className="mb-8">{stepIndicator}</div>
                            )}
                            <div className="animate-rise max-w-[480px]">{children}</div>
                        </div>
                        <div className="px-6 pb-8 sm:px-10 lg:hidden">
                            <a
                                href={home.url()}
                                className="inline-flex items-center gap-1.5 text-xs uppercase tracking-widest text-muted-foreground"
                            >
                                <span>{t('Powered by')}</span>
                                <Display className="font-semibold text-secondary-foreground">
                                    riservo
                                </Display>
                            </a>
                        </div>
                    </section>
                </div>
            </div>
        </>
    );
}
