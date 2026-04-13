import GuestLayout from '@/layouts/guest-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, FieldLabel, FieldError } from '@/components/ui/field';
import { useTrans } from '@/hooks/use-trans';
import { Form, Link } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/Auth/CustomerRegisterController';
import { create as magicLinkCreate } from '@/actions/App/Http/Controllers/Auth/MagicLinkController';

export default function CustomerRegister() {
    const { t } = useTrans();

    return (
        <GuestLayout title={t('Create customer account')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Create customer account')}</CardTitle>
                    <CardDescription>
                        {t('Register with the email you used when booking to manage all your appointments.')}
                    </CardDescription>
                </CardHeader>
                <Form action={store()}>
                    {({ errors, processing }) => (
                        <>
                            <CardPanel className="flex flex-col gap-4">
                                <Field>
                                    <FieldLabel>{t('Name')}</FieldLabel>
                                    <Input
                                        name="name"
                                        type="text"
                                        defaultValue=""
                                        required
                                        autoFocus
                                    />
                                    {errors.name && <FieldError match>{errors.name}</FieldError>}
                                </Field>

                                <Field>
                                    <FieldLabel>{t('Email')}</FieldLabel>
                                    <Input
                                        name="email"
                                        type="email"
                                        defaultValue=""
                                        required
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

                                <Field>
                                    <FieldLabel>{t('Confirm Password')}</FieldLabel>
                                    <Input
                                        name="password_confirmation"
                                        type="password"
                                        defaultValue=""
                                        required
                                    />
                                </Field>
                            </CardPanel>
                            <CardFooter className="flex items-center justify-between">
                                <Link href={magicLinkCreate()} className="text-sm text-muted-foreground hover:underline">
                                    {t('Or use a magic link')}
                                </Link>
                                <Button type="submit" disabled={processing}>
                                    {t('Register')}
                                </Button>
                            </CardFooter>
                        </>
                    )}
                </Form>
            </Card>
        </GuestLayout>
    );
}
