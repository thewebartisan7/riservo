import GuestLayout from '@/layouts/guest-layout';
import { Card, CardHeader, CardTitle, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/hooks/use-trans';
import { Form, usePage } from '@inertiajs/react';
import { cancel } from '@/actions/App/Http/Controllers/Booking/BookingManagementController';
import type { BookingDetail, PageProps } from '@/types';

export default function BookingShow() {
    const { t } = useTrans();
    const { booking, flash } = usePage<PageProps & { booking: BookingDetail }>().props;

    const date = new Date(booking.starts_at);
    const endDate = new Date(booking.ends_at);

    return (
        <GuestLayout title={t('Booking details')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Booking details')}</CardTitle>
                </CardHeader>
                <CardPanel className="flex flex-col gap-3">
                    {flash.success && (
                        <p className="text-sm text-green-600">{flash.success}</p>
                    )}
                    {flash.error && (
                        <p className="text-sm text-destructive-foreground">{flash.error}</p>
                    )}

                    <div className="grid grid-cols-2 gap-2 text-sm">
                        <span className="text-muted-foreground">{t('Business')}</span>
                        <span>{booking.business.name}</span>

                        <span className="text-muted-foreground">{t('Service')}</span>
                        <span>{booking.service.name}</span>

                        <span className="text-muted-foreground">{t('With')}</span>
                        <span>{booking.provider.name}</span>

                        <span className="text-muted-foreground">{t('Date')}</span>
                        <span>{date.toLocaleDateString()}</span>

                        <span className="text-muted-foreground">{t('Time')}</span>
                        <span>
                            {date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                            {' - '}
                            {endDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                        </span>

                        <span className="text-muted-foreground">{t('Duration')}</span>
                        <span>{booking.service.duration_minutes} {t('min')}</span>

                        <span className="text-muted-foreground">{t('Status')}</span>
                        <span className="capitalize">{booking.status}</span>

                        {booking.service.price !== null && (
                            <>
                                <span className="text-muted-foreground">{t('Price')}</span>
                                <span>
                                    {booking.service.price === 0
                                        ? t('Free')
                                        : `CHF ${Number(booking.service.price).toFixed(2)}`}
                                </span>
                            </>
                        )}
                    </div>

                    {booking.notes && (
                        <div className="text-sm">
                            <span className="text-muted-foreground">{t('Notes')}: </span>
                            <span>{booking.notes}</span>
                        </div>
                    )}
                </CardPanel>
                {booking.can_cancel && (
                    <CardFooter>
                        <Form action={cancel(booking.token)} className="w-full">
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    className="w-full"
                                    disabled={processing}
                                >
                                    {t('Cancel booking')}
                                </Button>
                            )}
                        </Form>
                    </CardFooter>
                )}
            </Card>
        </GuestLayout>
    );
}
