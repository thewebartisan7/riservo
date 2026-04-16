import { useEffect, useState } from 'react';
import { useHttp } from '@inertiajs/react';
import { providers as providersAction } from '@/actions/App/Http/Controllers/Booking/PublicBookingController';
import { useTrans } from '@/hooks/use-trans';
import type { PublicProvider } from '@/types';
import { Users } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Display } from '@/components/ui/display';
import { Skeleton } from '@/components/ui/skeleton';
import { getInitials } from '@/lib/booking-format';

interface ProviderPickerProps {
    slug: string;
    serviceId: number;
    onSelect: (provider: PublicProvider | null) => void;
}

export default function ProviderPicker({
    slug,
    serviceId,
    onSelect,
}: ProviderPickerProps) {
    const { t } = useTrans();
    const [providers, setProviders] = useState<PublicProvider[]>([]);
    const http = useHttp({});

    useEffect(() => {
        http.get(providersAction.url(slug, { query: { service_id: serviceId } }), {
            onSuccess: (response: unknown) => {
                const data = response as { providers: PublicProvider[] };
                setProviders(data.providers);
            },
        });
    }, [slug, serviceId]);

    const heading = (
        <div>
            <Display
                render={<h2 />}
                className="text-2xl font-semibold leading-tight text-foreground"
            >
                {t('Who would you like to see?')}
            </Display>
            <p className="mt-1.5 text-sm text-muted-foreground">
                {t("Pick a specialist or let us assign whoever's free.")}
            </p>
        </div>
    );

    if (http.processing) {
        return (
            <div className="flex flex-col gap-6">
                {heading}
                <div className="flex flex-col gap-2">
                    {[1, 2, 3].map((i) => (
                        <Skeleton key={i} className="h-[72px] rounded-xl" />
                    ))}
                </div>
            </div>
        );
    }

    function ProviderButton({
        provider,
        isAny,
    }: {
        provider?: PublicProvider;
        isAny?: boolean;
    }) {
        return (
            <button
                type="button"
                onClick={() => onSelect(provider ?? null)}
                className="group flex w-full items-center gap-4 rounded-xl border border-border bg-background px-4 py-3.5 text-left transition-all hover:border-primary hover:bg-honey-soft focus-visible:border-primary focus-visible:shadow-[0_0_0_3px_var(--ring)] focus-visible:outline-none"
            >
                <Avatar className="h-11 w-11 shrink-0">
                    {!isAny && provider?.avatar_url && (
                        <AvatarImage src={provider.avatar_url} alt={provider.name} />
                    )}
                    <AvatarFallback className="font-display bg-accent text-sm font-semibold text-secondary-foreground">
                        {isAny ? (
                            <Users className="h-4 w-4" aria-hidden />
                        ) : provider ? (
                            getInitials(provider.name)
                        ) : (
                            ''
                        )}
                    </AvatarFallback>
                </Avatar>
                <div className="min-w-0 flex-1">
                    <Display
                        render={<p />}
                        className="text-base font-semibold text-foreground"
                    >
                        {isAny ? t('Any specialist') : provider?.name}
                    </Display>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {isAny
                            ? t('We pick the best match.')
                            : t('Available for this service.')}
                    </p>
                </div>
                <span
                    className="text-lg leading-none text-muted-foreground transition-transform group-hover:translate-x-0.5"
                    aria-hidden
                >
                    →
                </span>
            </button>
        );
    }

    return (
        <div className="flex flex-col gap-6">
            {heading}
            <div className="flex flex-col gap-2.5">
                <ProviderButton isAny />
                {providers.map((provider) => (
                    <ProviderButton key={provider.id} provider={provider} />
                ))}
            </div>
        </div>
    );
}
