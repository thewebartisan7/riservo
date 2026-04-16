import SettingsLayout from '@/layouts/settings-layout';
import { Button } from '@/components/ui/button';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Frame, FrameHeader } from '@/components/ui/frame';
import { Display } from '@/components/ui/display';
import {
    SectionHeading,
    SectionTitle,
    SectionRule,
} from '@/components/ui/section-heading';
import { useTrans } from '@/hooks/use-trans';
import { Link, router } from '@inertiajs/react';
import {
    show,
    resendInvitation,
    cancelInvitation,
} from '@/actions/App/Http/Controllers/Dashboard/Settings/StaffController';
import { toggle } from '@/actions/App/Http/Controllers/Dashboard/Settings/ProviderController';
import { StaffInviteDialog } from '@/components/settings/staff-invite-dialog';
import { getInitials } from '@/lib/booking-format';
import { useState } from 'react';
import { ArrowRightIcon, PlusIcon } from 'lucide-react';

interface StaffItem {
    id: number;
    name: string;
    email: string;
    avatar_url: string | null;
    is_active: boolean;
    services_count: number;
    provider_id: number | null;
}

interface InvitationItem {
    id: number;
    email: string;
    service_ids: number[] | null;
    created_at: string;
    expires_at: string;
}

interface Props {
    staff: StaffItem[];
    invitations: InvitationItem[];
    services: { id: number; name: string }[];
}

export default function Staff({ staff, invitations, services }: Props) {
    const { t } = useTrans();
    const [inviteOpen, setInviteOpen] = useState(false);

    return (
        <SettingsLayout
            title={t('Staff')}
            eyebrow={t('Settings · Team')}
            heading={t('Staff')}
            description={t(
                'Everyone who performs services. Each has their own schedule, exceptions, and service list.',
            )}
            actions={
                <Button onClick={() => setInviteOpen(true)}>
                    <PlusIcon />
                    {t('Invite')}
                </Button>
            }
        >
            <div className="flex flex-col gap-10">
                {staff.length === 0 ? (
                    <Frame>
                        <FrameHeader className="items-center gap-2 py-12 text-center">
                            <p className="text-sm text-foreground">{t('No staff yet.')}</p>
                            <p className="max-w-sm text-sm text-muted-foreground">
                                {t('Invite a team member to hand them their own calendar and customer list.')}
                            </p>
                        </FrameHeader>
                    </Frame>
                ) : (
                    <ul className="flex flex-col divide-y divide-border/70 border-y border-border/70">
                        {staff.map((member) => (
                            <li key={member.id}>
                                <div className="group flex items-start gap-4 py-4 sm:items-center">
                                    <Link
                                        href={show.url(member.id)}
                                        className="flex min-w-0 flex-1 items-center gap-3 transition-opacity hover:opacity-90"
                                    >
                                        <Avatar className="size-9 shrink-0 rounded-xl border border-border bg-muted">
                                            <AvatarImage
                                                src={member.avatar_url ?? undefined}
                                                alt=""
                                                className="rounded-xl object-cover"
                                            />
                                            <AvatarFallback className="rounded-xl bg-muted font-display text-xs font-semibold text-muted-foreground">
                                                {getInitials(member.name)}
                                            </AvatarFallback>
                                        </Avatar>
                                        <div className="flex min-w-0 flex-col gap-0.5">
                                            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                                                <Display className="text-sm font-medium text-foreground">
                                                    {member.name}
                                                </Display>
                                                {!member.is_active && (
                                                    <span className="text-[10px] font-medium uppercase tracking-[0.2em] text-muted-foreground">
                                                        {t('Inactive')}
                                                    </span>
                                                )}
                                            </div>
                                            <span className="truncate text-xs text-muted-foreground">
                                                {member.email}
                                            </span>
                                        </div>
                                    </Link>
                                    <div className="flex shrink-0 items-center gap-4">
                                        <span className="hidden font-display text-xs tabular-nums text-muted-foreground sm:block">
                                            {t(':n services', { n: member.services_count })}
                                        </span>
                                        {member.provider_id !== null && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => router.post(toggle.url(member.provider_id as number))}
                                                className="text-muted-foreground hover:text-foreground"
                                            >
                                                {member.is_active ? t('Deactivate') : t('Activate')}
                                            </Button>
                                        )}
                                        <Link
                                            href={show.url(member.id)}
                                            aria-label={t('Open :name', { name: member.name })}
                                            className="inline-flex items-center text-muted-foreground/60 transition-all group-hover:translate-x-0.5 group-hover:text-foreground"
                                        >
                                            <ArrowRightIcon className="size-4" aria-hidden="true" />
                                        </Link>
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                {invitations.length > 0 && (
                    <section className="flex flex-col gap-4">
                        <SectionHeading>
                            <SectionTitle>{t('Pending invitations')}</SectionTitle>
                            <SectionRule />
                        </SectionHeading>

                        <ul className="flex flex-col divide-y divide-border/70 border-y border-border/70">
                            {invitations.map((inv) => {
                                const expires = new Date(inv.expires_at).toLocaleDateString([], {
                                    day: 'numeric',
                                    month: 'short',
                                });
                                return (
                                    <li
                                        key={inv.id}
                                        className="flex flex-col gap-2 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                                    >
                                        <div className="flex flex-col gap-0.5">
                                            <Display className="text-sm font-medium text-foreground">
                                                {inv.email}
                                            </Display>
                                            <span className="text-xs text-muted-foreground">
                                                {t('Expires :date', { date: expires })}
                                            </span>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-1">
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
                                                className="text-muted-foreground hover:text-foreground"
                                                onClick={() => router.delete(cancelInvitation.url(inv.id))}
                                            >
                                                {t('Cancel')}
                                            </Button>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                )}
            </div>

            <StaffInviteDialog
                open={inviteOpen}
                onOpenChange={setInviteOpen}
                services={services}
            />
        </SettingsLayout>
    );
}
