import GuestLayout from '@/layouts/guest-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { InputError } from '@/components/input-error';
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
                                <div className="flex flex-col gap-2">
                                    <label htmlFor="name" className="text-sm font-medium">{t('Name')}</label>
                                    <Input
                                        id="name"
                                        name="name"
                                        type="text"
                                        defaultValue=""
                                        required
                                        autoFocus
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="flex flex-col gap-2">
                                    <label htmlFor="email" className="text-sm font-medium">{t('Email')}</label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        defaultValue=""
                                        required
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="flex flex-col gap-2">
                                    <label htmlFor="password" className="text-sm font-medium">{t('Password')}</label>
                                    <Input
                                        id="password"
                                        name="password"
                                        type="password"
                                        defaultValue=""
                                        required
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="flex flex-col gap-2">
                                    <label htmlFor="password_confirmation" className="text-sm font-medium">{t('Confirm Password')}</label>
                                    <Input
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        type="password"
                                        defaultValue=""
                                        required
                                    />
                                </div>
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
