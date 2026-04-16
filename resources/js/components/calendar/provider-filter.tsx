import type { CalendarProvider } from '@/types';
import type { ProviderColor } from '@/lib/calendar-colors';
import { useTrans } from '@/hooks/use-trans';

interface ProviderFilterProps {
    providers: CalendarProvider[];
    visibleIds: Set<number>;
    colorMap: Map<number, ProviderColor>;
    onToggle: (id: number) => void;
    onToggleAll: () => void;
}

export function ProviderFilter({
    providers,
    visibleIds,
    colorMap,
    onToggle,
    onToggleAll,
}: ProviderFilterProps) {
    const { t } = useTrans();
    const allVisible = visibleIds.size === providers.length;

    return (
        <div className="flex flex-wrap items-center gap-1.5" role="group" aria-label={t('Filter by provider')}>
            <button
                type="button"
                onClick={onToggleAll}
                aria-pressed={allVisible}
                className="inline-flex h-7 items-center gap-1.5 rounded-full border border-transparent bg-secondary px-3 text-xs font-medium text-secondary-foreground transition-colors hover:bg-accent/60 aria-pressed:border-primary/30 aria-pressed:bg-honey-soft aria-pressed:text-primary-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background"
            >
                {t('All')}
            </button>
            {providers.map((provider) => {
                const color = colorMap.get(provider.id);
                const isVisible = visibleIds.has(provider.id);

                return (
                    <button
                        key={provider.id}
                        type="button"
                        onClick={() => onToggle(provider.id)}
                        aria-pressed={isVisible}
                        className="inline-flex h-7 items-center gap-1.5 rounded-full border border-transparent bg-secondary/60 px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent/60 aria-pressed:border-border/80 aria-pressed:bg-background aria-pressed:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background"
                    >
                        <span
                            aria-hidden="true"
                            className={`size-2 rounded-full transition-opacity ${color?.dot ?? 'bg-muted-foreground'} ${isVisible ? 'opacity-100' : 'opacity-40'}`}
                        />
                        {provider.name}
                    </button>
                );
            })}
        </div>
    );
}
