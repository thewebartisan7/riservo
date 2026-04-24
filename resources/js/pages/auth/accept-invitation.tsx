import GuestLayout from '@/layouts/guest-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, FieldLabel, FieldError } from '@/components/ui/field';
import { useTrans } from '@/hooks/use-trans';
import { Form, usePage } from '@inertiajs/react';
import { accept } from '@/actions/App/Http/Controllers/Auth/InvitationController';
import { destroy as logout } from '@/actions/App/Http/Controllers/Auth/LoginController';
import type { InvitationData, PageProps } from '@/types';

interface AcceptInvitationProps extends PageProps {
    invitation: InvitationData;
    isExistingUser: boolean;
    authUserEmail: string | null;
}

export default function AcceptInvitation() {
    const { t } = useTrans();
    const { invitation, isExistingUser, authUserEmail, flash } =
        usePage<AcceptInvitationProps>().props;

    const signedInAsInvitee = authUserEmail === invitation.email;
    const signedInAsOtherUser = authUserEmail !== null && !signedInAsInvitee;

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

                {flash?.error && (
                    <CardPanel>
                        <p className="text-sm text-destructive-foreground">{flash.error}</p>
                    </CardPanel>
                )}

                {isExistingUser && signedInAsOtherUser ? (
                    <>
                        <CardPanel className="flex flex-col gap-2 text-sm">
                            <p>
                                {t('You are signed in as :current. This invitation is for :target.', {
                                    current: authUserEmail ?? '',
                                    target: invitation.email,
                                })}
                            </p>
                            <p className="text-muted-foreground">
                                {t('Sign out first, then reopen this invitation.')}
                            </p>
                        </CardPanel>
                        <CardFooter className="flex justify-end">
                            <Form action={logout()} method="post">
                                {({ processing }) => (
                                    <Button type="submit" disabled={processing}>
                                        {t('Sign out')}
                                    </Button>
                                )}
                            </Form>
                        </CardFooter>
                    </>
                ) : isExistingUser && signedInAsInvitee ? (
                    <Form action={accept(invitation.token)}>
                        {({ processing }) => (
                            <>
                                <CardPanel className="flex flex-col gap-2 text-sm">
                                    <p>
                                        {t('You are signed in as :email.', {
                                            email: authUserEmail ?? '',
                                        })}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {t('Accept to join :business.', {
                                            business: invitation.business_name,
                                        })}
                                    </p>
                                </CardPanel>
                                <CardFooter className="flex justify-end">
                                    <Button type="submit" disabled={processing}>
                                        {t('Accept invitation')}
                                    </Button>
                                </CardFooter>
                            </>
                        )}
                    </Form>
                ) : isExistingUser ? (
                    <Form action={accept(invitation.token)}>
                        {({ errors, processing }) => (
                            <>
                                <CardPanel className="flex flex-col gap-4">
                                    <p className="text-sm text-muted-foreground">
                                        {t('You already have a riservo.ch account. Sign in to accept.')}
                                    </p>

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
                                        <FieldLabel>{t('Password')}</FieldLabel>
                                        <Input
                                            name="password"
                                            type="password"
                                            defaultValue=""
                                            required
                                            autoFocus
                                        />
                                        {errors.password && <FieldError match>{errors.password}</FieldError>}
                                    </Field>
                                </CardPanel>
                                <CardFooter className="flex justify-end">
                                    <Button type="submit" disabled={processing}>
                                        {t('Sign in and accept')}
                                    </Button>
                                </CardFooter>
                            </>
                        )}
                    </Form>
                ) : (
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
                )}
            </Card>
        </GuestLayout>
    );
}
