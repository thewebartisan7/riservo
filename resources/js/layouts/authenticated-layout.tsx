import { Head, Link, usePage } from '@inertiajs/react';
import { index as dashboardIndex } from '@/actions/App/Http/Controllers/Dashboard/DashboardController';
import { index as bookingsIndex } from '@/actions/App/Http/Controllers/Dashboard/BookingController';
import { index as calendarIndex } from '@/actions/App/Http/Controllers/Dashboard/CalendarController';
import { index as customersIndex } from '@/actions/App/Http/Controllers/Dashboard/CustomerController';
import { destroy } from '@/actions/App/Http/Controllers/Auth/LoginController';
import { home } from '@/routes/index';
import type { PropsWithChildren, ReactNode } from 'react';
import type { PageProps } from '@/types';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarInset,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarProvider,
    SidebarRail,
    SidebarTrigger,
} from '@/components/ui/sidebar';
import { Alert, AlertAction, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Display } from '@/components/ui/display';
import { ToastProvider } from '@/components/ui/toast';
import { useTrans } from '@/hooks/use-trans';
import { getInitials } from '@/lib/booking-format';
import { billing as settingsBilling, services as settingsServices } from '@/routes/settings';
import type { SubscriptionState } from '@/types';
import {
    AlertTriangleIcon,
    CalendarDaysIcon,
    ClipboardListIcon,
    HomeIcon,
    LogOutIcon,
    Settings2Icon,
    UsersIcon,
    type LucideIcon,
} from 'lucide-react';

interface AuthenticatedLayoutProps {
    title?: string;
    eyebrow?: string;
    heading?: string;
    description?: string;
    actions?: ReactNode;
    /**
     * riservo: when true, the page renders directly into <main> without the centered
     * padded wrapper. The page owns its own height/width/padding. Default: false.
     * Currently used by the calendar surface to eliminate double scroll.
     */
    fullBleed?: boolean;
}

