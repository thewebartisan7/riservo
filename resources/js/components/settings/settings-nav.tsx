import { Link } from '@inertiajs/react';
import { useTrans } from '@/hooks/use-trans';
import { cn } from '@/lib/utils';

const navGroups = [
    {
        label: 'Business',
        items: [
            { label: 'Profile', href: '/dashboard/settings/profile' },
            { label: 'Booking', href: '/dashboard/settings/booking' },
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
            { label: 'Collaborators', href: '/dashboard/settings/collaborators' },
        ],
    },
    {
        label: 'Share',
        items: [
            { label: 'Embed & Share', href: '/dashboard/settings/embed' },
        ],
    },
];

export function SettingsNav() {
    const { t } = useTrans();
    const currentPath = window.location.pathname;

    return (
        <nav aria-label={t('Settings navigation')} className="flex flex-col gap-5">
            {navGroups.map((group) => (
                <div key={group.label} className="flex flex-col gap-1.5">
                    <p className="px-2 text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                        {t(group.label)}
                    </p>
                    <ul className="flex flex-col gap-0.5">
                        {group.items.map((item) => {
                            const isActive =
                                currentPath === item.href ||
                                (item.href !== '/dashboard/settings/profile' &&
                                    currentPath.startsWith(item.href));

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
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ))}
        </nav>
    );
}
