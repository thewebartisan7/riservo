import GuestLayout from '@/layouts/guest-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { InputError } from '@/components/input-error';
import { useTrans } from '@/hooks/use-trans';
import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function ResetPassword() {
    const { t } = useTrans();
    const { token, email } = usePage<{ token: string; email: string }>().props;
    const form = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post('/reset-password');
    }

    return (
        <GuestLayout title={t('Reset password')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Reset password')}</CardTitle>
                    <CardDescription>{t('Enter your new password below.')}</CardDescription>
                </CardHeader>
                <form onSubmit={submit}>
                    <CardPanel className="flex flex-col gap-4">
                        <div className="flex flex-col gap-2">
                            <label htmlFor="email" className="text-sm font-medium">{t('Email')}</label>
                            <Input
                                id="email"
                                type="email"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                readOnly
                            />
                            <InputError message={form.errors.email} />
                        </div>

                        <div className="flex flex-col gap-2">
                            <label htmlFor="password" className="text-sm font-medium">{t('New password')}</label>
                            <Input
                                id="password"
                                type="password"
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                                required
                                autoFocus
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
                            {t('Reset password')}
                        </Button>
                    </CardFooter>
                </form>
            </Card>
        </GuestLayout>
    );
}
