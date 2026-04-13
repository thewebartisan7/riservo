import OnboardingLayout from '@/layouts/onboarding-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { InputError } from '@/components/input-error';
import { useTrans } from '@/hooks/use-trans';
import { Form, useHttp } from '@inertiajs/react';
import { store, checkSlug as checkSlugAction, uploadLogo as uploadLogoAction } from '@/actions/App/Http/Controllers/OnboardingController';
import { useCallback, useRef, useState } from 'react';

interface Props {
    business: {
        name: string;
        slug: string;
        description: string | null;
        logo: string | null;
        phone: string | null;
        email: string | null;
        address: string | null;
    };
    logoUrl: string | null;
}

export default function Step1({ business, logoUrl }: Props) {
    const { t } = useTrans();
    const [slug, setSlug] = useState(business.slug ?? '');
    const [slugAvailable, setSlugAvailable] = useState<boolean | null>(null);
    const [logoPath, setLogoPath] = useState(business.logo ?? '');
    const [previewUrl, setPreviewUrl] = useState<string | null>(logoUrl);
    const slugTimerRef = useRef<ReturnType<typeof setTimeout>>(null);
    const slugHttp = useHttp({ slug: '' });
    const logoHttp = useHttp({ logo: null as File | null });

    const checkSlug = useCallback(
        (val: string) => {
            if (slugTimerRef.current) clearTimeout(slugTimerRef.current);
            setSlugAvailable(null);
            if (!val || val.length < 2) return;

            slugTimerRef.current = setTimeout(() => {
                slugHttp.setData('slug', val);
                slugHttp.post(checkSlugAction.url(), {
                    onSuccess: (response: unknown) => {
                        const data = response as { available: boolean };
                        setSlugAvailable(data.available);
                    },
                });
            }, 300);
        },
        [],
    );

    function handleLogoChange(e: React.ChangeEvent<HTMLInputElement>) {
        const file = e.target.files?.[0];
        if (!file) return;

        logoHttp.setData('logo', file);
        logoHttp.post(uploadLogoAction.url(), {
            onSuccess: (response: unknown) => {
                const data = response as { path: string; url: string };
                setLogoPath(data.path);
                setPreviewUrl(data.url);
            },
        });
    }

    return (
        <OnboardingLayout step={1} title={t('Business Profile')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Business Profile')}</CardTitle>
                    <CardDescription>{t('Tell us about your business')}</CardDescription>
                </CardHeader>
                <Form action={store(1)}>
                    {({ errors, processing }) => (
                        <>
                            <CardPanel className="flex flex-col gap-4">
                                <div className="flex flex-col gap-2">
                                    <label htmlFor="name" className="text-sm font-medium">{t('Business name')}</label>
                                    <Input
                                        id="name"
                                        name="name"
                                        defaultValue={business.name ?? ''}
                                        required
                                        autoFocus
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="flex flex-col gap-2">
                                    <label htmlFor="slug" className="text-sm font-medium">{t('Booking URL')}</label>
                                    <div className="flex items-center gap-0">
                                        <span className="flex h-9 items-center rounded-l-lg border border-r-0 bg-muted px-3 text-sm text-muted-foreground sm:h-8">
                                            riservo.ch/
                                        </span>
                                        <Input
                                            id="slug"
                                            name="slug"
                                            className="rounded-l-none"
                                            value={slug}
                                            onChange={(e) => {
                                                const val = e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '');
                                                setSlug(val);
                                                checkSlug(val);
                                            }}
                                            required
                                        />
                                    </div>
                                    {slugHttp.processing && (
                                        <p className="text-xs text-muted-foreground">{t('Checking...')}</p>
                                    )}
                                    {!slugHttp.processing && slugAvailable !== null && (
                                        <p className={`text-xs ${slugAvailable ? 'text-green-600' : 'text-destructive-foreground'}`}>
                                            {slugAvailable ? t('This URL is available') : t('This URL is not available')}
                                        </p>
                                    )}
                                    <InputError message={errors.slug} />
                                </div>

                                <div className="flex flex-col gap-2">
                                    <label htmlFor="description" className="text-sm font-medium">{t('Description')}</label>
                                    <Textarea
                                        id="description"
                                        name="description"
                                        defaultValue={business.description ?? ''}
                                        rows={3}
                                        placeholder={t('Describe your business...')}
                                    />
                                    <InputError message={errors.description} />
                                </div>

                                <input type="hidden" name="logo" value={logoPath} />

                                <div className="flex flex-col gap-2">
                                    <label className="text-sm font-medium">{t('Logo')}</label>
                                    <div className="flex items-center gap-4">
                                        {previewUrl && (
                                            <img
                                                src={previewUrl}
                                                alt={t('Business logo')}
                                                className="h-16 w-16 rounded-lg object-cover"
                                            />
                                        )}
                                        <div>
                                            <input
                                                type="file"
                                                accept="image/jpeg,image/png,image/webp"
                                                onChange={handleLogoChange}
                                                className="text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                                            />
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {t('JPG, PNG or WebP. Max 2MB.')}
                                            </p>
                                        </div>
                                    </div>
                                    {logoHttp.processing && (
                                        <p className="text-xs text-muted-foreground">{t('Uploading...')}</p>
                                    )}
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div className="flex flex-col gap-2">
                                        <label htmlFor="phone" className="text-sm font-medium">{t('Phone')}</label>
                                        <Input
                                            id="phone"
                                            name="phone"
                                            type="tel"
                                            defaultValue={business.phone ?? ''}
                                        />
                                        <InputError message={errors.phone} />
                                    </div>
                                    <div className="flex flex-col gap-2">
                                        <label htmlFor="email" className="text-sm font-medium">{t('Contact email')}</label>
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            defaultValue={business.email ?? ''}
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                </div>

                                <div className="flex flex-col gap-2">
                                    <label htmlFor="address" className="text-sm font-medium">{t('Address')}</label>
                                    <Input
                                        id="address"
                                        name="address"
                                        defaultValue={business.address ?? ''}
                                        placeholder={t('Street, city, postal code')}
                                    />
                                    <InputError message={errors.address} />
                                </div>
                            </CardPanel>
                            <CardFooter className="flex justify-end">
                                <Button type="submit" disabled={processing}>
                                    {t('Continue')}
                                </Button>
                            </CardFooter>
                        </>
                    )}
                </Form>
            </Card>
        </OnboardingLayout>
    );
}
