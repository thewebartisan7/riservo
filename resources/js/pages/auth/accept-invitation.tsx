import GuestLayout from '@/layouts/guest-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { InputError } from '@/components/input-error';
import { useTrans } from '@/hooks/use-trans';
import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import type { InvitationData } from '@/types';

export default function AcceptInvitation() {
    const { t } = useTrans();
    const { invitation } = usePage<{ invitation: InvitationData }>().props;
    const form = useForm({
        name: '',
        password: '',
        password_confirmation: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(`/invite/${invitation.token}`);
    }

    return (
        <GuestLayout title={t('Accept invitation')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Accept invitation')}</CardTitle>
                    <CardDescription>
                        {t('You have been invited to join :business as a :role.', {
                            business: invitation.business_name,
                            role: invitation.role,
                        })}
                    </CardDescription>
                </CardHeader>
                <form onSubmit={submit}>
                    <CardPanel className="flex flex-col gap-4">
                        <div className="flex flex-col gap-2">
                            <label htmlFor="email" className="text-sm font-medium">{t('Email')}</label>
                            <Input
                                id="email"
                                type="email"
                                value={invitation.email}
                                readOnly
                                disabled
                            />
                        </div>

                        <div className="flex flex-col gap-2">
                            <label htmlFor="name" className="text-sm font-medium">{t('Name')}</label>
                            <Input
                                id="name"
                                type="text"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                required
                                autoFocus
                            />
                            <InputError message={form.errors.name} />
                        </div>

                        <div className="flex flex-col gap-2">
                            <label htmlFor="password" className="text-sm font-medium">{t('Password')}</label>
                            <Input
                                id="password"
                                type="password"
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                                required
                            />
                            <InputError message={form.errors.password} />
                        </div>

                        <div className="flex flex-col gap-2">
                            <label htmlFor="password_confirmation" className="text-sm font-medium">{t('Confirm Password')}</label>
                            <Input
                                id="password_confirmation"
                                type="password"
                                value={form.data.password_confirmation}
                                onChange={(e) => form.setData('password_confirmation', e.target.value)}
                                required
                            />
                        </div>
                    </CardPanel>
                    <CardFooter className="flex justify-end">
                        <Button type="submit" disabled={form.processing}>
                            {t('Accept invitation')}
                        </Button>
                    </CardFooter>
                </form>
            </Card>
        </GuestLayout>
    );
}
