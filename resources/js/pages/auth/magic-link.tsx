import GuestLayout from '@/layouts/guest-layout';
import { Card, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, FieldLabel, FieldError } from '@/components/ui/field';
import { Display } from '@/components/ui/display';
import { useTrans } from '@/hooks/use-trans';
import { Form, Link, usePage } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/Auth/MagicLinkController';
import { create as loginCreate } from '@/actions/App/Http/Controllers/Auth/LoginController';

export default function MagicLink() {
    const { t } = useTrans();
    const { status } = usePage<{ status?: string }>().props;

    return (
        <GuestLayout title={t('Magic link login')}>
            <div className="mb-6 flex flex-col gap-2 sm:mb-8">
                <p className="text-[11px] uppercase tracking-[0.22em] text-muted-foreground">
                    {t('Magic link')}
                </p>
                <Display
                    render={<h1 />}
                    className="text-[clamp(1.625rem,1.3rem+1vw,2rem)] font-semibold leading-[1.05] text-foreground"
                >
                    {t('No password needed')}
                </Display>
                <p className="text-balance text-sm leading-relaxed text-muted-foreground">
                    {t("Enter your email and we'll send a one-time link. Tap it and you're in.")}
                </p>
            </div>

            <Card>
                <Form action={store()}>
                    {({ errors, processing }) => (
                        <>
                            <CardPanel className="flex flex-col gap-5">
                                {status && (
                                    <p
                                        role="status"
                                        className="rounded-lg border border-primary/24 bg-honey-soft px-3 py-2 text-sm text-primary-foreground"
                                    >
                                        {status}
                                    </p>
                                )}

                                <Field>
                                    <FieldLabel>{t('Email')}</FieldLabel>
                                    <Input
                                        name="email"
                                        type="email"
                                        autoComplete="email"
                                        placeholder="name@example.com"
                                        defaultValue=""
                                        aria-invalid={!!errors.email}
                                        required
                                        autoFocus
                                    />
                                    {errors.email && (
                                        <FieldError match>
                                            {errors.email}
                                        </FieldError>
                                    )}
                                </Field>
                            </CardPanel>
                            <CardFooter>
                                <Button
                                    type="submit"
                                    size="xl"
                                    loading={processing}
                                    disabled={processing}
                                    className="h-12 w-full text-sm sm:h-12"
                                >
                                    <Display className="tracking-tight">
                                        {t('Send magic link')}
                                    </Display>
                                </Button>
                            </CardFooter>
                        </>
                    )}
                </Form>
            </Card>

            <p className="mt-6 text-center text-sm text-muted-foreground">
                {t('Prefer a password?')}{' '}
                <Link
                    href={loginCreate()}
                    className="font-medium text-foreground underline-offset-4 hover:underline"
                >
                    {t('Back to sign in')}
                </Link>
            </p>
        </GuestLayout>
    );
}
