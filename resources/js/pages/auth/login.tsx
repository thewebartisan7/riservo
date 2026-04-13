import GuestLayout from '@/layouts/guest-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import { InputError } from '@/components/input-error';
import { useTrans } from '@/hooks/use-trans';
import { Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function Login() {
    const { t } = useTrans();
    const { status } = usePage<{ status?: string }>().props;
    const form = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post('/login');
    }

    return (
        <GuestLayout title={t('Log in')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Log in')}</CardTitle>
                    <CardDescription>{t('Welcome back')}</CardDescription>
                </CardHeader>
                <form onSubmit={submit}>
                    <CardPanel className="flex flex-col gap-4">
                        {status && (
                            <p className="text-sm text-green-600">{status}</p>
                        )}

                        <div className="flex flex-col gap-2">
                            <label htmlFor="email" className="text-sm font-medium">{t('Email')}</label>
                            <Input
                                id="email"
                                type="email"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                required
                                autoFocus
                            />
                            <InputError message={form.errors.email} />
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

                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="remember"
                                checked={form.data.remember}
                                onCheckedChange={(checked) => form.setData('remember', !!checked)}
                            />
                            <label htmlFor="remember" className="text-sm">
                                {t('Remember me')}
                            </label>
                        </div>
                    </CardPanel>
                    <CardFooter className="flex flex-col gap-4">
                        <div className="flex w-full items-center justify-between">
                            <Link href="/forgot-password" className="text-sm text-muted-foreground hover:underline">
                                {t('Forgot your password?')}
                            </Link>
                            <Button type="submit" disabled={form.processing}>
                                {t('Log in')}
                            </Button>
                        </div>
                        <div className="flex w-full items-center justify-between text-sm text-muted-foreground">
                            <Link href="/magic-link" className="hover:underline">
                                {t('Send me a magic link')}
                            </Link>
                            <Link href="/register" className="hover:underline">
                                {t("Don't have an account?")}
                            </Link>
                        </div>
                    </CardFooter>
                </form>
            </Card>
        </GuestLayout>
    );
}
