import type { ReactElement } from 'react';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';
import { useTrans } from '@/hooks/use-trans';
import { formatTimeShort } from '@/lib/datetime-format';
import type { DashboardBooking } from '@/types';
import type { ProviderColor } from '@/lib/calendar-colors';

interface BookingHoverCardProps {
    booking: DashboardBooking;
    color: ProviderColor;
    timezone: string;
    /** Single React element; Base UI Tooltip.Trigger injects props via render. */
    children: ReactElement;
    /** When true, renders only children without a tooltip wrapper. */
    disabled?: boolean;
}

const STATUS_TONE: Record<string, string> = {
    pending: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
    confirmed: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
    completed: 'bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-200',
    cancelled: 'bg-rose-100 text-rose-900 dark:bg-rose-950 dark:text-rose-200',
    no_show: 'bg-muted text-muted-foreground',
};

/**
 * Compact hover-card around a CalendarEvent. Shows service / customer /
 * start–end time / status chip. Uses the COSS UI Tooltip primitive so the
 * positioning and portal behaviour match the rest of the app. Click-through
 * is preserved — the child still opens the detail sheet on click.
 */
export function BookingHoverCard({
    booking,
    color,
    timezone,
    children,
    disabled = false,
}: BookingHoverCardProps) {
    const { t } = useTrans();

    if (disabled) {
        return children;
    }

    const startTime = formatTimeShort(booking.starts_at, timezone);
    const endTime = formatTimeShort(booking.ends_at, timezone);
    const statusTone = STATUS_TONE[booking.status] ?? STATUS_TONE.pending;
    const title = booking.service?.name ?? booking.external_title ?? t('External event');
    const customer = booking.customer?.name ?? (booking.external ? t('External') : null);

    return (
        <Tooltip>
            <TooltipTrigger render={children} />
            <TooltipContent className="min-w-48 max-w-64 p-3" side="right" sideOffset={8}>
                <div className="flex flex-col gap-2">
                    <div className="flex items-start gap-2">
                        <span
                            aria-hidden="true"
                            className={`mt-1 size-2 shrink-0 rounded-full ${color.dot}`}
                        />
                        <div className="flex min-w-0 flex-1 flex-col">
                            <span className="truncate text-sm font-semibold text-foreground">
                                {title}
                            </span>
                            {customer && (
                                <span className="truncate text-xs text-muted-foreground">
                                    {customer}
                                </span>
                            )}
                        </div>
                    </div>
                    <dl className="grid grid-cols-[auto_1fr] gap-x-2 gap-y-1 text-xs">
                        <dt className="text-muted-foreground">{t('Time')}</dt>
                        <dd className="tabular-nums text-foreground">
                            {startTime} – {endTime}
                        </dd>
                        <dt className="text-muted-foreground">{t('With')}</dt>
                        <dd className="truncate text-foreground">
                            {booking.provider.is_active
                                ? booking.provider.name
                                : t(':name (deactivated)', { name: booking.provider.name })}
                        </dd>
                    </dl>
                    <span
                        className={`inline-flex w-max items-center rounded-full px-2 py-0.5 text-[10px] font-medium uppercase tracking-[0.1em] ${statusTone}`}
                    >
                        {t(booking.status)}
                    </span>
                </div>
            </TooltipContent>
        </Tooltip>
    );
}
