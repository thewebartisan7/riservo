import SettingsLayout from '@/layouts/settings-layout';
import { Button } from '@/components/ui/button';
import { Frame, FrameHeader } from '@/components/ui/frame';
import { Display } from '@/components/ui/display';
import { useTrans } from '@/hooks/use-trans';
import { Link } from '@inertiajs/react';
import {
    create,
    edit,
} from '@/actions/App/Http/Controllers/Dashboard/Settings/ServiceController';
import { ArrowRightIcon, PlusIcon } from 'lucide-react';

interface ServiceItem {
    id: number;
    name: string;
    slug: string;
    duration_minutes: number;
    price: number | null;
    is_active: boolean;
    bookings_count: number;
    providers: { id: number; name: string }[];
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

    function formatDuration(minutes: number): string {
        if (minutes < 60) return t(':m min').replace(':m', String(minutes));
        const h = Math.floor(minutes / 60);
        const m = minutes % 60;
        if (m === 0) return t(':h h').replace(':h', String(h));
        return t(':h h :m').replace(':h', String(h)).replace(':m', String(m));
    }

    return (
        <SettingsLayout
            title={t('Services')}
            eyebrow={t('Settings · Team')}
            heading={t('Services')}
            description={t('Every treatment you offer. Duration, price, and who performs it — customers book from this list.')}
            actions={
                <Button render={<Link href={create.url()} />}>
                    <PlusIcon />
                    {t('New service')}
                </Button>
            }
        >
            {services.length === 0 ? (
                <Frame>
                    <FrameHeader className="items-center gap-2 py-12 text-center">
                        <p className="text-sm text-foreground">{t('No services yet.')}</p>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            {t('Start with a single treatment. You can add descriptions, prices, and team assignments afterwards.')}
                        </p>
                    </FrameHeader>
                </Frame>
            ) : (
                <ul className="flex flex-col divide-y divide-border/70 border-y border-border/70">
                    {services.map((service) => (
                        <li key={service.id}>
                            <Link
                                href={edit.url(service.id)}
                                className="group flex items-start gap-4 py-4 transition-colors hover:bg-muted/40 sm:items-center sm:gap-6"
                            >
                                <div className="flex min-w-0 flex-1 flex-col gap-1">
                                    <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                        <Display className="text-sm font-medium text-foreground">
                                            {service.name}
                                        </Display>
                                        {!service.is_active && (
                                            <span className="text-[10px] font-medium uppercase tracking-[0.2em] text-muted-foreground">
                                                {t('Inactive')}
                                            </span>
                                        )}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                                        <span className="font-display tabular-nums">
                                            {formatDuration(service.duration_minutes)}
                                        </span>
                                        <span aria-hidden="true" className="size-0.5 rounded-full bg-muted-foreground/40" />
                                        <span className="font-display tabular-nums">
                                            {formatPrice(service.price)}
                                        </span>
                                        {service.providers.length > 0 && (
                                            <>
                                                <span aria-hidden="true" className="size-0.5 rounded-full bg-muted-foreground/40" />
                                                <span className="truncate">
                                                    {service.providers.map((p) => p.name).join(', ')}
                                                </span>
                                            </>
                                        )}
                                    </div>
                                </div>
                                <div className="flex shrink-0 items-center gap-4">
                                    <span className="text-right font-display text-xs tabular-nums text-muted-foreground">
                                        {t(':n bookings', { n: service.bookings_count })}
                                    </span>
                                    <ArrowRightIcon
                                        aria-hidden="true"
                                        className="size-4 text-muted-foreground/60 transition-all group-hover:translate-x-0.5 group-hover:text-foreground"
                                    />
                                </div>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </SettingsLayout>
    );
}
