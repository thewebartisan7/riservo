import OnboardingLayout from '@/layouts/onboarding-layout';
import { Card, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, FieldLabel, FieldError } from '@/components/ui/field';
import { Display } from '@/components/ui/display';
import { useTrans } from '@/hooks/use-trans';
import { router, usePage } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/OnboardingController';
import { type FormEvent, useState } from 'react';
import { PlusIcon, Trash2Icon, UserPlusIcon, CheckIcon } from 'lucide-react';
import type { PageProps } from '@/types';

interface ServiceOption {
    id: number;
    name: string;
}

interface PendingInvitation {
    id: number;
    email: string;
    service_ids: number[] | null;
}

interface InvitationRow {
    email: string;
    service_ids: number[];
}

interface Props {
    services: ServiceOption[];
    pendingInvitations: PendingInvitation[];
}

export default function Step4({ services, pendingInvitations }: Props) {
    const { t } = useTrans();
    const [invitations, setInvitations] = useState<InvitationRow[]>(
        pendingInvitations.length > 0
            ? pendingInvitations.map((inv) => ({ email: inv.email, service_ids: inv.service_ids ?? [] }))
            : [{ email: '', service_ids: [] }],
    );
    const [processing, setProcessing] = useState(false);
    const pageErrors = usePage<PageProps>().props.errors as Record<string, string> | undefined;

    function addRow() {
        setInvitations([...invitations, { email: '', service_ids: [] }]);
    }

    function removeRow(index: number) {
        setInvitations(invitations.filter((_, i) => i !== index));
    }

    function updateEmail(index: number, email: string) {
        const updated = [...invitations];
        updated[index] = { ...updated[index], email };
        setInvitations(updated);
    }

    function toggleService(index: number, serviceId: number) {
        const updated = [...invitations];
        const current = updated[index].service_ids;
        updated[index] = {
            ...updated[index],
            service_ids: current.includes(serviceId)
                ? current.filter((id) => id !== serviceId)
                : [...current, serviceId],
        };
        setInvitations(updated);
    }

    function submitData(rows: InvitationRow[]) {
        // TODO Fix TS2345: Argument of type Record<string, unknown> is not assignable to parameter of type RequestPayload | undefined
        router.post(store.url(4), { invitations: rows } as Record<string, unknown>, {
            preserveState: true,
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        });
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        const validInvitations = invitations.filter((inv) => inv.email.trim() !== '');
        submitData(validInvitations);
    }

    function skip() {
        submitData([]);
    }

    const firstRowEmpty =
        invitations.length === 1 &&
        invitations[0].email.trim() === '' &&
        invitations[0].service_ids.length === 0;

    return (
        <OnboardingLayout
            step={4}
            title={t('Invite team')}
            eyebrow={t('Optional — skip if you work alone')}
            heading={t('Bring in your team')}
            description={t('Collaborators get their own login, availability, and booking link. They accept by email, no account creation needed from you.')}
        >
            <Card>
                <form onSubmit={submit}>
                    <CardPanel className="flex flex-col gap-0">
                        <ul className="flex flex-col gap-0 divide-y divide-border/60">
                            {invitations.map((invitation, index) => {
                                const emailError = pageErrors?.[`invitations.${index}.email`];
                                return (
                                    <li key={index} className="flex flex-col gap-4 py-5 first:pt-0 last:pb-0">
                                        <Field>
                                            <div className="flex items-center justify-between">
                                                <FieldLabel>
                                                    {t('Collaborator :n', { n: index + 1 })}
                                                </FieldLabel>
                                                {invitations.length > 1 && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-xs"
                                                        className="-mr-1 text-muted-foreground hover:text-foreground"
                                                        onClick={() => removeRow(index)}
                                                    >
                                                        <Trash2Icon />
                                                        <span className="sr-only">{t('Remove collaborator')}</span>
                                                    </Button>
                                                )}
                                            </div>
                                            <Input
                                                type="email"
                                                value={invitation.email}
                                                onChange={(e) => updateEmail(index, e.target.value)}
                                                placeholder="name@example.ch"
                                                aria-invalid={!!emailError}
                                            />
                                            {emailError && <FieldError match>{emailError}</FieldError>}
                                        </Field>

                                        {services.length > 0 && (
                                            <Field>
                                                <FieldLabel className="text-xs font-normal text-muted-foreground">
                                                    {t('Offers these services')}
                                                </FieldLabel>
                                                <div className="flex flex-wrap gap-2">
                                                    {services.map((service) => {
                                                        const selected = invitation.service_ids.includes(service.id);
                                                        return (
                                                            <button
                                                                key={service.id}
                                                                type="button"
                                                                aria-pressed={selected}
                                                                onClick={() => toggleService(index, service.id)}
                                                                className="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-input bg-background px-3 py-1.5 text-sm text-foreground shadow-xs/5 outline-none transition-colors hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background aria-pressed:border-primary aria-pressed:bg-honey-soft aria-pressed:text-primary"
                                                            >
                                                                <span
                                                                    aria-hidden="true"
                                                                    className={`inline-flex size-3.5 items-center justify-center rounded-full border transition-colors ${selected ? 'border-primary bg-primary text-primary-foreground' : 'border-input'}`}
                                                                >
                                                                    {selected && <CheckIcon className="size-2.5" />}
                                                                </span>
                                                                <span>{service.name}</span>
                                                            </button>
                                                        );
                                                    })}
                                                </div>
                                            </Field>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>

                        <div className="mt-5 flex items-center justify-center">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addRow}
                                className="border-dashed"
                            >
                                <PlusIcon />
                                {t('Add another person')}
                            </Button>
                        </div>
                    </CardPanel>
                    <CardFooter className="flex flex-col gap-3">
                        <Button
                            type="submit"
                            size="xl"
                            loading={processing}
                            disabled={processing}
                            className="h-12 w-full text-sm sm:h-12"
                        >
                            <Display className="tracking-tight">
                                {firstRowEmpty ? (
                                    <>
                                        <UserPlusIcon className="mr-2 inline" />
                                        {t('Continue without inviting')}
                                    </>
                                ) : (
                                    t('Send invites & continue')
                                )}
                            </Display>
                        </Button>
                        <button
                            type="button"
                            onClick={skip}
                            disabled={processing}
                            className="text-xs uppercase tracking-[0.22em] text-muted-foreground transition-colors hover:text-foreground disabled:opacity-60"
                        >
                            {t('Skip for now')}
                        </button>
                    </CardFooter>
                </form>
            </Card>
        </OnboardingLayout>
    );
}
