import GuestLayout from '@/layouts/guest-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/hooks/use-trans';
import { Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function VerifyEmail() {
    const { t } = useTrans();
    const { status } = usePage<{ status?: string }>().props;
    const form = useForm({});

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post('/email/verification-notification');
    }

    return (
        <GuestLayout title={t('Verify your email')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Verify your email')}</CardTitle>
                    <CardDescription>
                        {t("We've sent a verification link to your email address. Please check your inbox and click the link to verify your account.")}
                    </CardDescription>
                </CardHeader>
                <form onSubmit={submit}>
                    <CardPanel className="flex flex-col gap-4">
                        {status && (
                            <p className="text-sm text-green-600">{status}</p>
                        )}
                        <p className="text-sm text-muted-foreground">
                            {t("Didn't receive the email? Click the button below to request a new one.")}
                        </p>
                    </CardPanel>
                    <CardFooter className="flex items-center justify-between">
                        <Link href="/logout" method="post" as="button" className="text-sm text-muted-foreground hover:underline">
                            {t('Log out')}
                        </Link>
                        <Button type="submit" disabled={form.processing}>
                            {t('Resend verification email')}
                        </Button>
                    </CardFooter>
                </form>
            </Card>
        </GuestLayout>
    );
}
