import GuestLayout from '@/layouts/guest-layout';
import { Card, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel, FieldError } from '@/components/ui/field';
import { Display } from '@/components/ui/display';
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
            <div className="mb-6 flex flex-col gap-2 sm:mb-8">
                <p className="text-[11px] uppercase tracking-[0.22em] text-muted-foreground">
                    {t('Sign in')}
                </p>
                <Display
                    render={<h1 />}
                    className="text-[clamp(1.625rem,1.3rem+1vw,2rem)] font-semibold leading-[1.05] text-foreground"
                >
                    {t('Welcome back')}
                </Display>
                <p className="text-balance text-sm leading-relaxed text-muted-foreground">
                    {t('Sign in to continue managing your bookings and customers.')}
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

                                <Field>
                                    <FieldLabel className="flex w-full items-center justify-between">
                                        <span>{t('Password')}</span>
                                        <Link
                                            href={forgotPasswordCreate()}
                                            className="text-xs font-normal text-muted-foreground transition-colors hover:text-foreground hover:underline"
                                        >
                                            {t('Forgot?')}
                                        </Link>
                                    </FieldLabel>
                                    <Input
                                        name="password"
                                        type="password"
                                        autoComplete="current-password"
                                        defaultValue=""
                                        aria-invalid={!!errors.password}
                                        required
                                    />
                                    {errors.password && (
                                        <FieldError match>
                                            {errors.password}
                                        </FieldError>
                                    )}
                                </Field>

                                <label className="inline-flex cursor-pointer items-center gap-2.5 self-start text-sm text-secondary-foreground">
                                    <input
                                        type="hidden"
                                        name="remember"
                                        value={remember ? '1' : '0'}
                                    />
                                    <Checkbox
                                        checked={remember}
                                        onCheckedChange={(checked) =>
                                            setRemember(!!checked)
                                        }
                                    />
                                    <span>{t('Keep me signed in')}</span>
                                </label>
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
                                        {t('Sign in')}
                                    </Display>
                                </Button>
                            </CardFooter>
                        </>
                    )}
                </Form>
            </Card>

            <div className="mt-6 flex flex-col items-center gap-3 text-center text-sm">
                <Link
                    href={magicLinkCreate()}
                    className="inline-flex items-center gap-2 text-secondary-foreground transition-colors hover:text-foreground"
                >
                    <span
                        aria-hidden="true"
                        className="size-1 rounded-full bg-primary"
                    />
                    <span className="underline-offset-4 hover:underline">
                        {t('Send me a magic link instead')}
                    </span>
                </Link>
                <p className="text-muted-foreground">
                    {t("New to riservo?")}{' '}
                    <Link
                        href={registerCreate()}
                        className="font-medium text-foreground underline-offset-4 hover:underline"
                    >
                        {t('Create an account')}
                    </Link>
                </p>
            </div>
        </GuestLayout>
    );
}
