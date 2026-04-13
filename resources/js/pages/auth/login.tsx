import GuestLayout from '@/layouts/guest-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel, FieldError } from '@/components/ui/field';
import { useTrans } from '@/hooks/use-trans';
import { Form, Link, usePage } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/Auth/LoginController';
import { create as forgotPasswordCreate } from '@/actions/App/Http/Controllers/Auth/PasswordResetController';
import { create as magicLinkCreate } from '@/actions/App/Http/Controllers/Auth/MagicLinkController';
import { create as registerCreate } from '@/actions/App/Http/Controllers/Auth/RegisterController';
import { useState } from 'react';

export default function Login() {
    const { t } = useTrans();
    const { status } = usePage<{ status?: string }>().props;
    const [remember, setRemember] = useState(false);

    return (
        <GuestLayout title={t('Log in')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Log in')}</CardTitle>
                    <CardDescription>{t('Welcome back')}</CardDescription>
                </CardHeader>
                <Form action={store()}>
                    {({ errors, processing }) => (
                        <>
                            <CardPanel className="flex flex-col gap-4">
                                {status && (
                                    <p className="text-sm text-green-600">{status}</p>
                                )}

                                <Field>
                                    <FieldLabel>{t('Email')}</FieldLabel>
                                    <Input
                                        name="email"
                                        type="email"
                                        defaultValue=""
                                        required
                                        autoFocus
                                    />
                                    {errors.email && <FieldError match>{errors.email}</FieldError>}
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

                                <div className="flex items-center gap-2">
                                    <input type="hidden" name="remember" value={remember ? '1' : '0'} />
                                    <Checkbox
                                        id="remember"
                                        checked={remember}
                                        onCheckedChange={(checked) => setRemember(!!checked)}
                                    />
                                    <label htmlFor="remember" className="text-sm">
                                        {t('Remember me')}
                                    </label>
                                </div>
                            </CardPanel>
                            <CardFooter className="flex flex-col gap-4">
                                <div className="flex w-full items-center justify-between">
                                    <Link href={forgotPasswordCreate()} className="text-sm text-muted-foreground hover:underline">
                                        {t('Forgot your password?')}
                                    </Link>
                                    <Button type="submit" disabled={processing}>
                                        {t('Log in')}
                                    </Button>
                                </div>
                                <div className="flex w-full items-center justify-between text-sm text-muted-foreground">
                                    <Link href={magicLinkCreate()} className="hover:underline">
                                        {t('Send me a magic link')}
                                    </Link>
                                    <Link href={registerCreate()} className="hover:underline">
                                        {t("Don't have an account?")}
                                    </Link>
                                </div>
                            </CardFooter>
                        </>
                    )}
                </Form>
            </Card>
        </GuestLayout>
    );
}
