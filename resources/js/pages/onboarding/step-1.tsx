import OnboardingLayout from '@/layouts/onboarding-layout';
import { Card, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { InputGroup, InputGroupAddon, InputGroupInput, InputGroupText } from '@/components/ui/input-group';
import { Field, FieldLabel, FieldError, FieldDescription } from '@/components/ui/field';
import { Display } from '@/components/ui/display';
import { Avatar, AvatarImage, AvatarFallback } from '@/components/ui/avatar';
import { useTrans } from '@/hooks/use-trans';
import { Form, useHttp } from '@inertiajs/react';
import { store, checkSlug as checkSlugAction, uploadLogo as uploadLogoAction } from '@/actions/App/Http/Controllers/OnboardingController';
import { useCallback, useRef, useState } from 'react';
import { UploadIcon } from 'lucide-react';
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

export default function Step1({ business, logoUrl }: Props) {
    const { t } = useTrans();
    const [name, setName] = useState(business.name ?? '');
    const [slug, setSlug] = useState(business.slug ?? '');
    const [slugAvailable, setSlugAvailable] = useState<boolean | null>(null);
    const [logoPath, setLogoPath] = useState(business.logo ?? '');
    const [previewUrl, setPreviewUrl] = useState<string | null>(logoUrl);
    const fileInputRef = useRef<HTMLInputElement | null>(null);
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
                        const data = response as SlugCheckResponse;
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
                const data = response as FileUploadResponse;
                setLogoPath(data.path);
                setPreviewUrl(data.url);
            },
        });
    }

    const initials = name
        ? name
              .split(/\s+/)
              .slice(0, 2)
              .map((w) => w[0])
              .join('')
              .toUpperCase()
        : '·';

    return (
        <OnboardingLayout
            step={1}
            title={t('Business profile')}
            eyebrow={t('Welcome to riservo')}
            heading={t('Set the stage for your studio')}
            description={t('These details shape the booking page your customers will see. Nothing is final — adjust anything later from settings.')}
        >
            <Card>
                <Form action={store(1)}>
                    {({ errors, processing }) => (
                        <>
                            <CardPanel className="flex flex-col gap-5">
                                <Field>
                                    <FieldLabel>{t('Business name')}</FieldLabel>
                                    <Input
                                        name="name"
                                        value={name}
                                        onChange={(e) => setName(e.target.value)}
                                        aria-invalid={!!errors.name}
                                        required
                                        autoFocus
                                    />
                                    {errors.name && <FieldError match>{errors.name}</FieldError>}
                                </Field>

                                <Field>
                                    <FieldLabel>{t('Booking URL')}</FieldLabel>
                                    <InputGroup>
                                        <InputGroupAddon align="inline-start">
                                            <InputGroupText>riservo.ch/</InputGroupText>
                                        </InputGroupAddon>
                                        <InputGroupInput
                                            name="slug"
                                            value={slug}
                                            onChange={(e) => {
                                                const val = e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '');
                                                setSlug(val);
                                                checkSlug(val);
                                            }}
                                            aria-invalid={!!errors.slug}
                                            required
                                        />
                                    </InputGroup>
                                    <FieldDescription className="min-h-[1.25em]">
                                        {slugHttp.processing ? (
                                            <span className="text-muted-foreground">{t('Checking availability…')}</span>
                                        ) : slugAvailable === true ? (
                                            <span className="text-foreground">{t('Available — this is yours.')}</span>
                                        ) : slugAvailable === false ? (
                                            <span className="text-primary">{t('Taken — try another variant.')}</span>
                                        ) : (
                                            <span>{t('Lowercase letters, numbers, and dashes only.')}</span>
                                        )}
                                    </FieldDescription>
                                    {errors.slug && <FieldError match>{errors.slug}</FieldError>}
                                </Field>

                                <Field>
                                    <FieldLabel>{t('Short description')}</FieldLabel>
                                    <Textarea
                                        name="description"
                                        defaultValue={business.description ?? ''}
                                        rows={3}
                                        placeholder={t('A sentence or two about what you do.')}
                                    />
                                    <FieldDescription>
                                        {t('Shown under your business name on the booking page.')}
                                    </FieldDescription>
                                    {errors.description && <FieldError match>{errors.description}</FieldError>}
                                </Field>

                                <input type="hidden" name="logo" value={logoPath} />

                                <Field>
                                    <FieldLabel>{t('Logo')}</FieldLabel>
                                    <div className="flex items-center gap-4">
                                        <Avatar className="size-14 shrink-0 rounded-xl border border-border bg-muted">
                                            {previewUrl && (
                                                <AvatarImage
                                                    src={previewUrl}
                                                    alt={t('Business logo')}
                                                    className="rounded-xl object-cover"
                                                />
                                            )}
                                            <AvatarFallback className="rounded-xl bg-muted font-display text-sm font-semibold text-muted-foreground">
                                                {initials}
                                            </AvatarFallback>
                                        </Avatar>
                                        <div className="flex flex-col gap-1">
                                            <div className="flex items-center gap-2">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    loading={logoHttp.processing}
                                                    onClick={() => fileInputRef.current?.click()}
                                                >
                                                    <UploadIcon />
                                                    {previewUrl ? t('Replace') : t('Upload')}
                                                </Button>
                                                {previewUrl && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-muted-foreground"
                                                        onClick={() => {
                                                            setPreviewUrl(null);
                                                            setLogoPath('');
                                                        }}
                                                    >
                                                        {t('Remove')}
                                                    </Button>
                                                )}
                                            </div>
                                            <FieldDescription>
                                                {t('Square works best. JPG, PNG or WebP, up to 2MB.')}
                                            </FieldDescription>
                                        </div>
                                        <input
                                            ref={fileInputRef}
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp"
                                            onChange={handleLogoChange}
                                            className="sr-only"
                                            aria-hidden="true"
                                            tabIndex={-1}
                                        />
                                    </div>
                                </Field>

                                <div
                                    role="separator"
                                    aria-hidden="true"
                                    className="flex items-center gap-3 py-1 text-[10px] uppercase tracking-[0.22em] text-muted-foreground"
                                >
                                    <span className="h-px flex-1 bg-border" />
                                    <span>{t('How customers reach you')}</span>
                                    <span className="h-px flex-1 bg-border" />
                                </div>

                                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                    <Field>
                                        <FieldLabel>{t('Phone')}</FieldLabel>
                                        <Input
                                            name="phone"
                                            type="tel"
                                            defaultValue={business.phone ?? ''}
                                            placeholder="+41 79 000 00 00"
                                            aria-invalid={!!errors.phone}
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
                                            aria-invalid={!!errors.email}
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
                                        aria-invalid={!!errors.address}
                                    />
                                    {errors.address && <FieldError match>{errors.address}</FieldError>}
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
                                        {t('Continue to hours')}
                                    </Display>
                                </Button>
                            </CardFooter>
                        </>
                    )}
                </Form>
            </Card>
        </OnboardingLayout>
    );
}
