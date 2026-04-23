import { Link, usePage } from '@inertiajs/react';
import BookingLayout from '@/layouts/booking-layout';
import { Card, CardPanel } from '@/components/ui/card';
import { Display } from '@/components/ui/display';
import { useTrans } from '@/hooks/use-trans';
import type { PageProps } from '@/types';

interface PaymentSuccessProps extends PageProps {
    state: 'processing';
    booking: {
        token: string;
        business: { name: string };
    };
}

/**
 * PAYMENTS Session 2a return landing (Inertia-rendered).
 *
 * The controller only renders this page when the synchronous Stripe
 * `checkout.sessions.retrieve` returned a session whose `payment_status`
 * is NOT yet `paid` — the async-pending branch. For the common happy
 * path the controller 302-redirects to `/bookings/{token}` directly.
 */
export default function BookingPaymentSuccess() {
    const { t } = useTrans();
    const { booking } = usePage<PaymentSuccessProps>().props;

    return (
        <BookingLayout>
            <div className="mx-auto max-w-md px-4 py-12">
                <Card>
                    <CardPanel className="flex flex-col items-center gap-4 p-8 text-center">
                        <div
                            className="h-10 w-10 animate-spin rounded-full border-2 border-muted border-t-primary"
                            role="status"
                            aria-label={t('Processing')}
                        />
                        <Display render={<h1 />} className="text-xl font-semibold text-foreground">
                            {t('Processing payment')}
                        </Display>
                        <p className="text-sm leading-normal text-muted-foreground">
                            {t(
                                "We're confirming your payment with Stripe. This usually takes a moment. You'll receive a confirmation email shortly.",
                            )}
                        </p>
                        <Link
                            href={`/bookings/${booking.token}`}
                            className="text-sm font-medium text-primary underline-offset-4 hover:underline"
                        >
                            {t('Check booking status')}
                        </Link>
                    </CardPanel>
                </Card>
            </div>
        </BookingLayout>
    );
}
