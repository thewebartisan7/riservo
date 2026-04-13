import { Button } from '@/components/ui/button';
import { Card, CardPanel } from '@/components/ui/card';
import { useTrans } from '@/hooks/use-trans';
import type { PublicService } from '@/types';

interface BookingConfirmationProps {
    status: string;
    token: string;
    service: PublicService;
    date: string;
    time: string;
    onBookAnother: () => void;
}

function formatDate(dateStr: string): string {
    const [y, m, d] = dateStr.split('-');
    return `${d}.${m}.${y}`;
}

export default function BookingConfirmation({
    status,
    token,
    service,
    date,
    time,
    onBookAnother,
}: BookingConfirmationProps) {
    const { t } = useTrans();

    const isConfirmed = status === 'confirmed';

    return (
        <div className="flex flex-col items-center gap-4 text-center">
            <div className="flex h-16 w-16 items-center justify-center rounded-full bg-primary/10 text-3xl">
                {isConfirmed ? '\u2713' : '\u231B'}
            </div>

            <div>
                <h2 className="text-lg font-semibold">
                    {isConfirmed
                        ? t('Booking confirmed!')
                        : t('Booking received!')}
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    {isConfirmed
                        ? t('Your appointment has been confirmed. A confirmation email has been sent.')
                        : t('Your booking is pending confirmation. You will receive an email once confirmed.')}
                </p>
            </div>

            <Card className="w-full">
                <CardPanel className="text-sm">
                    <div className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                        <span className="text-muted-foreground">{t('Service')}</span>
                        <span className="font-medium">{service.name}</span>

                        <span className="text-muted-foreground">{t('Date')}</span>
                        <span>{formatDate(date)}</span>

                        <span className="text-muted-foreground">{t('Time')}</span>
                        <span>{time}</span>
                    </div>
                </CardPanel>
            </Card>

            <div className="flex w-full flex-col gap-2">
                <a
                    href={`/bookings/${token}`}
                    className="inline-flex h-9 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                >
                    {t('View booking details')}
                </a>
                <Button variant="outline" onClick={onBookAnother}>
                    {t('Book another appointment')}
                </Button>
            </div>
        </div>
    );
}
