import { useHttp } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/Booking/PublicBookingController';
import { useTrans } from '@/hooks/use-trans';
import type { BookingStoreResponse, PublicProvider, PublicService } from '@/types';
import type { CustomerData } from './customer-form';
import { Button } from '@/components/ui/button';
import { Card, CardPanel } from '@/components/ui/card';
import { Display } from '@/components/ui/display';
import {
    formatDateLongWithYear,
    formatDurationFull,
    formatPrice,
} from '@/lib/booking-format';

interface BookingSummaryProps {
    slug: string;
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
    service,
    provider,
    date,
    time,
    customer,
    onSuccess,
}: BookingSummaryProps) {
    const { t } = useTrans();

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
    });

    function handleConfirm() {
        http.setData('website', getHoneypotValue());
        http.post(store.url(slug), {
            onSuccess: (response: unknown) => {
                const result = response as BookingStoreResponse;
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
                <Display className="tracking-tight">{t('Confirm booking')} →</Display>
            </Button>

            <p className="text-center text-xs leading-normal text-muted-foreground">
                {t('You will receive a confirmation by email. You can reschedule or cancel from that message.')}
            </p>
        </div>
    );
}
