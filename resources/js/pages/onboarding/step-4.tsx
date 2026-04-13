import OnboardingLayout from '@/layouts/onboarding-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { InputError } from '@/components/input-error';
import { useTrans } from '@/hooks/use-trans';
import { router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { PlusIcon, Trash2Icon } from 'lucide-react';

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

function getCsrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function getInertiaVersion(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="inertia-version"]')?.content ?? '';
}

function postJson(url: string, data: unknown): Promise<Response> {
    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': getCsrfToken(),
            'X-Inertia': 'true',
            'X-Inertia-Version': getInertiaVersion(),
            Accept: 'text/html, application/xhtml+xml',
        },
        body: JSON.stringify(data),
    });
}

export default function Step4({ services, pendingInvitations }: Props) {
    const { t } = useTrans();
    const [invitations, setInvitations] = useState<InvitationRow[]>(
        pendingInvitations.length > 0
            ? pendingInvitations.map((inv) => ({ email: inv.email, service_ids: inv.service_ids ?? [] }))
            : [{ email: '', service_ids: [] }],
    );
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

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

    function submitData(data: unknown) {
        setProcessing(true);
        setErrors({});

        postJson('/onboarding/step/4', data).then((response) => {
            if (response.status === 422) {
                return response.json().then((json) => {
                    setErrors(json.errors ?? {});
                    setProcessing(false);
                });
            }
            router.visit('/onboarding/step/5');
        }).catch(() => {
            setProcessing(false);
        });
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        const validInvitations = invitations.filter((inv) => inv.email.trim() !== '');
        submitData({ invitations: validInvitations });
    }

    function skip() {
        submitData({ invitations: [] });
    }

    return (
        <OnboardingLayout step={4} title={t('Invite Team')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Invite Collaborators')}</CardTitle>
                    <CardDescription>{t('Invite team members to join your business. This step is optional — you can do it later.')}</CardDescription>
                </CardHeader>
                <form onSubmit={submit}>
                    <CardPanel className="flex flex-col gap-4">
                        {invitations.map((invitation, index) => (
                            <div key={index} className="rounded-lg border p-4">
                                <div className="flex items-start gap-2">
                                    <div className="flex-1">
                                        <label className="text-sm font-medium">{t('Email')}</label>
                                        <Input
                                            type="email"
                                            value={invitation.email}
                                            onChange={(e) => updateEmail(index, e.target.value)}
                                            placeholder={t('collaborator@example.com')}
                                        />
                                        <InputError message={errors[`invitations.${index}.email`]} />
                                    </div>
                                    {invitations.length > 1 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="mt-6 h-8 w-8 text-muted-foreground hover:text-destructive-foreground"
                                            onClick={() => removeRow(index)}
                                        >
                                            <Trash2Icon className="h-4 w-4" />
                                        </Button>
                                    )}
                                </div>

                                {services.length > 0 && (
                                    <div className="mt-3">
                                        <label className="text-sm font-medium">{t('Assign to services')}</label>
                                        <div className="mt-1 flex flex-wrap gap-2">
                                            {services.map((service) => (
                                                <label
                                                    key={service.id}
                                                    className={`flex cursor-pointer items-center gap-1.5 rounded-md border px-2.5 py-1 text-sm transition-colors ${
                                                        invitation.service_ids.includes(service.id)
                                                            ? 'border-primary bg-primary/10 text-primary'
                                                            : 'border-input hover:bg-accent'
                                                    }`}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={invitation.service_ids.includes(service.id)}
                                                        onChange={() => toggleService(index, service.id)}
                                                        className="sr-only"
                                                    />
                                                    {service.name}
                                                </label>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>
                        ))}

                        <Button type="button" variant="outline" size="sm" className="w-fit" onClick={addRow}>
                            <PlusIcon className="mr-1 h-4 w-4" />
                            {t('Add another')}
                        </Button>
                    </CardPanel>
                    <CardFooter className="flex justify-between">
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => router.visit('/onboarding/step/3')}
                            >
                                {t('Back')}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={skip}
                                disabled={processing}
                            >
                                {t('Skip this step')}
                            </Button>
                        </div>
                        <Button type="submit" disabled={processing}>
                            {t('Continue')}
                        </Button>
                    </CardFooter>
                </form>
            </Card>
        </OnboardingLayout>
    );
}