export default function AuthenticatedLayout({
    title,
    eyebrow,
    heading,
    description,
    actions,
    fullBleed = false,
    children,
}: PropsWithChildren<AuthenticatedLayoutProps>) {
    const { auth, bookability } = usePage<PageProps>().props;
    const { t } = useTrans();
    const isAdmin = auth.role === 'admin';
    const currentPath = window.location.pathname;
    const hasPageHeader = Boolean(eyebrow || heading || description || actions);
    const unbookableServices = bookability?.unbookableServices ?? [];
    const subscription: SubscriptionState | null = auth.business?.subscription ?? null;
    const subscriptionBanner = resolveSubscriptionBanner(subscription, isAdmin, t);
    const connectedAccount = auth.business?.connected_account ?? null;
    // PAYMENTS Session 1 (D-114): dashboard-wide mismatch banner fires when
    // the Business's payment_mode is not offline but the connected account is
    // missing or unverified. Dormant until Session 5 unhides the non-offline
    // options; still wired now so a DB-seeded online value (dogfooding Session
    // 2a) surfaces correctly.
    const paymentModeMismatch = isAdmin && (connectedAccount?.payment_mode_mismatch ?? false);

    const navItems: { label: string; href: string; active: boolean; icon: LucideIcon }[] = [
        { label: t('Dashboard'), href: dashboardIndex.url(), active: currentPath === '/dashboard', icon: HomeIcon },
        { label: t('Bookings'), href: bookingsIndex.url(), active: currentPath.startsWith('/dashboard/bookings'), icon: ClipboardListIcon },
        { label: t('Calendar'), href: calendarIndex.url(), active: currentPath.startsWith('/dashboard/calendar'), icon: CalendarDaysIcon },
        ...(isAdmin
            ? [{ label: t('Customers'), href: customersIndex.url(), active: currentPath.startsWith('/dashboard/customers'), icon: UsersIcon }]
            : []),
        ...(isAdmin
            ? [{ label: t('Settings'), href: '/dashboard/settings/profile', active: currentPath.startsWith('/dashboard/settings'), icon: Settings2Icon }]
            : []),
    ];

    return (
        <ToastProvider>
            {title && <Head title={title} />}
            <SidebarProvider>
                <Sidebar>
                    <SidebarHeader className="px-3 py-4">
                        <Link
                            href={home()}
                            className="inline-flex items-baseline gap-1.5 text-foreground transition-colors hover:text-foreground/80"
                        >
                            <Display className="text-lg font-semibold leading-none">
                                riservo
                            </Display>
                            <span
                                aria-hidden="true"
                                className="size-1 translate-y-[-1px] rounded-full bg-primary"
                            />
                        </Link>
                    </SidebarHeader>
                    <SidebarContent>
                        <SidebarGroup>
                            <SidebarGroupLabel className="text-[10px] font-medium uppercase tracking-[0.22em]">
                                {t('Workspace')}
                            </SidebarGroupLabel>
                            <SidebarGroupContent>
                                <SidebarMenu>
                                    {navItems.map((item) => {
                                        const Icon = item.icon;
                                        return (
                                            <SidebarMenuItem key={item.href}>
                                                <SidebarMenuButton
                                                    isActive={item.active}
                                                    render={
                                                        <Link
                                                            href={item.href}
                                                            prefetch
                                                        />
                                                    }
                                                >
                                                    <Icon aria-hidden="true" strokeWidth={1.75} />
                                                    <span>{item.label}</span>
                                                </SidebarMenuButton>
                                            </SidebarMenuItem>
                                        );
                                    })}
                                </SidebarMenu>
                            </SidebarGroupContent>
                        </SidebarGroup>
                    </SidebarContent>
                    <SidebarFooter>
                        {auth.user && (
                            <SidebarMenu>
                                <SidebarMenuItem>
                                    <SidebarMenuButton className="h-auto py-2">
                                        <Avatar className="size-7 rounded-lg">
                                            <AvatarImage
                                                src={
                                                    auth.user.avatar ??
                                                    undefined
                                                }
                                                alt={auth.user.name}
                                            />
                                            <AvatarFallback className="rounded-lg bg-muted font-display text-[11px] font-semibold text-muted-foreground">
                                                {getInitials(auth.user.name)}
                                            </AvatarFallback>
                                        </Avatar>
                                        <span className="truncate">{auth.user.name}</span>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        className="text-sidebar-foreground/75 hover:text-sidebar-accent-foreground"
                                        render={
                                            <Link
                                                href={destroy()}
                                                method="post"
                                                as="button"
                                            />
                                        }
                                    >
                                        <LogOutIcon aria-hidden="true" strokeWidth={1.75} />
                                        <span>{t('Log out')}</span>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            </SidebarMenu>
                        )}
                    </SidebarFooter>
                    <SidebarRail />
                </Sidebar>
                <SidebarInset>
                    <header className="sticky top-0 z-10 flex h-12 items-center gap-2 border-b border-border/60 bg-background/80 px-4 backdrop-blur-md md:hidden">
                        <SidebarTrigger className="-ms-1" />
                        {title && (
                            <span className="text-[11px] uppercase tracking-[0.22em] text-muted-foreground">
                                {title}
                            </span>
                        )}
                    </header>
                    <main
                        className={
                            fullBleed
                                ? 'flex h-[calc(100svh-3rem)] min-h-0 flex-col md:h-svh'
                                : 'flex-1'
                        }
                    >
                        {subscriptionBanner && (
                            <div
                                className={
                                    fullBleed
                                        ? 'border-b border-border/60 px-5 pb-3 pt-3 sm:px-8'
                                        : 'mx-auto w-full max-w-6xl px-5 pt-5 sm:px-8 sm:pt-8'
                                }
                                data-testid={subscriptionBanner.testId}
                            >
                                <Alert variant={subscriptionBanner.variant}>
                                    <AlertTriangleIcon aria-hidden="true" />
                                    <AlertTitle>{subscriptionBanner.title}</AlertTitle>
                                    <AlertDescription>
                                        <p>{subscriptionBanner.description}</p>
                                    </AlertDescription>
                                    {subscriptionBanner.action && (
                                        <AlertAction>
                                            <Link
                                                href={settingsBilling().url}
                                                className={
                                                    subscriptionBanner.variant === 'error'
                                                        ? 'inline-flex items-center text-xs font-medium uppercase tracking-[0.22em] text-destructive underline-offset-4 hover:underline'
                                                        : 'inline-flex items-center text-xs font-medium uppercase tracking-[0.22em] text-warning underline-offset-4 hover:underline'
                                                }
                                            >
                                                {subscriptionBanner.action === 'resubscribe'
                                                    ? t('Resubscribe')
                                                    : t('Manage')}
                                            </Link>
                                        </AlertAction>
                                    )}
                                </Alert>
                            </div>
                        )}
                        {paymentModeMismatch && (
                            <div
                                className={
                                    fullBleed
                                        ? 'border-b border-border/60 px-5 pb-3 pt-3 sm:px-8'
                                        : 'mx-auto w-full max-w-6xl px-5 pt-5 sm:px-8 sm:pt-8'
                                }
                                data-testid="payment-mode-mismatch-banner"
                            >
                                <Alert variant="warning" role="alert">
                                    <AlertTriangleIcon aria-hidden="true" />
                                    <AlertTitle>{t('Online payments need attention')}</AlertTitle>
                                    <AlertDescription>
                                        <p>
                                            {t(
                                                'Your business is set to accept online payments, but your Stripe connected account is not ready yet. New bookings will fall back to offline until this is resolved.',
                                            )}
                                        </p>
                                    </AlertDescription>
                                    <AlertAction>
                                        <Link
                                            href="/dashboard/settings/connected-account"
                                            className="inline-flex items-center text-xs font-medium uppercase tracking-[0.22em] text-warning underline-offset-4 hover:underline"
                                        >
                                            {t('Resolve in settings')}
                                        </Link>
                                    </AlertAction>
                                </Alert>
                            </div>
                        )}
                        {unbookableServices.length > 0 && (
                            <div
                                className={
                                    fullBleed
                                        ? 'border-b border-border/60 px-5 pb-3 pt-3 sm:px-8'
                                        : 'mx-auto w-full max-w-6xl px-5 pt-5 sm:px-8 sm:pt-8'
                                }
                                data-testid="unbookable-banner"
                            >
                                <Alert variant="warning">
                                    <AlertTriangleIcon aria-hidden="true" />
                                    <AlertTitle>
                                        {t("Some services can't be booked yet")}
                                    </AlertTitle>
                                    <AlertDescription>
                                        <p>
                                            {t(
                                                "Customers can't see these services until a provider with available hours is assigned:",
                                            )}
                                        </p>
                                        <p className="font-medium text-foreground">
                                            {unbookableServices
                                                .map((service) => service.name)
                                                .join(', ')}
                                        </p>
                                    </AlertDescription>
                                    <AlertAction>
                                        <Link
                                            href={settingsServices().url}
                                            className="inline-flex items-center text-xs font-medium uppercase tracking-[0.22em] text-warning underline-offset-4 hover:underline"
                                        >
                                            {t('Fix')}
                                        </Link>
                                    </AlertAction>
                                </Alert>
                            </div>
                        )}
                        {fullBleed ? (
                            <div className="flex min-h-0 flex-1 flex-col animate-rise">
                                {children}
                            </div>
                        ) : (
                            <div className="mx-auto w-full max-w-6xl px-5 pb-16 pt-5 sm:px-8 sm:pt-8">
                                {hasPageHeader && (
                                    <div className="mb-6 flex flex-col gap-4 sm:mb-8 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                        <div className="flex min-w-0 flex-1 flex-col gap-2">
                                            {eyebrow && (
                                                <p className="text-[11px] uppercase tracking-[0.22em] text-muted-foreground">
                                                    {eyebrow}
                                                </p>
                                            )}
                                            {heading && (
                                                <Display
                                                    render={<h1 />}
                                                    className="text-[clamp(1.5rem,1.2rem+0.9vw,1.875rem)] font-semibold leading-[1.05] text-foreground"
                                                >
                                                    {heading}
                                                </Display>
                                            )}
                                            {description && (
                                                <p className="max-w-xl text-balance text-sm leading-relaxed text-muted-foreground">
                                                    {description}
                                                </p>
                                            )}
                                        </div>
                                        {actions && (
                                            <div className="flex shrink-0 items-center gap-2">
                                                {actions}
                                            </div>
                                        )}
                                    </div>
                                )}
                                <div className="animate-rise">{children}</div>
                            </div>
                        )}
                    </main>
                </SidebarInset>
            </SidebarProvider>
        </ToastProvider>
    );
}

