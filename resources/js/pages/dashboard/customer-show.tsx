import { Link, usePage } from '@inertiajs/react';
import { index as customersIndex } from '@/actions/App/Http/Controllers/Dashboard/CustomerController';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { BookingStatusBadge } from '@/components/dashboard/booking-status-badge';
import { useTrans } from '@/hooks/use-trans';
import { Display } from '@/components/ui/display';
import { Frame } from '@/components/ui/frame';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateMedium, formatDateTimeLong } from '@/lib/datetime-format';
import { getInitials } from '@/lib/booking-format';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import type { CustomerBookingHistory, DashboardCustomerDetail, PageProps } from '@/types';

interface CustomerShowPageProps extends PageProps {
    customer: DashboardCustomerDetail;
    stats: {
        total_bookings: number;
        first_booking_at: string | null;
        last_booking_at: string | null;
    };
    bookings: CustomerBookingHistory[];
}

export default function CustomerShowPage() {
    const { customer, stats, bookings } = usePage<CustomerShowPageProps>().props;
    const { t } = useTrans();

    return (
        <AuthenticatedLayout title={customer.name}>
            <div className="flex flex-col gap-8">
                <Link
                    href={customersIndex.url()}
                    className="inline-flex items-center gap-1.5 text-[11px] uppercase tracking-[0.22em] text-muted-foreground transition-colors hover:text-foreground"
                >
                    ← {t('All customers')}
                </Link>

                <header className="flex flex-col gap-5 sm:flex-row sm:items-start sm:gap-6">
                    <Avatar className="size-14 shrink-0 rounded-xl border border-border bg-muted sm:size-16">
                        <AvatarFallback className="rounded-xl bg-muted font-display text-base font-semibold text-muted-foreground sm:text-lg">
                            {getInitials(customer.name) || '·'}
                        </AvatarFallback>
                    </Avatar>
                    <div className="flex min-w-0 flex-col gap-2">
                        <p className="text-[11px] uppercase tracking-[0.22em] text-muted-foreground">
                            {t('Customer')}
                        </p>
                        <Display
                            render={<h1 />}
                            className="text-[clamp(1.5rem,1.2rem+0.9vw,1.875rem)] font-semibold leading-[1.05] text-foreground"
                        >
                            {customer.name}
                        </Display>
                        <div className="flex flex-wrap gap-x-5 gap-y-1 text-sm text-muted-foreground">
                            <span>{customer.email}</span>
                            {customer.phone && (
                                <>
                                    <span className="text-rule-strong" aria-hidden="true">·</span>
                                    <span>{customer.phone}</span>
                                </>
                            )}
                        </div>
                    </div>
                </header>

                <section>
                    <h2 className="sr-only">{t('Visit summary')}</h2>
                    <dl className="grid grid-cols-3 divide-x divide-border border-y border-border">
                        <div className="flex flex-col gap-1 py-4 sm:py-5">
                            <dt className="text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                                {t('Total visits')}
                            </dt>
                            <dd>
                                <Display className="text-2xl font-semibold tabular-nums leading-none text-foreground sm:text-3xl">
                                    {stats.total_bookings}
                                </Display>
                            </dd>
                        </div>
                        <div className="flex flex-col gap-1 py-4 ps-5 sm:ps-6 sm:py-5">
                            <dt className="text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                                {t('First visit')}
                            </dt>
                            <dd className="font-display tabular-nums text-sm text-foreground">
                                {formatDateMedium(stats.first_booking_at)}
                            </dd>
                        </div>
                        <div className="flex flex-col gap-1 py-4 ps-5 sm:ps-6 sm:py-5">
                            <dt className="text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                                {t('Last visit')}
                            </dt>
                            <dd className="font-display tabular-nums text-sm text-foreground">
                                {formatDateMedium(stats.last_booking_at)}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section className="flex flex-col gap-4">
                    <div className="flex items-center gap-3">
                        <h2 className="text-[11px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                            {t('Booking history')}
                        </h2>
                        <span className="h-px flex-1 bg-border" aria-hidden="true" />
                    </div>
                    <Frame className="w-full">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-56">{t('When')}</TableHead>
                                    <TableHead>{t('Service')}</TableHead>
                                    <TableHead>{t('With')}</TableHead>
                                    <TableHead className="text-right">
                                        {t('Status')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {bookings.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={4}
                                            className="py-10 text-center"
                                        >
                                            <p className="text-sm text-muted-foreground">
                                                {t('No bookings on file yet.')}
                                            </p>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    bookings.map((booking) => (
                                        <TableRow key={booking.id}>
                                            <TableCell className="font-display tabular-nums text-sm">
                                                {formatDateTimeLong(booking.starts_at)}
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {booking.service.name}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground text-sm">
                                                {booking.provider.name}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <BookingStatusBadge status={booking.status} />
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </Frame>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
