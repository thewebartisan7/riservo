import GuestLayout from '@/layouts/guest-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, FieldLabel, FieldError } from '@/components/ui/field';
import { useTrans } from '@/hooks/use-trans';
import { Form, usePage } from '@inertiajs/react';
import { update } from '@/actions/App/Http/Controllers/Auth/PasswordResetController';

export default function ResetPassword() {
    const { t } = useTrans();
    const { token, email } = usePage<{ token: string; email: string }>().props;

    return (
        <GuestLayout title={t('Reset password')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Reset password')}</CardTitle>
                    <CardDescription>{t('Enter your new password below.')}</CardDescription>
                </CardHeader>
                <Form action={update()}>
                    {({ errors, processing }) => (
                        <>
                            <CardPanel className="flex flex-col gap-4">
                                <input type="hidden" name="token" value={token} />

                                <Field>
                                    <FieldLabel>{t('Email')}</FieldLabel>
                                    <Input
                                        name="email"
                                        type="email"
                                        defaultValue={email}
                                        readOnly
                                    />
                                    {errors.email && <FieldError match>{errors.email}</FieldError>}
                                </Field>

                                <Field>
                                    <FieldLabel>{t('New password')}</FieldLabel>
                                    <Input
                                        name="password"
                                        type="password"
                                        defaultValue=""
                                        required
                                        autoFocus
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
                                    {t('Reset password')}
                                </Button>
                            </CardFooter>
                        </>
                    )}
                </Form>
            </Card>
        </GuestLayout>
    );
}
