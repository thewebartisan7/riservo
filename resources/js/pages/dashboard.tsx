import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { Link, usePage } from '@inertiajs/react';
import { index as bookingsIndex } from '@/actions/App/Http/Controllers/Dashboard/BookingController';
import { useTrans } from '@/hooks/use-trans';
import { Display } from '@/components/ui/display';
import { Frame, FrameHeader } from '@/components/ui/frame';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { BookingStatusBadge } from '@/components/dashboard/booking-status-badge';
import { CalendarPendingActionsSection } from '@/components/dashboard/calendar-pending-actions-section';
import { formatTimeShort } from '@/lib/datetime-format';
import { ArrowUpRightIcon, CalendarDaysIcon } from 'lucide-react';
import type { CalendarPendingAction, DashboardStats, PageProps, TodayBooking } from '@/types';

interface DashboardPageProps extends PageProps {
    stats: DashboardStats;
    todayBookings: TodayBooking[];
    timezone: string;
    calendarPendingActions: CalendarPendingAction[];
}

export default function Dashboard() {
    const { auth, stats, todayBookings, timezone, calendarPendingActions } =
        usePage<DashboardPageProps>().props;
    const { t } = useTrans();

    const firstName = auth.user?.name.split(' ')[0] ?? '';
    const today = new Date().toLocaleDateString([], {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        timeZone: timezone,
    });

    const statCards = [
        { label: t('Today'), value: stats.today_count },
        { label: t('This week'), value: stats.week_count },
        { label: t('Upcoming'), value: stats.upcoming_count },
        { label: t('Awaiting confirmation'), value: stats.pending_count },
    ];

    return (
        <AuthenticatedLayout
            title={t('Dashboard')}
            eyebrow={today}
            heading={
                firstName ? t('Good to see you, :name.', { name: firstName }) : t('Dashboard')
            }
            description={t("A calm look at today — glance, act, move on.")}
        >
            <div className="flex flex-col gap-8">
                <CalendarPendingActionsSection actions={calendarPendingActions} timezone={timezone} />

                <section>
                    <h2 className="sr-only">{t('Overview')}</h2>
                    <dl className="grid grid-cols-2 divide-y divide-border border-y border-border sm:grid-cols-4 sm:divide-x sm:divide-y-0">
                        {statCards.map((stat, idx) => (
                            <div
                                key={stat.label}
                                className={
                                    'flex flex-col gap-1 py-4 sm:py-5 ' +
                                    (idx % 2 === 1 ? 'ps-5 sm:ps-6' : 'pe-5 sm:px-6') +
                                    (idx === 0 ? ' sm:ps-0' : '') +
                                    (idx === statCards.length - 1 ? ' sm:pe-0' : '')
                                }
                            >
                                <dt className="text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                                    {stat.label}
                                </dt>
                                <dd>
                                    <Display className="text-3xl font-semibold tabular-nums leading-none text-foreground sm:text-4xl">
                                        {stat.value}
                                    </Display>
                                </dd>
                            </div>
                        ))}
                    </dl>
                </section>

                <section className="flex flex-col gap-4">
                    <div className="flex items-end justify-between gap-4">
                        <div className="flex flex-col gap-1">
                            <h2 className="text-[11px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                                {t("Today's schedule")}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {todayBookings.length === 0
                                    ? t('Nothing on the books yet.')
                                    : t(':count on the calendar.', { count: todayBookings.length })}
                            </p>
                        </div>
                        <Link
                            href={bookingsIndex.url()}
                            className="inline-flex items-center gap-1 text-[11px] uppercase tracking-[0.22em] text-muted-foreground transition-colors hover:text-foreground"
                        >
                            {t('All bookings')}
                            <ArrowUpRightIcon className="size-3" />
                        </Link>
                    </div>

                    {todayBookings.length === 0 ? (
                        <Frame>
                            <FrameHeader className="items-center gap-2 py-10 text-center">
                                <p className="text-sm text-foreground">
                                    {t('A quiet day.')}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {t('New bookings will appear here as they come in.')}
                                </p>
                            </FrameHeader>
                        </Frame>
                    ) : (
                        <Frame className="w-full">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-24">{t('Time')}</TableHead>
                                        <TableHead>{t('Customer')}</TableHead>
                                        <TableHead>{t('Service')}</TableHead>
                                        <TableHead>{t('With')}</TableHead>
                                        <TableHead className="text-right">{t('Status')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {todayBookings.map((booking) => (
                                        <TableRow key={booking.id}>
                                            <TableCell className="font-display tabular-nums text-sm font-medium">
                                                {formatTimeShort(booking.starts_at, timezone)}
                                            </TableCell>
                                            <TableCell className="text-sm font-medium">
                                                {booking.external ? (
                                                    <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                                                        <CalendarDaysIcon aria-hidden="true" className="size-3.5" />
                                                        {booking.external_title ?? t('External event')}
                                                    </span>
                                                ) : (
                                                    booking.customer?.name ?? t('—')
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground text-sm">
                                                {booking.service?.name ?? (booking.external ? t('External') : t('—'))}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground text-sm">
                                                {booking.provider.is_active
                                                    ? booking.provider.name
                                                    : t(':name (deactivated)', { name: booking.provider.name })}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <BookingStatusBadge status={booking.status} />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Frame>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
