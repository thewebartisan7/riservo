import type { CalendarCollaborator } from '@/types';
import type { CollaboratorColor } from '@/lib/calendar-colors';
import { useTrans } from '@/hooks/use-trans';

interface CollaboratorFilterProps {
    collaborators: CalendarCollaborator[];
    visibleIds: Set<number>;
    colorMap: Map<number, CollaboratorColor>;
    onToggle: (id: number) => void;
    onToggleAll: () => void;
}

export function CollaboratorFilter({
    collaborators,
    visibleIds,
    colorMap,
    onToggle,
    onToggleAll,
}: CollaboratorFilterProps) {
    const { t } = useTrans();
    const allVisible = visibleIds.size === collaborators.length;

    return (
        <div className="flex flex-wrap items-center gap-1.5" role="group" aria-label={t('Filter by collaborator')}>
            <button
                type="button"
                onClick={onToggleAll}
                aria-pressed={allVisible}
                className="inline-flex h-7 items-center gap-1.5 rounded-full border border-transparent bg-secondary px-3 text-xs font-medium text-secondary-foreground transition-colors hover:bg-accent/60 aria-pressed:border-primary/30 aria-pressed:bg-honey-soft aria-pressed:text-primary-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background"
            >
                {t('All')}
            </button>
            {collaborators.map((collaborator) => {
                const color = colorMap.get(collaborator.id);
                const isVisible = visibleIds.has(collaborator.id);

                return (
                    <button
                        key={collaborator.id}
                        type="button"
                        onClick={() => onToggle(collaborator.id)}
                        aria-pressed={isVisible}
                        className="inline-flex h-7 items-center gap-1.5 rounded-full border border-transparent bg-secondary/60 px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent/60 aria-pressed:border-border/80 aria-pressed:bg-background aria-pressed:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background"
                    >
                        <span
                            aria-hidden="true"
                            className={`size-2 rounded-full transition-opacity ${color?.dot ?? 'bg-muted-foreground'} ${isVisible ? 'opacity-100' : 'opacity-40'}`}
                        />
                        {collaborator.name}
                    </button>
                );
            })}
        </div>
    );
}
