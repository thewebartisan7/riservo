import { useEffect, useState } from 'react';
import { useHttp } from '@inertiajs/react';
import { collaborators as collaboratorsAction } from '@/actions/App/Http/Controllers/Booking/PublicBookingController';
import { useTrans } from '@/hooks/use-trans';
import type { PublicCollaborator } from '@/types';
import { Users } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Display } from '@/components/ui/display';
import { Skeleton } from '@/components/ui/skeleton';
import { getInitials } from '@/lib/booking-format';

interface CollaboratorPickerProps {
    slug: string;
    serviceId: number;
    onSelect: (collaborator: PublicCollaborator | null) => void;
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
        http.get(collaboratorsAction.url(slug, { query: { service_id: serviceId } }), {
            onSuccess: (response: unknown) => {
                const data = response as { collaborators: PublicCollaborator[] };
                setCollaborators(data.collaborators);
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

    function CollabButton({
        collaborator,
        isAny,
    }: {
        collaborator?: PublicCollaborator;
        isAny?: boolean;
    }) {
        return (
            <button
                type="button"
                onClick={() => onSelect(collaborator ?? null)}
                className="group flex w-full items-center gap-4 rounded-xl border border-border bg-background px-4 py-3.5 text-left transition-all hover:border-primary hover:bg-honey-soft focus-visible:border-primary focus-visible:shadow-[0_0_0_3px_var(--ring)] focus-visible:outline-none"
            >
                <Avatar className="h-11 w-11 shrink-0">
                    {!isAny && collaborator?.avatar_url && (
                        <AvatarImage src={collaborator.avatar_url} alt={collaborator.name} />
                    )}
                    <AvatarFallback className="font-display bg-accent text-sm font-semibold text-secondary-foreground">
                        {isAny ? (
                            <Users className="h-4 w-4" aria-hidden />
                        ) : collaborator ? (
                            getInitials(collaborator.name)
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
                        {isAny ? t('Any specialist') : collaborator?.name}
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
                <CollabButton isAny />
                {collaborators.map((collab) => (
                    <CollabButton key={collab.id} collaborator={collab} />
                ))}
            </div>
        </div>
    );
}
