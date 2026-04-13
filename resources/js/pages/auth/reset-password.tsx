import GuestLayout from '@/layouts/guest-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { InputError } from '@/components/input-error';
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

                                <div className="flex flex-col gap-2">
                                    <label htmlFor="email" className="text-sm font-medium">{t('Email')}</label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        defaultValue={email}
                                        readOnly
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="flex flex-col gap-2">
                                    <label htmlFor="password" className="text-sm font-medium">{t('New password')}</label>
                                    <Input
                                        id="password"
                                        name="password"
                                        type="password"
                                        defaultValue=""
                                        required
                                        autoFocus
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
