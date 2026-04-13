import GuestLayout from '@/layouts/guest-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { InputError } from '@/components/input-error';
import { useTrans } from '@/hooks/use-trans';
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function CustomerRegister() {
    const { t } = useTrans();
    const form = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post('/customer/register');
    }

    return (
        <GuestLayout title={t('Create customer account')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Create customer account')}</CardTitle>
                    <CardDescription>
                        {t('Register with the email you used when booking to manage all your appointments.')}
                    </CardDescription>
                </CardHeader>
                <form onSubmit={submit}>
                    <CardPanel className="flex flex-col gap-4">
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
                            <label htmlFor="email" className="text-sm font-medium">{t('Email')}</label>
                            <Input
                                id="email"
                                type="email"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                required
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
                    <CardFooter className="flex items-center justify-between">
                        <Link href="/magic-link" className="text-sm text-muted-foreground hover:underline">
                            {t('Or use a magic link')}
                        </Link>
                        <Button type="submit" disabled={form.processing}>
                            {t('Register')}
                        </Button>
                    </CardFooter>
                </form>
            </Card>
        </GuestLayout>
    );
}
