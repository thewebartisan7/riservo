import GuestLayout from '@/layouts/guest-layout';
import { Card, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, FieldLabel, FieldError, FieldDescription } from '@/components/ui/field';
import { Display } from '@/components/ui/display';
import { useTrans } from '@/hooks/use-trans';
import { Form, Link } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/Auth/RegisterController';
import { create as loginCreate } from '@/actions/App/Http/Controllers/Auth/LoginController';

export default function Register() {
    const { t } = useTrans();

    return (
        <GuestLayout title={t('Register')}>
            <div className="mb-6 flex flex-col gap-2 sm:mb-8">
                <p className="text-[11px] uppercase tracking-[0.22em] text-muted-foreground">
                    {t('Create account')}
                </p>
                <Display
                    render={<h1 />}
                    className="text-[clamp(1.625rem,1.3rem+1vw,2rem)] font-semibold leading-[1.05] text-foreground"
                >
                    {t('Start with riservo')}
                </Display>
                <p className="text-balance text-sm leading-relaxed text-muted-foreground">
                    {t('Tell us a bit about you and your business. You can change everything later.')}
                </p>
            </div>

            <Card>
                <Form action={store()}>
                    {({ errors, processing }) => (
                        <>
                            <CardPanel className="flex flex-col gap-5">
                                <Field>
                                    <FieldLabel>{t('Your name')}</FieldLabel>
                                    <Input
                                        name="name"
                                        type="text"
                                        autoComplete="name"
                                        placeholder={t('Full name')}
                                        defaultValue=""
                                        aria-invalid={!!errors.name}
                                        required
                                        autoFocus
                                    />
                                    {errors.name && (
                                        <FieldError match>
                                            {errors.name}
                                        </FieldError>
                                    )}
                                </Field>

                                <Field>
                                    <FieldLabel>{t('Business name')}</FieldLabel>
                                    <Input
                                        name="business_name"
                                        type="text"
                                        autoComplete="organization"
                                        placeholder={t('e.g. Atelier Bellezza')}
                                        defaultValue=""
                                        aria-invalid={!!errors.business_name}
                                        required
                                    />
                                    <FieldDescription>
                                        {t('Shown on your public booking page.')}
                                    </FieldDescription>
                                    {errors.business_name && (
                                        <FieldError match>
                                            {errors.business_name}
                                        </FieldError>
                                    )}
                                </Field>

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
                                    />
                                    {errors.email && (
                                        <FieldError match>
                                            {errors.email}
                                        </FieldError>
                                    )}
                                </Field>

                                <div
                                    role="separator"
                                    aria-hidden="true"
                                    className="flex items-center gap-3 py-1 text-[10px] uppercase tracking-[0.22em] text-muted-foreground"
                                >
                                    <span className="h-px flex-1 bg-border" />
                                    <span>{t('Choose a password')}</span>
                                    <span className="h-px flex-1 bg-border" />
                                </div>

                                <Field>
                                    <FieldLabel>{t('Password')}</FieldLabel>
                                    <Input
                                        name="password"
                                        type="password"
                                        autoComplete="new-password"
                                        defaultValue=""
                                        aria-invalid={!!errors.password}
                                        required
                                    />
                                    <FieldDescription>
                                        {t('At least 8 characters.')}
                                    </FieldDescription>
                                    {errors.password && (
                                        <FieldError match>
                                            {errors.password}
                                        </FieldError>
                                    )}
                                </Field>

                                <Field>
                                    <FieldLabel>{t('Confirm password')}</FieldLabel>
                                    <Input
                                        name="password_confirmation"
                                        type="password"
                                        autoComplete="new-password"
                                        defaultValue=""
                                        required
                                    />
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
                                        {t('Create account')}
                                    </Display>
                                </Button>
                            </CardFooter>
                        </>
                    )}
                </Form>
            </Card>

            <p className="mt-6 text-center text-sm text-muted-foreground">
                {t('Already have an account?')}{' '}
                <Link
                    href={loginCreate()}
                    className="font-medium text-foreground underline-offset-4 hover:underline"
                >
                    {t('Sign in')}
                </Link>
            </p>
        </GuestLayout>
    );
}
