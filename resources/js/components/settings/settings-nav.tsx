import { Link } from '@inertiajs/react';
import { useTrans } from '@/hooks/use-trans';
import { cn } from '@/lib/utils';

const navItems = [
    { label: 'Profile', href: '/dashboard/settings/profile' },
    { label: 'Booking', href: '/dashboard/settings/booking' },
    { label: 'Working Hours', href: '/dashboard/settings/hours' },
    { label: 'Exceptions', href: '/dashboard/settings/exceptions' },
    { label: 'Services', href: '/dashboard/settings/services' },
    { label: 'Collaborators', href: '/dashboard/settings/collaborators' },
    { label: 'Embed & Share', href: '/dashboard/settings/embed' },
];

export function SettingsNav() {
    const { t } = useTrans();
    const currentPath = window.location.pathname;

    return (
        <nav className="flex flex-col gap-1">
            {navItems.map((item) => {
                const isActive =
                    currentPath === item.href ||
                    (item.href !== '/dashboard/settings/profile' &&
                        currentPath.startsWith(item.href));

                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        prefetch
                        className={cn(
                            'rounded-md px-3 py-2 text-sm font-medium transition-colors',
                            isActive
                                ? 'bg-accent text-accent-foreground'
                                : 'text-muted-foreground hover:bg-accent/50 hover:text-accent-foreground',
                        )}
                    >
                        {t(item.label)}
                    </Link>
                );
            })}
        </nav>
    );
}
