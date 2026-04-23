import { useState } from 'react';
import { useHttp } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/Booking/PublicBookingController';
import { useTrans } from '@/hooks/use-trans';
import type { BookingStoreResponse, PublicBusiness, PublicProvider, PublicService } from '@/types';
import type { CustomerData } from './customer-form';
import { Button } from '@/components/ui/button';
import { Card, CardPanel } from '@/components/ui/card';
import { Display } from '@/components/ui/display';
import {
    formatDateLongWithYear,
    formatDurationFull,
    formatPrice,
} from '@/lib/booking-format';

type PaymentChoice = 'online' | 'offline';

interface BookingSummaryProps {
    slug: string;
    business: PublicBusiness;
    service: PublicService;
    provider: PublicProvider | null;
    date: string;
    time: string;
    customer: CustomerData;
    onBack: () => void;
    onSuccess: (result: { token: string; status: string }) => void;
}

export default function BookingSummary({
    slug,
    business,
    service,
    provider,
    date,
    time,
    customer,
    onSuccess,
}: BookingSummaryProps) {
    const { t } = useTrans();

    // PAYMENTS Session 2a: online-payment eligibility (locked decision #8).
    // Price null / 0 always falls through to the offline path regardless
    // of Business.payment_mode. `can_accept_online_payments` also folds in
    // Stripe capability + country (D-127, D-138).
    const servicePriceEligible = service.price !== null && Number(service.price) > 0;
    const onlinePaymentAvailable =
        business.can_accept_online_payments && servicePriceEligible;

    const isOnlineMode = onlinePaymentAvailable && business.payment_mode === 'online';
    const isCustomerChoiceMode =
        onlinePaymentAvailable && business.payment_mode === 'customer_choice';

    // customer_choice default pick = 'online' per the roadmap UX — the
    // typical commercial intent of enabling customer_choice is to steer
    // customers toward prepaying while keeping the pay-on-site door open.
    const [paymentChoice, setPaymentChoice] = useState<PaymentChoice>('online');

    // Which branch the server will take, mirrored client-side so the CTA
    // copy + caption match the actual outcome.
    const willRedirectToStripe =
        isOnlineMode || (isCustomerChoiceMode && paymentChoice === 'online');

    const getHoneypotValue = () => {
        const el = document.getElementById('booking-hp') as HTMLInputElement | null;
        return el?.value ?? '';
    };

    const http = useHttp({
        service_id: service.id,
        provider_id: provider?.id ?? (null as number | null),
        date,
        time,
        name: customer.name,
        email: customer.email,
        phone: customer.phone,
        notes: customer.notes || '',
        website: '',
        // `payment_choice` is only forwarded in customer_choice mode.
        // Pure-online and pure-offline businesses ignore the field
        // server-side, but sending it anyway is harmless.
        payment_choice: isCustomerChoiceMode ? paymentChoice : (null as PaymentChoice | null),
    });

    function handleConfirm() {
        http.setData('website', getHoneypotValue());
        http.post(store.url(slug), {
            onSuccess: (response: unknown) => {
                const result = response as BookingStoreResponse;

                // Codex Round 2 (D-161): dispatch on the explicit
                // `external_redirect` boolean the server sends. An earlier
                // `https://` prefix heuristic would match HTTPS-deployed
                // riservo internal URLs too — skipping the confirmation
                // step and hard-navigating for every booking.
                if (result.external_redirect) {
                    window.location.href = result.redirect_url;
                    return;
                }

                onSuccess(result);
            },
        });
    }

    const rows: [string, string][] = [
        [t('Service'), service.name],
        [t('Duration'), formatDurationFull(service.duration_minutes, t)],
        [t('Price'), formatPrice(service.price, t)],
        [t('Specialist'), provider?.name ?? t('Any available')],
        [t('Date'), formatDateLongWithYear(date)],
        [t('Time'), time],
    ];

    return (
        <div className="flex flex-col gap-7">
            <div>
                <Display
                    render={<h2 />}
                    className="text-2xl font-semibold leading-tight text-foreground"
                >
                    {t('Everything in order?')}
                </Display>
                <p className="mt-1.5 text-sm text-muted-foreground">
                    {t('One final look before we send it through.')}
                </p>
            </div>

            {/* Receipt-like summary */}
            <Card>
                <CardPanel className="p-0">
                    <dl>
                        {rows.map(([label, value], i) => (
                            <div
                                key={label}
                                className={`flex items-baseline justify-between gap-4 px-5 py-3.5 ${
                                    i > 0 ? 'border-t border-border' : ''
                                }`}
                            >
                                <dt className="text-xs uppercase tracking-widest text-muted-foreground">
                                    {label}
                                </dt>
                                <dd className="tabular-nums text-right text-sm text-foreground">
                                    {value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </CardPanel>
            </Card>

            {/* Customer summary */}
            <Card className="bg-muted">
                <CardPanel className="p-5">
                    <p className="text-xs uppercase tracking-widest text-muted-foreground">
                        {t('Contact')}
                    </p>
                    <Display
                        render={<p />}
                        className="mt-2 text-base font-semibold text-foreground"
                    >
                        {customer.name}
                    </Display>
                    <p className="tabular-nums mt-0.5 text-sm text-secondary-foreground">
                        {customer.email}
                    </p>
                    {customer.phone && (
                        <p className="tabular-nums text-sm text-secondary-foreground">
                            {customer.phone}
                        </p>
                    )}
                    {customer.notes && (
                        <p className="mt-3 border-t border-border pt-3 text-sm italic leading-normal text-secondary-foreground">
                            "{customer.notes}"
                        </p>
                    )}
                </CardPanel>
            </Card>

            {isCustomerChoiceMode && (
                <Card>
                    <CardPanel className="p-5">
                        <p className="text-xs uppercase tracking-widest text-muted-foreground">
                            {t('Payment')}
                        </p>
                        <div
                            className="mt-3 grid grid-cols-2 gap-2"
                            role="radiogroup"
                            aria-label={t('Choose how to pay')}
                        >
                            <button
                                type="button"
                                role="radio"
                                aria-checked={paymentChoice === 'online'}
                                onClick={() => setPaymentChoice('online')}
                                className={`rounded-lg border px-3 py-2.5 text-sm transition-colors ${
                                    paymentChoice === 'online'
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'border-border bg-background text-foreground hover:bg-muted'
                                }`}
                            >
                                {t('Pay now')}
                            </button>
                            <button
                                type="button"
                                role="radio"
                                aria-checked={paymentChoice === 'offline'}
                                onClick={() => setPaymentChoice('offline')}
                                className={`rounded-lg border px-3 py-2.5 text-sm transition-colors ${
                                    paymentChoice === 'offline'
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'border-border bg-background text-foreground hover:bg-muted'
                                }`}
                            >
                                {t('Pay on site')}
                            </button>
                        </div>
                    </CardPanel>
                </Card>
            )}

            {http.hasErrors && (
                <div className="rounded-lg border border-primary bg-honey-soft px-4 py-3 text-sm text-primary-foreground">
                    {t('This time slot is no longer available. Please select another time.')}
                </div>
            )}

            <Button
                variant="default"
                size="xl"
                className="h-12 sm:h-12 text-sm"
                loading={http.processing}
                onClick={handleConfirm}
            >
                <Display className="tracking-tight">
                    {willRedirectToStripe ? t('Continue to payment') : t('Confirm booking')} →
                </Display>
            </Button>

            <p className="text-center text-xs leading-normal text-muted-foreground">
                {willRedirectToStripe
                    ? t('You will be redirected to a secure Stripe page to complete payment.')
                    : t('You will receive a confirmation by email. You can reschedule or cancel from that message.')}
            </p>
        </div>
    );
}