type BannerSpec = {
    variant: 'warning' | 'error';
    title: string;
    description: string;
    action: 'manage' | 'resubscribe' | null;
    testId: string;
};

function resolveSubscriptionBanner(
    subscription: SubscriptionState | null,
    isAdmin: boolean,
    t: (s: string) => string,
): BannerSpec | null {
    if (subscription === null) {
        return null;
    }

    switch (subscription.status) {
        case 'past_due':
            return {
                variant: 'warning',
                title: t('Your last payment failed'),
                description: t(
                    'Stripe is retrying. Update your payment method to avoid losing access.',
                ),
                action: isAdmin ? 'manage' : null,
                testId: 'subscription-past-due-banner',
            };
        case 'canceled':
            return {
                variant: 'warning',
                title: t('Your subscription is set to cancel'),
                description: t(
                    'You still have access until the end of the current billing period. Resume any time before then.',
                ),
                action: isAdmin ? 'manage' : null,
                testId: 'subscription-canceled-banner',
            };
        case 'read_only':
            return {
                variant: 'error',
                title: t('Your subscription has ended'),
                description: t(
                    'The dashboard is read-only until you resubscribe. Existing bookings remain visible to your customers.',
                ),
                action: isAdmin ? 'resubscribe' : null,
                testId: 'subscription-read-only-banner',
            };
        default:
            return null;
    }
}
