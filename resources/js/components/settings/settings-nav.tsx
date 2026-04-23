import { Link, usePage } from '@inertiajs/react';
import { useTrans } from '@/hooks/use-trans';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface NavItem {
    label: string;
    href: string;
    badgeKey?: 'calendarPendingActionsCount';
    requiresActiveProvider?: boolean;
}

interface NavGroup {
    label: string;
    items: NavItem[];
}

const accountItem: NavItem = {
    label: 'Account',
    href: '/dashboard/settings/account',
};

const availabilityItem: NavItem = {
    label: 'Availability',
    href: '/dashboard/settings/availability',
    requiresActiveProvider: true,
};

const calendarIntegrationItem: NavItem = {
    label: 'Calendar Integration',
    href: '/dashboard/settings/calendar-integration',
    badgeKey: 'calendarPendingActionsCount',
};

// Admin sees the union of admin-only items and shared items. Shared items are
// grouped under "You"; admin-only items keep their existing groupings (D-081,
// extended in D-096 to add Availability under "You").
const adminGroups: NavGroup[] = [
    {
        label: 'You',
        items: [accountItem, availabilityItem, calendarIntegrationItem],
    },
    {
        label: 'Business',
        items: [
            { label: 'Profile', href: '/dashboard/settings/profile' },
            { label: 'Booking', href: '/dashboard/settings/booking' },
            { label: 'Online Payments', href: '/dashboard/settings/connected-account' },
            { label: 'Billing', href: '/dashboard/settings/billing' },
        ],
    },
    {
        label: 'Schedule',
        items: [
            { label: 'Working Hours', href: '/dashboard/settings/hours' },
            { label: 'Exceptions', href: '/dashboard/settings/exceptions' },
        ],
    },
    {
        label: 'Team',
        items: [
            { label: 'Services', href: '/dashboard/settings/services' },
            { label: 'Staff', href: '/dashboard/settings/staff' },
        ],
    },
    {
        label: 'Share',
        items: [
            { label: 'Embed & Share', href: '/dashboard/settings/embed' },
        ],
    },
];

// Staff users only see the shared (admin+staff) settings pages (D-081, D-096).
const staffGroups: NavGroup[] = [
    {
        label: 'You',
        items: [accountItem, availabilityItem, calendarIntegrationItem],
    },
];

export function SettingsNav() {
    const { t } = useTrans();
    const page = usePage<PageProps>();
    const role = page.props.auth.role;
    const hasActiveProvider = page.props.auth.has_active_provider;
    const calendarPendingActionsCount = page.props.calendarPendingActionsCount ?? 0;
    const currentPath = window.location.pathname;

    const navGroups = role === 'admin' ? adminGroups : staffGroups;

    return (
        <nav aria-label={t('Settings navigation')} className="flex flex-col gap-5">
            {navGroups.map((group) => {
                const visibleItems = group.items.filter(
                    (item) => !item.requiresActiveProvider || hasActiveProvider,
                );

                if (visibleItems.length === 0) {
                    return null;
                }

                return (
                    <div key={group.label} className="flex flex-col gap-1.5">
                        <p className="px-2 text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                            {t(group.label)}
                        </p>
                        <ul className="flex flex-col gap-0.5">
                            {visibleItems.map((item) => {
                                const isActive =
                                    currentPath === item.href ||
                                    (item.href !== '/dashboard/settings/profile' &&
                                        currentPath.startsWith(item.href));

                                const badgeValue = item.badgeKey === 'calendarPendingActionsCount'
                                    ? calendarPendingActionsCount
                                    : 0;

                                return (
                                    <li key={item.href}>
                                        <Link
                                            href={item.href}
                                            prefetch
                                            className={cn(
                                                'group relative flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm transition-colors',
                                                isActive
                                                    ? 'text-foreground'
                                                    : 'text-muted-foreground hover:text-foreground',
                                            )}
                                            aria-current={isActive ? 'page' : undefined}
                                        >
                                            <span
                                                aria-hidden="true"
                                                className={cn(
                                                    'size-1 shrink-0 rounded-full transition-all',
                                                    isActive
                                                        ? 'bg-primary'
                                                        : 'bg-transparent group-hover:bg-muted-foreground/40',
                                                )}
                                            />
                                            <span className={cn(isActive && 'font-medium')}>
                                                {t(item.label)}
                                            </span>
                                            {badgeValue > 0 && (
                                                <span className="ml-auto inline-flex min-w-5 items-center justify-center rounded-full bg-primary/10 px-1.5 text-[10px] font-semibold tabular-nums text-primary">
                                                    {badgeValue}
                                                </span>
                                            )}
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                );
            })}
        </nav>
    );
}
