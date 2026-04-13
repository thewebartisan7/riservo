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
        <div className="flex flex-wrap items-center gap-2">
            <button
                type="button"
                onClick={onToggleAll}
                className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors ${
                    allVisible
                        ? 'bg-gray-900 text-white'
                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                }`}
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
                        className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors ${
                            isVisible
                                ? 'bg-gray-100 text-gray-900'
                                : 'bg-gray-50 text-gray-400 hover:bg-gray-100'
                        }`}
                    >
                        <span
                            className={`size-2 rounded-full ${
                                isVisible ? (color?.dot ?? 'bg-gray-400') : 'bg-gray-300'
                            }`}
                        />
                        {collaborator.name}
                    </button>
                );
            })}
        </div>
    );
}
