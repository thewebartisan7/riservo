import GuestLayout from '@/layouts/guest-layout';
import { Card, CardHeader, CardTitle, CardPanel } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/hooks/use-trans';
import { Link, useForm, usePage } from '@inertiajs/react';
import type { BookingSummary, PageProps } from '@/types';
import type { FormEvent } from 'react';

function BookingItem({ booking }: { booking: BookingSummary }) {
    const { t } = useTrans();
    const form = useForm({});
    const date = new Date(booking.starts_at);
    const endDate = new Date(booking.ends_at);

    function cancel(e: FormEvent) {
        e.preventDefault();
        form.post(`/my-bookings/${booking.id}/cancel`);
    }

    return (
        <div className="flex items-center justify-between rounded-lg border p-4">
            <div className="flex flex-col gap-1">
                <span className="font-medium">{booking.service.name}</span>
                <span className="text-sm text-muted-foreground">
                    {booking.business.name} &middot; {booking.collaborator.name}
                </span>
                <span className="text-sm text-muted-foreground">
                    {date.toLocaleDateString()} &middot;{' '}
                    {date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                    {' - '}
                    {endDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                </span>
                <span className="text-xs capitalize text-muted-foreground">{booking.status}</span>
            </div>
            {booking.can_cancel && (
                <form onSubmit={cancel}>
                    <Button type="submit" variant="outline" size="sm" disabled={form.processing}>
                        {t('Cancel')}
                    </Button>
                </form>
            )}
        </div>
    );
}

export default function CustomerBookings() {
    const { t } = useTrans();
    const { upcoming, past, flash } = usePage<PageProps & { upcoming: BookingSummary[]; past: BookingSummary[] }>().props;

    return (
        <GuestLayout title={t('My Bookings')}>
            <div className="flex flex-col gap-6">
                {flash.success && (
                    <p className="text-sm text-green-600">{flash.success}</p>
                )}
                {flash.error && (
                    <p className="text-sm text-destructive-foreground">{flash.error}</p>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>{t('Upcoming')}</CardTitle>
                    </CardHeader>
                    <CardPanel className="flex flex-col gap-3">
                        {upcoming.length === 0 ? (
                            <p className="text-sm text-muted-foreground">{t('No upcoming bookings')}</p>
                        ) : (
                            upcoming.map((booking) => (
                                <BookingItem key={booking.id} booking={booking} />
                            ))
                        )}
                    </CardPanel>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('Past')}</CardTitle>
                    </CardHeader>
                    <CardPanel className="flex flex-col gap-3">
                        {past.length === 0 ? (
                            <p className="text-sm text-muted-foreground">{t('No past bookings')}</p>
                        ) : (
                            past.map((booking) => (
                                <BookingItem key={booking.id} booking={booking} />
                            ))
                        )}
                    </CardPanel>
                </Card>

                <div className="flex justify-center">
                    <Link href="/logout" method="post" as="button" className="text-sm text-muted-foreground hover:underline">
                        {t('Log out')}
                    </Link>
                </div>
            </div>
        </GuestLayout>
    );
}
