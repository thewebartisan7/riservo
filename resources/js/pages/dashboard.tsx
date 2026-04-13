import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { Link, usePage } from '@inertiajs/react';
import { index as bookingsIndex } from '@/actions/App/Http/Controllers/Dashboard/BookingController';
import { useTrans } from '@/hooks/use-trans';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import type { DashboardStats, PageProps, TodayBooking } from '@/types';

interface DashboardPageProps extends PageProps {
    stats: DashboardStats;
    todayBookings: TodayBooking[];
    timezone: string;
}

function formatTime(isoString: string, timezone: string): string {
    return new Date(isoString).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: timezone,
    });
}

function StatusBadge({ status }: { status: string }) {
    const variant =
        status === 'confirmed'
            ? 'success'
            : status === 'pending'
              ? 'warning'
              : status === 'cancelled'
                ? 'destructive'
                : status === 'no_show'
                  ? 'error'
                  : 'secondary';

    const label =
        status === 'no_show'
            ? 'No Show'
            : status.charAt(0).toUpperCase() + status.slice(1);

    return <Badge variant={variant}>{label}</Badge>;
}

export default function Dashboard() {
    const { stats, todayBookings, timezone } = usePage<DashboardPageProps>().props;
    const { t } = useTrans();

    const statCards = [
        { label: t('Today'), value: stats.today_count },
        { label: t('This Week'), value: stats.week_count },
        { label: t('Upcoming'), value: stats.upcoming_count },
        { label: t('Pending'), value: stats.pending_count },
    ];

    return (
        <AuthenticatedLayout title={t('Dashboard')}>
            <div className="space-y-6">
                {/* Stats */}
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    {statCards.map((stat) => (
                        <Card key={stat.label}>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-muted-foreground text-sm font-medium">
                                    {stat.label}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">
                                    {stat.value}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Today's Appointments */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle>{t("Today's Appointments")}</CardTitle>
                        <Link
                            href={bookingsIndex.url()}
                            className="text-sm text-primary hover:underline"
                        >
                            {t('View all')}
                        </Link>
                    </CardHeader>
                    <CardContent>
                        {todayBookings.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                {t('No appointments scheduled for today.')}
                            </p>
                        ) : (
                            <div className="space-y-3">
                                {todayBookings.map((booking) => (
                                    <div
                                        key={booking.id}
                                        className="flex items-center justify-between rounded-md border p-3"
                                    >
                                        <div className="flex items-center gap-4">
                                            <div className="text-sm font-medium">
                                                {formatTime(booking.starts_at, timezone)}
                                            </div>
                                            <div>
                                                <p className="text-sm font-medium">
                                                    {booking.customer.name}
                                                </p>
                                                <p className="text-muted-foreground text-xs">
                                                    {booking.service.name}{' '}
                                                    &middot;{' '}
                                                    {booking.collaborator.name}
                                                </p>
                                            </div>
                                        </div>
                                        <StatusBadge status={booking.status} />
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
