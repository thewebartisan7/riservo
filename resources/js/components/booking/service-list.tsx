import { useTrans } from '@/hooks/use-trans';
import type { PublicService } from '@/types';
import { ArrowUpRight } from 'lucide-react';
import { Display } from '@/components/ui/display';
import { formatDurationShort, formatPriceValue } from '@/lib/booking-format';

interface ServiceListProps {
    services: PublicService[];
    onSelect: (service: PublicService) => void;
}

export default function ServiceList({ services, onSelect }: ServiceListProps) {
    const { t } = useTrans();

    if (services.length === 0) {
        return (
            <div className="rounded-lg border border-dashed border-rule-strong px-6 py-12 text-center">
                <Display
                    render={<p />}
                    className="text-lg text-secondary-foreground"
                >
                    {t('Nothing to book just yet.')}
                </Display>
                <p className="mt-2 text-sm text-muted-foreground">
                    {t('Please check back soon.')}
                </p>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-6">
            <div>
                <Display
                    render={<h2 />}
                    className="text-2xl font-semibold leading-tight text-foreground"
                >
                    {t('Choose a service')}
                </Display>
                <p className="mt-1.5 text-sm text-muted-foreground">
                    {services.length === 1
                        ? t('One service available.')
                        : t(':count services available.', { count: services.length })}
                </p>
            </div>

            <ul className="overflow-hidden rounded-xl border border-border bg-background">
                {services.map((service, i) => {
                    const showPrice = formatPriceValue(service.price, t);
                    const isFreeOrRequest =
                        service.price === null || service.price === 0;
                    return (
                        <li
                            key={service.id}
                            className={i > 0 ? 'border-t border-border' : undefined}
                        >
                            <button
                                type="button"
                                onClick={() => onSelect(service)}
                                className="group relative flex w-full items-center gap-5 px-5 py-5 text-left transition-colors hover:bg-muted focus-visible:bg-muted focus-visible:shadow-[inset_0_0_0_2px_var(--ring)] focus-visible:outline-none sm:px-6"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-baseline gap-2.5">
                                        <Display
                                            render={<p />}
                                            className="text-lg font-semibold tracking-tight text-foreground"
                                        >
                                            {service.name}
                                        </Display>
                                        <span className="tabular-nums text-xs text-muted-foreground">
                                            {formatDurationShort(service.duration_minutes, t)}
                                        </span>
                                    </div>
                                    {service.description && (
                                        <p className="mt-1.5 line-clamp-2 text-sm leading-normal text-secondary-foreground">
                                            {service.description}
                                        </p>
                                    )}
                                </div>

                                <div className="flex shrink-0 items-center gap-4">
                                    {isFreeOrRequest ? (
                                        <span className="text-sm text-secondary-foreground">
                                            {showPrice}
                                        </span>
                                    ) : (
                                        <span className="flex items-baseline gap-1">
                                            <span className="text-xs uppercase tracking-widest text-muted-foreground">
                                                CHF
                                            </span>
                                            <Display className="tabular-nums text-lg font-semibold text-foreground">
                                                {showPrice}
                                            </Display>
                                        </span>
                                    )}
                                    <ArrowUpRight
                                        className="h-4 w-4 text-muted-foreground transition-all duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5"
                                        aria-hidden
                                    />
                                </div>
                            </button>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
