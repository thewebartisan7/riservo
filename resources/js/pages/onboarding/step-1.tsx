import OnboardingLayout from '@/layouts/onboarding-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { InputError } from '@/components/input-error';
import { useTrans } from '@/hooks/use-trans';
import { useForm } from '@inertiajs/react';
import { type FormEvent, useCallback, useRef, useState } from 'react';

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

// TODO: Replace fetch() calls with useHttp from @inertiajs/react v3 after upgrading client
function getCsrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

export default function Step1({ business, logoUrl }: Props) {
    const { t } = useTrans();
    const [slugAvailable, setSlugAvailable] = useState<boolean | null>(null);
    const [slugChecking, setSlugChecking] = useState(false);
    const [previewUrl, setPreviewUrl] = useState<string | null>(logoUrl);
    const [logoUploading, setLogoUploading] = useState(false);
    const slugTimerRef = useRef<ReturnType<typeof setTimeout>>(null);

    const form = useForm({
        name: business.name ?? '',
        slug: business.slug ?? '',
        description: business.description ?? '',
        logo: business.logo ?? '',
        phone: business.phone ?? '',
        email: business.email ?? '',
        address: business.address ?? '',
    });

    const checkSlug = useCallback(
        (slug: string) => {
            if (slugTimerRef.current) clearTimeout(slugTimerRef.current);
            setSlugAvailable(null);
            if (!slug || slug.length < 2) return;

            slugTimerRef.current = setTimeout(async () => {
                setSlugChecking(true);
                try {
                    const response = await fetch('/onboarding/slug-check', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': getCsrfToken(),
                            Accept: 'application/json',
                        },
                        body: JSON.stringify({ slug }),
                    });
                    const data = await response.json();
                    setSlugAvailable(data.available);
                } finally {
                    setSlugChecking(false);
                }
            }, 300);
        },
        [],
    );

    async function handleLogoChange(e: React.ChangeEvent<HTMLInputElement>) {
        const file = e.target.files?.[0];
        if (!file) return;

        setLogoUploading(true);
        try {
            const formData = new FormData();
            formData.append('logo', file);

            const response = await fetch('/onboarding/logo-upload', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': getCsrfToken(),
                    Accept: 'application/json',
                },
                body: formData,
            });
            const data = await response.json();
            form.setData('logo', data.path);
            setPreviewUrl(data.url);
        } finally {
            setLogoUploading(false);
        }
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post('/onboarding/step/1');
    }

    return (
        <OnboardingLayout step={1} title={t('Business Profile')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Business Profile')}</CardTitle>
                    <CardDescription>{t('Tell us about your business')}</CardDescription>
                </CardHeader>
                <form onSubmit={submit}>
                    <CardPanel className="flex flex-col gap-4">
                        <div className="flex flex-col gap-2">
                            <label htmlFor="name" className="text-sm font-medium">{t('Business name')}</label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                required
                                autoFocus
                            />
                            <InputError message={form.errors.name} />
                        </div>

                        <div className="flex flex-col gap-2">
                            <label htmlFor="slug" className="text-sm font-medium">{t('Booking URL')}</label>
                            <div className="flex items-center gap-0">
                                <span className="flex h-9 items-center rounded-l-lg border border-r-0 bg-muted px-3 text-sm text-muted-foreground sm:h-8">
                                    riservo.ch/
                                </span>
                                <Input
                                    id="slug"
                                    className="rounded-l-none"
                                    value={form.data.slug}
                                    onChange={(e) => {
                                        const val = e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '');
                                        form.setData('slug', val);
                                        checkSlug(val);
                                    }}
                                    required
                                />
                            </div>
                            {slugChecking && (
                                <p className="text-xs text-muted-foreground">{t('Checking...')}</p>
                            )}
                            {!slugChecking && slugAvailable !== null && (
                                <p className={`text-xs ${slugAvailable ? 'text-green-600' : 'text-destructive-foreground'}`}>
                                    {slugAvailable ? t('This URL is available') : t('This URL is not available')}
                                </p>
                            )}
                            <InputError message={form.errors.slug} />
                        </div>

                        <div className="flex flex-col gap-2">
                            <label htmlFor="description" className="text-sm font-medium">{t('Description')}</label>
                            <Textarea
                                id="description"
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                rows={3}
                                placeholder={t('Describe your business...')}
                            />
                            <InputError message={form.errors.description} />
                        </div>

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
                            {logoUploading && (
                                <p className="text-xs text-muted-foreground">{t('Uploading...')}</p>
                            )}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="flex flex-col gap-2">
                                <label htmlFor="phone" className="text-sm font-medium">{t('Phone')}</label>
                                <Input
                                    id="phone"
                                    type="tel"
                                    value={form.data.phone}
                                    onChange={(e) => form.setData('phone', e.target.value)}
                                />
                                <InputError message={form.errors.phone} />
                            </div>
                            <div className="flex flex-col gap-2">
                                <label htmlFor="email" className="text-sm font-medium">{t('Contact email')}</label>
                                <Input
                                    id="email"
                                    type="email"
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                />
                                <InputError message={form.errors.email} />
                            </div>
                        </div>

                        <div className="flex flex-col gap-2">
                            <label htmlFor="address" className="text-sm font-medium">{t('Address')}</label>
                            <Input
                                id="address"
                                value={form.data.address}
                                onChange={(e) => form.setData('address', e.target.value)}
                                placeholder={t('Street, city, postal code')}
                            />
                            <InputError message={form.errors.address} />
                        </div>
                    </CardPanel>
                    <CardFooter className="flex justify-end">
                        <Button type="submit" disabled={form.processing}>
                            {t('Continue')}
                        </Button>
                    </CardFooter>
                </form>
            </Card>
        </OnboardingLayout>
    );
}
