import GuestLayout from '@/layouts/guest-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, FieldLabel, FieldError, FieldDescription } from '@/components/ui/field';
import { useTrans } from '@/hooks/use-trans';
import { Form, Link, usePage } from '@inertiajs/react';
import { resend, changeEmail } from '@/actions/App/Http/Controllers/Auth/EmailVerificationController';
import { destroy } from '@/actions/App/Http/Controllers/Auth/LoginController';
import { useState } from 'react';

export default function VerifyEmail() {
    const { t } = useTrans();
    const { status, currentEmail } = usePage<{ status?: string; currentEmail?: string }>().props;
    const [showChangeEmail, setShowChangeEmail] = useState(false);

    return (
        <GuestLayout title={t('Verify your email')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Verify your email')}</CardTitle>
                    <CardDescription>
                        {currentEmail
                            ? t("We've sent a verification link to :email. Open your inbox and click the link to verify your account.", { email: currentEmail })
                            : t("We've sent a verification link to your email address. Please check your inbox and click the link to verify your account.")}
                    </CardDescription>
                </CardHeader>
                <Form action={resend()}>
                    {({ processing }) => (
                        <>
                            <CardPanel className="flex flex-col gap-4">
                                {status && (
                                    <p className="text-sm text-green-600">{status}</p>
                                )}
                                <p className="text-sm text-muted-foreground">
                                    {t("Didn't receive the email? Click the button below to request a new one.")}
                                </p>
                            </CardPanel>
                            <CardFooter className="flex items-center justify-between">
                                <Link href={destroy()} method="post" as="button" className="text-sm text-muted-foreground hover:underline">
                                    {t('Log out')}
                                </Link>
                                <Button type="submit" disabled={processing}>
                                    {t('Resend verification email')}
                                </Button>
                            </CardFooter>
                        </>
                    )}
                </Form>
            </Card>

            <div className="mt-6">
                {!showChangeEmail ? (
                    <button
                        type="button"
                        onClick={() => setShowChangeEmail(true)}
                        className="text-sm text-muted-foreground hover:underline"
                    >
                        {t('Wrong email? Change it.')}
                    </button>
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Change your email')}</CardTitle>
                            <CardDescription>
                                {t('Enter the correct email and we will send a fresh verification link.')}
                            </CardDescription>
                        </CardHeader>
                        <Form action={changeEmail()}>
                            {({ errors, processing }) => (
                                <>
                                    <CardPanel className="flex flex-col gap-4">
                                        <Field>
                                            <FieldLabel>{t('New email')}</FieldLabel>
                                            <Input
                                                name="email"
                                                type="email"
                                                autoComplete="email"
                                                required
                                            />
                                            <FieldDescription>
                                                {t('Sending verification will null your current verification status.')}
                                            </FieldDescription>
                                            {errors.email && <FieldError match>{errors.email}</FieldError>}
                                        </Field>
                                    </CardPanel>
                                    <CardFooter className="flex items-center justify-between">
                                        <button
                                            type="button"
                                            onClick={() => setShowChangeEmail(false)}
                                            className="text-sm text-muted-foreground hover:underline"
                                        >
                                            {t('Cancel')}
                                        </button>
                                        <Button type="submit" disabled={processing}>
                                            {t('Update and resend')}
                                        </Button>
                                    </CardFooter>
                                </>
                            )}
                        </Form>
                    </Card>
                )}
            </div>
        </GuestLayout>
    );
}
