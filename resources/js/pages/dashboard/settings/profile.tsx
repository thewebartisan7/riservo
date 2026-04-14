import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, FieldLabel, FieldError, FieldDescription } from '@/components/ui/field';
import { Avatar, AvatarImage, AvatarFallback } from '@/components/ui/avatar';
import { SectionHeading, SectionTitle, SectionRule } from '@/components/ui/section-heading';
import { useTrans } from '@/hooks/use-trans';
import { Form, useHttp } from '@inertiajs/react';
import {
    update,
    checkSlug as checkSlugAction,
    uploadLogo as uploadLogoAction,
} from '@/actions/App/Http/Controllers/Dashboard/Settings/ProfileController';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { FileUploadResponse, SlugCheckResponse } from '@/types';
import { getInitials } from '@/lib/booking-format';
import { CheckIcon } from 'lucide-react';

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
    const [name, setName] = useState(business.name ?? '');
    const [slug, setSlug] = useState(business.slug ?? '');
    const [slugAvailable, setSlugAvailable] = useState<boolean | null>(null);
    const [logoPath, setLogoPath] = useState(business.logo ?? '');
    const [previewUrl, setPreviewUrl] = useState<string | null>(logoUrl);
    const slugTimerRef = useRef<ReturnType<typeof setTimeout>>(null);
    const slugHttp = useHttp({ slug: '' });
    const logoHttp = useHttp({ logo: null as File | null });
    const fileInputRef = useRef<HTMLInputElement>(null);
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

    const initials = getInitials(name || business.name) || '·';

    return (
        <SettingsLayout
            title={t('Business Profile')}
            eyebrow={t('Settings · Business')}
            heading={t('Business profile')}
            description={t(
                'How your business shows up on your booking page. A name, a few words, and the ways customers reach you.',
            )}
        >
            <Form action={update()}>
                {({ errors, processing }) => (
                    <Card>
                        <CardPanel className="flex flex-col gap-8 p-5 sm:p-6">
                            <section className="flex flex-col gap-4">
                                <SectionHeading>
                                    <SectionTitle>{t('Identity')}</SectionTitle>
                                    <SectionRule />
                                </SectionHeading>

                                <Field>
                                    <FieldLabel>{t('Business name')}</FieldLabel>
                                    <Input
                                        name="name"
                                        value={name}
                                        onChange={(e) => setName(e.target.value)}
                                        required
                                    />
                                    {errors.name && <FieldError match>{errors.name}</FieldError>}
                                </Field>

                                <Field>
                                    <FieldLabel>{t('Description')}</FieldLabel>
                                    <Textarea
                                        name="description"
                                        defaultValue={business.description ?? ''}
                                        rows={3}
                                        placeholder={t('A short welcome — what you do, who you do it for.')}
                                    />
                                    <FieldDescription>
                                        {t('Appears under your name on the public booking page.')}
                                    </FieldDescription>
                                    {errors.description && <FieldError match>{errors.description}</FieldError>}
                                </Field>

                                <Field>
                                    <FieldLabel>{t('Logo')}</FieldLabel>
                                    <div className="flex items-center gap-4">
                                        <Avatar className="size-14 shrink-0 rounded-xl border border-border bg-muted">
                                            {previewUrl && (
                                                <AvatarImage
                                                    src={previewUrl}
                                                    alt=""
                                                    className="rounded-xl object-cover"
                                                />
                                            )}
                                            <AvatarFallback className="rounded-xl bg-muted font-display text-sm font-semibold text-muted-foreground">
                                                {initials}
                                            </AvatarFallback>
                                        </Avatar>
                                        <div className="flex flex-col items-start gap-1.5">
                                            <input
                                                ref={fileInputRef}
                                                type="file"
                                                accept="image/jpeg,image/png,image/webp"
                                                onChange={handleLogoChange}
                                                className="sr-only"
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => fileInputRef.current?.click()}
                                                loading={logoHttp.processing}
                                            >
                                                {previewUrl ? t('Replace logo') : t('Upload logo')}
                                            </Button>
                                            <FieldDescription>
                                                {t('JPG, PNG, or WebP · up to 2 MB')}
                                            </FieldDescription>
                                        </div>
                                    </div>
                                    <input type="hidden" name="logo" value={logoPath} />
                                </Field>
                            </section>

                            <section className="flex flex-col gap-4">
                                <SectionHeading>
                                    <SectionTitle>{t('Public URL')}</SectionTitle>
                                    <SectionRule />
                                </SectionHeading>

                                <Field>
                                    <FieldLabel>{t('Booking URL')}</FieldLabel>
                                    <div className="flex w-full items-stretch overflow-hidden rounded-lg border border-input bg-background shadow-xs/5 not-dark:bg-clip-padding focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/24 dark:bg-input/32">
                                        <span className="flex shrink-0 items-center border-r border-input bg-muted/72 px-3 font-display text-sm text-muted-foreground">
                                            riservo.ch/
                                        </span>
                                        <Input
                                            name="slug"
                                            unstyled
                                            className="flex h-8.5 min-w-0 flex-1 bg-transparent px-3 font-display text-sm text-foreground outline-none placeholder:text-muted-foreground/60 sm:h-7.5"
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
                                        <FieldDescription>{t('Checking availability…')}</FieldDescription>
                                    )}
                                    {!slugHttp.processing && slugAvailable === true && (
                                        <p className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                                            <CheckIcon className="size-3.5 text-primary" aria-hidden="true" />
                                            {t('This URL is available.')}
                                        </p>
                                    )}
                                    {!slugHttp.processing && slugAvailable === false && (
                                        <p className="text-xs text-primary">
                                            {t('This URL is already taken. Try another.')}
                                        </p>
                                    )}
                                    {!slugHttp.processing && slugAvailable === null && (
                                        <FieldDescription>
                                            {t('Lowercase letters, numbers, and dashes. This is what customers will bookmark.')}
                                        </FieldDescription>
                                    )}
                                    {errors.slug && <FieldError match>{errors.slug}</FieldError>}
                                </Field>
                            </section>

                            <section className="flex flex-col gap-4">
                                <SectionHeading>
                                    <SectionTitle>{t('Contact')}</SectionTitle>
                                    <SectionRule />
                                </SectionHeading>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field>
                                        <FieldLabel>{t('Phone')}</FieldLabel>
                                        <Input
                                            name="phone"
                                            type="tel"
                                            defaultValue={business.phone ?? ''}
                                            placeholder="+41 00 000 00 00"
                                        />
                                        {errors.phone && <FieldError match>{errors.phone}</FieldError>}
                                    </Field>
                                    <Field>
                                        <FieldLabel>{t('Contact email')}</FieldLabel>
                                        <Input
                                            name="email"
                                            type="email"
                                            defaultValue={business.email ?? ''}
                                            placeholder="hello@example.ch"
                                        />
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
                            </section>
                        </CardPanel>
                        <CardFooter className="justify-end border-t bg-muted/50 px-5 py-3 sm:px-6">
                            <Button type="submit" loading={processing}>
                                {t('Save changes')}
                            </Button>
                        </CardFooter>
                    </Card>
                )}
            </Form>
        </SettingsLayout>
    );
}
