import GuestLayout from '@/layouts/guest-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { InputError } from '@/components/input-error';
import { useTrans } from '@/hooks/use-trans';
import { Form, usePage } from '@inertiajs/react';
import { accept } from '@/actions/App/Http/Controllers/Auth/InvitationController';
import type { InvitationData } from '@/types';

export default function AcceptInvitation() {
    const { t } = useTrans();
    const { invitation } = usePage<{ invitation: InvitationData }>().props;

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
                <Form action={accept(invitation.token)}>
                    {({ errors, processing }) => (
                        <>
                            <CardPanel className="flex flex-col gap-4">
                                <div className="flex flex-col gap-2">
                                    <label htmlFor="email" className="text-sm font-medium">{t('Email')}</label>
                                    <Input
                                        id="email"
                                        type="email"
                                        defaultValue={invitation.email}
                                        readOnly
                                        disabled
                                    />
                                </div>

                                <div className="flex flex-col gap-2">
                                    <label htmlFor="name" className="text-sm font-medium">{t('Name')}</label>
                                    <Input
                                        id="name"
                                        name="name"
                                        type="text"
                                        defaultValue=""
                                        required
                                        autoFocus
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="flex flex-col gap-2">
                                    <label htmlFor="password" className="text-sm font-medium">{t('Password')}</label>
                                    <Input
                                        id="password"
                                        name="password"
                                        type="password"
                                        defaultValue=""
                                        required
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="flex flex-col gap-2">
                                    <label htmlFor="password_confirmation" className="text-sm font-medium">{t('Confirm Password')}</label>
                                    <Input
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        type="password"
                                        defaultValue=""
                                        required
                                    />
                                </div>
                            </CardPanel>
                            <CardFooter className="flex justify-end">
                                <Button type="submit" disabled={processing}>
                                    {t('Accept invitation')}
                                </Button>
                            </CardFooter>
                        </>
                    )}
                </Form>
            </Card>
        </GuestLayout>
    );
}
