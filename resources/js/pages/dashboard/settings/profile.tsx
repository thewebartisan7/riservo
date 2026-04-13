import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, FieldLabel, FieldError, FieldDescription } from '@/components/ui/field';
import { useTrans } from '@/hooks/use-trans';
import { Form, useHttp } from '@inertiajs/react';
import {
    update,
    checkSlug as checkSlugAction,
    uploadLogo as uploadLogoAction,
} from '@/actions/App/Http/Controllers/Dashboard/Settings/ProfileController';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { FileUploadResponse, SlugCheckResponse } from '@/types';

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

export default function Profile({ business, logoUrl }: Props) {
    const { t } = useTrans();
    const [slug, setSlug] = useState(business.slug ?? '');
    const [slugAvailable, setSlugAvailable] = useState<boolean | null>(null);
    const [logoPath, setLogoPath] = useState(business.logo ?? '');
    const [previewUrl, setPreviewUrl] = useState<string | null>(logoUrl);
    const slugTimerRef = useRef<ReturnType<typeof setTimeout>>(null);
    const slugHttp = useHttp({ slug: '' });
    const logoHttp = useHttp({ logo: null as File | null });
    const pendingLogoUpload = useRef(false);
    const pendingSlugCheck = useRef(false);

    const checkSlug = useCallback((val: string) => {
        if (slugTimerRef.current) clearTimeout(slugTimerRef.current);
        setSlugAvailable(null);
        if (!val || val.length < 2) return;

        slugTimerRef.current = setTimeout(() => {
            slugHttp.setData('slug', val);
            pendingSlugCheck.current = true;
        }, 300);
    }, []);

    useEffect(() => {
        if (pendingSlugCheck.current && slugHttp.data.slug) {
            pendingSlugCheck.current = false;
            slugHttp.post(checkSlugAction.url(), {
                onSuccess: (response: unknown) => {
                    const data = response as SlugCheckResponse;
                    setSlugAvailable(data.available);
                },
            });
        }
    }, [slugHttp.data.slug]);

    function handleLogoChange(e: React.ChangeEvent<HTMLInputElement>) {
        const file = e.target.files?.[0];
        if (!file) return;

        logoHttp.setData('logo', file);
        pendingLogoUpload.current = true;
    }

    useEffect(() => {
        if (pendingLogoUpload.current && logoHttp.data.logo) {
            pendingLogoUpload.current = false;
            logoHttp.post(uploadLogoAction.url(), {
                onSuccess: (response: unknown) => {
                    const data = response as FileUploadResponse;
                    setLogoPath(data.path);
                    setPreviewUrl(data.url);
                },
            });
        }
    }, [logoHttp.data.logo]);

    return (
        <SettingsLayout title={t('Business Profile')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Business Profile')}</CardTitle>
                    <CardDescription>{t('Update your business information')}</CardDescription>
                </CardHeader>
                <Form action={update()}>
                    {({ errors, processing }) => (
                        <>
                            <CardPanel className="flex flex-col gap-4">
                                <Field>
                                    <FieldLabel>{t('Business name')}</FieldLabel>
                                    <Input name="name" defaultValue={business.name ?? ''} required />
                                    {errors.name && <FieldError match>{errors.name}</FieldError>}
                                </Field>

                                <Field>
                                    <FieldLabel>{t('Booking URL')}</FieldLabel>
                                    <div className="flex items-center gap-0">
                                        <span className="flex h-9 items-center rounded-l-lg border border-r-0 bg-muted px-3 text-sm text-muted-foreground sm:h-8">
                                            riservo.ch/
                                        </span>
                                        <Input
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
                                        <FieldDescription>{t('Checking...')}</FieldDescription>
                                    )}
                                    {!slugHttp.processing && slugAvailable !== null && (
                                        <p className={`text-xs ${slugAvailable ? 'text-green-600' : 'text-destructive-foreground'}`}>
                                            {slugAvailable ? t('This URL is available') : t('This URL is not available')}
                                        </p>
                                    )}
                                    {errors.slug && <FieldError match>{errors.slug}</FieldError>}
                                </Field>

                                <Field>
                                    <FieldLabel>{t('Description')}</FieldLabel>
                                    <Textarea
                                        name="description"
                                        defaultValue={business.description ?? ''}
                                        rows={3}
                                        placeholder={t('Describe your business...')}
                                    />
                                    {errors.description && <FieldError match>{errors.description}</FieldError>}
                                </Field>

                                <input type="hidden" name="logo" value={logoPath} />

                                <Field>
                                    <FieldLabel>{t('Logo')}</FieldLabel>
                                    <div className="flex items-center gap-4">
                                        {previewUrl && (
                                            <img src={previewUrl} alt={t('Business logo')} className="h-16 w-16 rounded-lg object-cover" />
                                        )}
                                        <div>
                                            <input
                                                type="file"
                                                accept="image/jpeg,image/png,image/webp"
                                                onChange={handleLogoChange}
                                                className="text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                                            />
                                            <FieldDescription>{t('JPG, PNG or WebP. Max 2MB.')}</FieldDescription>
                                        </div>
                                    </div>
                                    {logoHttp.processing && <FieldDescription>{t('Uploading...')}</FieldDescription>}
                                </Field>

                                <div className="grid grid-cols-2 gap-4">
                                    <Field>
                                        <FieldLabel>{t('Phone')}</FieldLabel>
                                        <Input name="phone" type="tel" defaultValue={business.phone ?? ''} />
                                        {errors.phone && <FieldError match>{errors.phone}</FieldError>}
                                    </Field>
                                    <Field>
                                        <FieldLabel>{t('Contact email')}</FieldLabel>
                                        <Input name="email" type="email" defaultValue={business.email ?? ''} />
                                        {errors.email && <FieldError match>{errors.email}</FieldError>}
                                    </Field>
                                </div>

                                <Field>
                                    <FieldLabel>{t('Address')}</FieldLabel>
                                    <Input
                                        name="address"
                                        defaultValue={business.address ?? ''}
                                        placeholder={t('Street, city, postal code')}
                                    />
                                    {errors.address && <FieldError match>{errors.address}</FieldError>}
                                </Field>
                            </CardPanel>
                            <CardFooter className="flex justify-end">
                                <Button type="submit" disabled={processing}>{t('Save changes')}</Button>
                            </CardFooter>
                        </>
                    )}
                </Form>
            </Card>
        </SettingsLayout>
    );
}
