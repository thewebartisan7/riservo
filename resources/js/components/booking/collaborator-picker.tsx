import { useEffect, useState } from 'react';
import { useHttp } from '@inertiajs/react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useTrans } from '@/hooks/use-trans';
import type { PublicCollaborator } from '@/types';

interface CollaboratorPickerProps {
    slug: string;
    serviceId: number;
    onSelect: (collaborator: PublicCollaborator | null) => void;
}

function getInitials(name: string): string {
    return name
        .split(' ')
        .map((w) => w[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

export default function CollaboratorPicker({
    slug,
    serviceId,
    onSelect,
}: CollaboratorPickerProps) {
    const { t } = useTrans();
    const [collaborators, setCollaborators] = useState<PublicCollaborator[]>([]);
    const http = useHttp({});

    useEffect(() => {
        http.get(`/booking/${slug}/collaborators?service_id=${serviceId}`, {
            onSuccess: (response: unknown) => {
                const data = response as { collaborators: PublicCollaborator[] };
                setCollaborators(data.collaborators);
            },
        });
    }, [slug, serviceId]);

    if (http.processing) {
        return (
            <div className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold">{t('Select a collaborator')}</h2>
                <div className="animate-pulse space-y-3">
                    {[1, 2, 3].map((i) => (
                        <div key={i} className="h-14 rounded-lg bg-muted" />
                    ))}
                </div>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-3">
            <h2 className="text-lg font-semibold">{t('Select a collaborator')}</h2>
            <button
                type="button"
                className="flex items-center gap-3 rounded-lg border p-3 text-left transition-colors hover:bg-accent/50"
                onClick={() => onSelect(null)}
            >
                <Avatar className="h-10 w-10">
                    <AvatarFallback>?</AvatarFallback>
                </Avatar>
                <span className="font-medium">{t('Any available')}</span>
            </button>
            {collaborators.map((collab) => (
                <button
                    key={collab.id}
                    type="button"
                    className="flex items-center gap-3 rounded-lg border p-3 text-left transition-colors hover:bg-accent/50"
                    onClick={() => onSelect(collab)}
                >
                    <Avatar className="h-10 w-10">
                        {collab.avatar_url && (
                            <AvatarImage src={collab.avatar_url} alt={collab.name} />
                        )}
                        <AvatarFallback>{getInitials(collab.name)}</AvatarFallback>
                    </Avatar>
                    <span className="font-medium">{collab.name}</span>
                </button>
            ))}
        </div>
    );
}
