import { Card, CardPanel } from '@/components/ui/card';
import { useTrans } from '@/hooks/use-trans';
import type { PublicService } from '@/types';

interface ServiceListProps {
    services: PublicService[];
    onSelect: (service: PublicService) => void;
}

function formatPrice(price: number | null, t: (key: string) => string): string {
    if (price === null) return t('Price on request');
    if (price === 0) return t('Free');
    return `CHF ${Number(price).toFixed(2)}`;
}

export default function ServiceList({ services, onSelect }: ServiceListProps) {
    const { t } = useTrans();

    if (services.length === 0) {
        return (
            <p className="py-8 text-center text-muted-foreground">
                {t('No services available at this time.')}
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-3">
            <h2 className="text-lg font-semibold">{t('Select a service')}</h2>
            {services.map((service) => (
                <Card
                    key={service.id}
                    className="cursor-pointer transition-colors hover:bg-accent/50"
                    onClick={() => onSelect(service)}
                >
                    <CardPanel className="flex items-center justify-between gap-4">
                        <div className="min-w-0 flex-1">
                            <p className="font-medium">{service.name}</p>
                            {service.description && (
                                <p className="mt-0.5 line-clamp-2 text-sm text-muted-foreground">
                                    {service.description}
                                </p>
                            )}
                        </div>
                        <div className="shrink-0 text-right text-sm">
                            <p className="text-muted-foreground">
                                {service.duration_minutes} {t('min')}
                            </p>
                            <p className="font-medium">
                                {formatPrice(service.price, t)}
                            </p>
                        </div>
                    </CardPanel>
                </Card>
            ))}
        </div>
    );
}
