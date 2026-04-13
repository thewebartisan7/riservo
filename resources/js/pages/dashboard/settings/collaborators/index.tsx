import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useTrans } from '@/hooks/use-trans';
import { Link, router } from '@inertiajs/react';
import {
    show,
    toggleActive,
    resendInvitation,
    cancelInvitation,
} from '@/actions/App/Http/Controllers/Dashboard/Settings/CollaboratorController';
import { CollaboratorInviteDialog } from '@/components/settings/collaborator-invite-dialog';
import { useState } from 'react';

interface CollaboratorItem {
    id: number;
    name: string;
    email: string;
    avatar_url: string | null;
    is_active: boolean;
    services_count: number;
}

interface InvitationItem {
    id: number;
    email: string;
    service_ids: number[] | null;
    created_at: string;
    expires_at: string;
}

interface Props {
    collaborators: CollaboratorItem[];
    invitations: InvitationItem[];
    services: { id: number; name: string }[];
}

function getInitials(name: string): string {
    return name.split(' ').map((w) => w[0]).join('').toUpperCase().slice(0, 2);
}

export default function Collaborators({ collaborators, invitations, services }: Props) {
    const { t } = useTrans();
    const [inviteOpen, setInviteOpen] = useState(false);

    return (
        <SettingsLayout title={t('Collaborators')}>
            <div className="flex flex-col gap-6">
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <div>
                            <CardTitle>{t('Collaborators')}</CardTitle>
                            <CardDescription>{t('Manage your team members')}</CardDescription>
                        </div>
                        <Button onClick={() => setInviteOpen(true)}>{t('Invite')}</Button>
                    </CardHeader>
                    <CardPanel>
                        {collaborators.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">{t('No collaborators yet.')}</p>
                        ) : (
                            <div className="divide-y">
                                {collaborators.map((collab) => (
                                    <div key={collab.id} className="flex items-center justify-between py-3">
                                        <Link href={show.url(collab.id)} className="flex items-center gap-3 hover:opacity-80">
                                            <Avatar className="size-8">
                                                <AvatarImage src={collab.avatar_url ?? undefined} alt={collab.name} />
                                                <AvatarFallback>{getInitials(collab.name)}</AvatarFallback>
                                            </Avatar>
                                            <div>
                                                <div className="flex items-center gap-2">
                                                    <span className="text-sm font-medium">{collab.name}</span>
                                                    {!collab.is_active && (
                                                        <Badge variant="secondary">{t('Inactive')}</Badge>
                                                    )}
                                                </div>
                                                <span className="text-xs text-muted-foreground">{collab.email}</span>
                                            </div>
                                        </Link>
                                        <div className="flex items-center gap-2">
                                            <span className="text-xs text-muted-foreground">
                                                {collab.services_count} {t('services')}
                                            </span>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => router.post(toggleActive.url(collab.id))}
                                            >
                                                {collab.is_active ? t('Deactivate') : t('Activate')}
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardPanel>
                </Card>

                {invitations.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Pending Invitations')}</CardTitle>
                        </CardHeader>
                        <CardPanel>
                            <div className="divide-y">
                                {invitations.map((inv) => (
                                    <div key={inv.id} className="flex items-center justify-between py-3">
                                        <div>
                                            <span className="text-sm font-medium">{inv.email}</span>
                                            <p className="text-xs text-muted-foreground">
                                                {t('Expires')}: {new Date(inv.expires_at).toLocaleDateString()}
                                            </p>
                                        </div>
                                        <div className="flex gap-2">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => router.post(resendInvitation.url(inv.id))}
                                            >
                                                {t('Resend')}
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => router.delete(cancelInvitation.url(inv.id))}
                                            >
                                                {t('Cancel')}
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardPanel>
                    </Card>
                )}
            </div>

            <CollaboratorInviteDialog
                open={inviteOpen}
                onOpenChange={setInviteOpen}
                services={services}
            />
        </SettingsLayout>
    );
}
