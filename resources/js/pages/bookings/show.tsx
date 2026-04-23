import GuestLayout from '@/layouts/guest-layout';
import { Card, CardHeader, CardTitle, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/hooks/use-trans';
import { Form, usePage } from '@inertiajs/react';
import { cancel } from '@/actions/App/Http/Controllers/Booking/BookingManagementController';
import { formatDateMedium, formatTimeShort } from '@/lib/datetime-format';
import type { BookingDetail, PageProps } from '@/types';

export default function BookingShow() {
    const { t } = useTrans();
    const { booking, flash } = usePage<PageProps & { booking: BookingDetail }>().props;

    const tz = booking.business.timezone;
    const providerLabel = booking.provider.is_active
        ? booking.provider.name
        : t(':name (deactivated)', { name: booking.provider.name });

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
                        <span>{providerLabel}</span>

                        <span className="text-muted-foreground">{t('Date')}</span>
                        <span>{formatDateMedium(booking.starts_at, tz)}</span>

                        <span className="text-muted-foreground">{t('Time')}</span>
                        <span>
                            {formatTimeShort(booking.starts_at, tz)}
                            {' - '}
                            {formatTimeShort(booking.ends_at, tz)}
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

                    {booking.payment.status !== 'not_applicable' && (
                        <div className="mt-2 border-t border-border pt-3 text-sm">
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">{t('Payment')}</span>
                                <span className="font-medium capitalize">
                                    {booking.payment.status.replace(/_/g, ' ')}
                                </span>
                            </div>
                            {booking.payment.status === 'paid' &&
                                booking.payment.paid_amount_cents !== null &&
                                booking.payment.currency !== null && (
                                    <div className="mt-1 flex items-center justify-between">
                                        <span className="text-muted-foreground">{t('Amount paid')}</span>
                                        <span className="tabular-nums">
                                            {booking.payment.currency.toUpperCase()}{' '}
                                            {(booking.payment.paid_amount_cents / 100).toFixed(2)}
                                        </span>
                                    </div>
                                )}
                            {booking.payment.status === 'awaiting_payment' &&
                                booking.payment.stripe_checkout_session_id !== null && (
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        {t("We're waiting for your payment to complete. Check your email for the Stripe receipt; your booking will confirm automatically once payment settles.")}
                                    </p>
                                )}
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
