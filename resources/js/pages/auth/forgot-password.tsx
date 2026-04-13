import GuestLayout from '@/layouts/guest-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { InputError } from '@/components/input-error';
import { useTrans } from '@/hooks/use-trans';
import { Form, Link, usePage } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/Auth/PasswordResetController';
import { create as loginCreate } from '@/actions/App/Http/Controllers/Auth/LoginController';

export default function ForgotPassword() {
    const { t } = useTrans();
    const { status } = usePage<{ status?: string }>().props;

    return (
        <GuestLayout title={t('Forgot password')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Forgot password')}</CardTitle>
                    <CardDescription>
                        {t("Enter your email and we'll send you a link to reset your password.")}
                    </CardDescription>
                </CardHeader>
                <Form action={store()}>
                    {({ errors, processing }) => (
                        <>
                            <CardPanel className="flex flex-col gap-4">
                                {status && (
                                    <p className="text-sm text-green-600">{status}</p>
                                )}

                                <div className="flex flex-col gap-2">
                                    <label htmlFor="email" className="text-sm font-medium">{t('Email')}</label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        defaultValue=""
                                        required
                                        autoFocus
                                    />
                                    <InputError message={errors.email} />
                                </div>
                            </CardPanel>
                            <CardFooter className="flex items-center justify-between">
                                <Link href={loginCreate()} className="text-sm text-muted-foreground hover:underline">
                                    {t('Back to login')}
                                </Link>
                                <Button type="submit" disabled={processing}>
                                    {t('Send reset link')}
                                </Button>
                            </CardFooter>
                        </>
                    )}
                </Form>
            </Card>
        </GuestLayout>
    );
}
