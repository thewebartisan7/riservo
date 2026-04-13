import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { useTrans } from '@/hooks/use-trans';
import { Link } from '@inertiajs/react';
import {
    create,
    edit,
} from '@/actions/App/Http/Controllers/Dashboard/Settings/ServiceController';

interface ServiceItem {
    id: number;
    name: string;
    slug: string;
    duration_minutes: number;
    price: number | null;
    is_active: boolean;
    bookings_count: number;
    collaborators: { id: number; name: string }[];
}

interface Props {
    services: ServiceItem[];
}

export default function Services({ services }: Props) {
    const { t } = useTrans();

    function formatPrice(price: number | null): string {
        if (price === null) return t('On request');
        if (price === 0) return t('Free');
        return `CHF ${Number(price).toFixed(2)}`;
    }

    return (
        <SettingsLayout title={t('Services')}>
            <Card>
                <CardHeader className="flex flex-row items-center justify-between">
                    <div>
                        <CardTitle>{t('Services')}</CardTitle>
                        <CardDescription>{t('Manage the services your business offers')}</CardDescription>
                    </div>
                    <Button render={<Link href={create.url()} />}>{t('Add Service')}</Button>
                </CardHeader>
                <CardPanel>
                    {services.length === 0 ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">{t('No services yet.')}</p>
                    ) : (
                        <div className="divide-y">
                            {services.map((service) => (
                                <Link
                                    key={service.id}
                                    href={edit.url(service.id)}
                                    className="flex items-center justify-between py-3 hover:bg-accent/50 -mx-4 px-4 rounded-lg transition-colors"
                                >
                                    <div className="flex flex-col gap-1">
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm font-medium">{service.name}</span>
                                            {!service.is_active && (
                                                <Badge variant="secondary">{t('Inactive')}</Badge>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-3 text-xs text-muted-foreground">
                                            <span>{service.duration_minutes} {t('min')}</span>
                                            <span>{formatPrice(service.price)}</span>
                                            {service.collaborators.length > 0 && (
                                                <span>
                                                    {service.collaborators.map((c) => c.name).join(', ')}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                    <span className="text-xs text-muted-foreground">
                                        {service.bookings_count} {t('bookings')}
                                    </span>
                                </Link>
                            ))}
                        </div>
                    )}
                </CardPanel>
            </Card>
        </SettingsLayout>
    );
}
