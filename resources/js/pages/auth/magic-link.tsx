import GuestLayout from '@/layouts/guest-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { InputError } from '@/components/input-error';
import { useTrans } from '@/hooks/use-trans';
import { Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function MagicLink() {
    const { t } = useTrans();
    const { status } = usePage<{ status?: string }>().props;
    const form = useForm({ email: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post('/magic-link');
    }

    return (
        <GuestLayout title={t('Magic link login')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Magic link login')}</CardTitle>
                    <CardDescription>{t("Enter your email and we'll send you a login link.")}</CardDescription>
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
                    </CardPanel>
                    <CardFooter className="flex items-center justify-between">
                        <Link href="/login" className="text-sm text-muted-foreground hover:underline">
                            {t('Back to login')}
                        </Link>
                        <Button type="submit" disabled={form.processing}>
                            {t('Send magic link')}
                        </Button>
                    </CardFooter>
                </form>
            </Card>
        </GuestLayout>
    );
}
