import { Link, usePage } from '@inertiajs/react';
import { index as customersIndex } from '@/actions/App/Http/Controllers/Dashboard/CustomerController';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { BookingStatusBadge } from '@/components/dashboard/booking-status-badge';
import { useTrans } from '@/hooks/use-trans';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Frame, FrameHeader } from '@/components/ui/frame';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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

function formatDate(isoString: string | null): string {
    if (!isoString) return '—';
    return new Date(isoString).toLocaleDateString([], { dateStyle: 'medium' });
}

function formatDateTime(isoString: string): string {
    return new Date(isoString).toLocaleString([], {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function CustomerShowPage() {
    const { customer, stats, bookings } = usePage<CustomerShowPageProps>().props;
    const { t } = useTrans();

    return (
        <AuthenticatedLayout title={customer.name}>
            <div className="space-y-6">
                {/* Back link */}
                <div>
                    <Link href={customersIndex.url()}>
                        <Button variant="ghost" size="sm">
                            &larr; {t('Back to Customers')}
                        </Button>
                    </Link>
                </div>

                {/* Customer Info + Stats */}
                <div className="grid gap-4 sm:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Contact Information')}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">{t('Name')}</span>
                                <span className="font-medium">{customer.name}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">{t('Email')}</span>
                                <span>{customer.email}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">{t('Phone')}</span>
                                <span>{customer.phone || '—'}</span>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Booking Stats')}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">{t('Total Bookings')}</span>
                                <span className="font-medium">{stats.total_bookings}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">{t('First Visit')}</span>
                                <span>{formatDate(stats.first_booking_at)}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">{t('Last Visit')}</span>
                                <span>{formatDate(stats.last_booking_at)}</span>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Booking History */}
                <Frame className="w-full">
                    <FrameHeader>
                        <h3 className="text-base font-semibold">{t('Booking History')}</h3>
                    </FrameHeader>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('Date / Time')}</TableHead>
                                <TableHead>{t('Service')}</TableHead>
                                <TableHead>{t('Collaborator')}</TableHead>
                                <TableHead>{t('Status')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {bookings.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="text-muted-foreground py-8 text-center"
                                    >
                                        {t('No bookings found.')}
                                    </TableCell>
                                </TableRow>
                            ) : (
                                bookings.map((booking) => (
                                    <TableRow key={booking.id}>
                                        <TableCell className="text-sm">
                                            {formatDateTime(booking.starts_at)}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {booking.service.name}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {booking.collaborator.name}
                                        </TableCell>
                                        <TableCell>
                                            <BookingStatusBadge
                                                status={booking.status}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </Frame>
            </div>
        </AuthenticatedLayout>
    );
}
