import { useHttp } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardPanel } from '@/components/ui/card';
import { useTrans } from '@/hooks/use-trans';
import type { PublicCollaborator, PublicService } from '@/types';
import type { CustomerData } from './customer-form';

interface BookingSummaryProps {
    slug: string;
    service: PublicService;
    collaborator: PublicCollaborator | null;
    date: string;
    time: string;
    customer: CustomerData;
    onBack: () => void;
    onSuccess: (result: { token: string; status: string }) => void;
}

function formatPrice(price: number | null, t: (key: string) => string): string {
    if (price === null) return t('Price on request');
    if (price === 0) return t('Free');
    return `CHF ${Number(price).toFixed(2)}`;
}

function formatDate(dateStr: string): string {
    const [y, m, d] = dateStr.split('-');
    return `${d}.${m}.${y}`;
}

export default function BookingSummary({
    slug,
    service,
    collaborator,
    date,
    time,
    customer,
    onBack,
    onSuccess,
}: BookingSummaryProps) {
    const { t } = useTrans();

    // Read honeypot value
    const getHoneypotValue = () => {
        const el = document.getElementById('booking-hp') as HTMLInputElement | null;
        return el?.value ?? '';
    };

    const http = useHttp({
        service_id: service.id,
        collaborator_id: collaborator?.id ?? (null as number | null),
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
        http.post(`/booking/${slug}/book`, {
            onSuccess: (response: unknown) => {
                const result = response as { token: string; status: string };
                onSuccess(result);
            },
        });
    }

    return (
        <div className="flex flex-col gap-4">
            <h2 className="text-lg font-semibold">{t('Confirm your booking')}</h2>

            <Card>
                <CardPanel className="flex flex-col gap-2 text-sm">
                    <div className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                        <span className="text-muted-foreground">{t('Service')}</span>
                        <span className="font-medium">{service.name}</span>

                        <span className="text-muted-foreground">{t('Duration')}</span>
                        <span>{service.duration_minutes} {t('min')}</span>

                        <span className="text-muted-foreground">{t('Price')}</span>
                        <span>{formatPrice(service.price, t)}</span>

                        <span className="text-muted-foreground">{t('With')}</span>
                        <span>{collaborator?.name ?? t('To be assigned')}</span>

                        <span className="text-muted-foreground">{t('Date')}</span>
                        <span>{formatDate(date)}</span>

                        <span className="text-muted-foreground">{t('Time')}</span>
                        <span>{time}</span>
                    </div>

                    <div className="mt-2 border-t pt-2">
                        <p className="font-medium">{customer.name}</p>
                        <p className="text-muted-foreground">{customer.email}</p>
                        {customer.phone && (
                            <p className="text-muted-foreground">{customer.phone}</p>
                        )}
                        {customer.notes && (
                            <p className="mt-1 text-muted-foreground">{customer.notes}</p>
                        )}
                    </div>
                </CardPanel>
            </Card>

            {http.hasErrors && (
                <p className="text-sm text-destructive">
                    {t('This time slot is no longer available. Please select another time.')}
                </p>
            )}

            <div className="flex gap-3">
                <Button variant="outline" onClick={onBack}>
                    {t('Back')}
                </Button>
                <Button className="flex-1" onClick={handleConfirm} disabled={http.processing}>
                    {http.processing ? t('Booking...') : t('Confirm booking')}
                </Button>
            </div>
        </div>
    );
}
