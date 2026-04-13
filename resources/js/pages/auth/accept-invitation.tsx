import GuestLayout from '@/layouts/guest-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, FieldLabel, FieldError } from '@/components/ui/field';
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
                                <Field>
                                    <FieldLabel>{t('Email')}</FieldLabel>
                                    <Input
                                        type="email"
                                        defaultValue={invitation.email}
                                        readOnly
                                        disabled
                                    />
                                </Field>

                                <Field>
                                    <FieldLabel>{t('Name')}</FieldLabel>
                                    <Input
                                        name="name"
                                        type="text"
                                        defaultValue=""
                                        required
                                        autoFocus
                                    />
                                    {errors.name && <FieldError match>{errors.name}</FieldError>}
                                </Field>

                                <Field>
                                    <FieldLabel>{t('Password')}</FieldLabel>
                                    <Input
                                        name="password"
                                        type="password"
                                        defaultValue=""
                                        required
                                    />
                                    {errors.password && <FieldError match>{errors.password}</FieldError>}
                                </Field>

                                <Field>
                                    <FieldLabel>{t('Confirm Password')}</FieldLabel>
                                    <Input
                                        name="password_confirmation"
                                        type="password"
                                        defaultValue=""
                                        required
                                    />
                                </Field>
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
