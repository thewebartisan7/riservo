import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardPanel } from '@/components/ui/card';
import { Field, FieldLabel, FieldDescription } from '@/components/ui/field';
import {
    Select,
    SelectItem,
    SelectPopup,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    SectionHeading,
    SectionTitle,
    SectionRule,
} from '@/components/ui/section-heading';
import { Frame, FramePanel } from '@/components/ui/frame';
import { useTrans } from '@/hooks/use-trans';
import { EmbedSnippet } from '@/components/settings/embed-snippet';
import { useState } from 'react';
import { ArrowUpRightIcon } from 'lucide-react';

interface Service {
    id: number;
    name: string;
    slug: string;
}

interface Props {
    slug: string;
    baseUrl: string;
    embedUrl: string;
    appUrl: string;
    services: Service[];
}

export default function Embed({ slug, baseUrl, embedUrl, appUrl, services }: Props) {
    const { t } = useTrans();
    const [previewService, setPreviewService] = useState<string>('');

    const iframeUrl = previewService ? `${baseUrl}/${previewService}?embed=1` : embedUrl;
    const iframeSnippet = `<iframe src="${iframeUrl}" width="100%" height="700" frameborder="0"></iframe>`;
    const popupButton = previewService
        ? `<button data-riservo-open data-riservo-service="${previewService}">${t('Book Now')}</button>`
        : `<button data-riservo-open>${t('Book Now')}</button>`;
    const popupSnippet = `<script src="${appUrl}/embed.js" data-slug="${slug}"></script>\n${popupButton}`;
    const displayUrl = baseUrl.replace(/^https?:\/\//, '');

    return (
        <SettingsLayout
            title={t('Embed & Share')}
            eyebrow={t('Settings · Share')}
            heading={t('Embed & share')}
            description={t(
                'Put the booking flow wherever your customers find you — a direct link, an iframe on your site, or a popup button.',
            )}
        >
            <div className="flex flex-col gap-10">
                <section className="flex flex-col gap-4">
                    <SectionHeading>
                        <SectionTitle>{t('Direct link')}</SectionTitle>
                        <SectionRule />
                    </SectionHeading>

                    <Card>
                        <CardPanel className="flex flex-col gap-4 p-5 sm:p-6">
                            <div className="flex items-center justify-between gap-4">
                                <div className="flex min-w-0 flex-col gap-1">
                                    <p className="text-[10px] font-medium uppercase tracking-[0.22em] text-primary">
                                        {t('Your booking page')}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {t('Paste this anywhere — email signature, Instagram bio, QR code.')}
                                    </p>
                                </div>
                                <a
                                    href={baseUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex shrink-0 items-center gap-1 text-[11px] uppercase tracking-[0.22em] text-muted-foreground transition-colors hover:text-foreground"
                                >
                                    {t('Preview')}
                                    <ArrowUpRightIcon className="size-3" aria-hidden="true" />
                                </a>
                            </div>
                            <EmbedSnippet
                                label={t('Public booking URL')}
                                code={displayUrl}
                                variant="link"
                            />
                        </CardPanel>
                    </Card>
                </section>

                <section className="flex flex-col gap-4">
                    <SectionHeading>
                        <SectionTitle>{t('Iframe embed')}</SectionTitle>
                        <SectionRule />
                    </SectionHeading>

                    <Card>
                        <CardPanel className="flex flex-col gap-5 p-5 sm:p-6">
                            <p className="max-w-xl text-sm text-muted-foreground">
                                {t('Drop the booking flow into any page on your website. Great for a dedicated "Book" page.')}
                            </p>
                            {services.length > 0 && (
                                <Field>
                                    <FieldLabel>{t('Pre-filter by service')}</FieldLabel>
                                    <Select
                                        value={previewService}
                                        onValueChange={(val) => setPreviewService(val ?? '')}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectPopup>
                                            <SelectItem value="">{t('All services')}</SelectItem>
                                            {services.map((s) => (
                                                <SelectItem key={s.id} value={s.slug}>
                                                    {s.name}
                                                </SelectItem>
                                            ))}
                                        </SelectPopup>
                                    </Select>
                                    <FieldDescription>
                                        {t('Optional. Skips the service picker and opens directly on the selected service.')}
                                    </FieldDescription>
                                </Field>
                            )}
                            <EmbedSnippet label={t('Iframe snippet')} code={iframeSnippet} />
                        </CardPanel>
                    </Card>
                </section>

                <section className="flex flex-col gap-4">
                    <SectionHeading>
                        <SectionTitle>{t('Popup embed')}</SectionTitle>
                        <SectionRule />
                    </SectionHeading>

                    <Card>
                        <CardPanel className="flex flex-col gap-5 p-5 sm:p-6">
                            <p className="max-w-xl text-sm text-muted-foreground">
                                {t('Add a "Book now" button to any page. The booking flow opens in a focused overlay.')}
                            </p>
                            <EmbedSnippet label={t('Popup snippet')} code={popupSnippet} />
                        </CardPanel>
                    </Card>
                </section>

                <section className="flex flex-col gap-4">
                    <SectionHeading>
                        <SectionTitle>{t('Live preview')}</SectionTitle>
                        <SectionRule />
                    </SectionHeading>

                    <Frame>
                        <FramePanel className="overflow-hidden p-0">
                            <iframe
                                src={iframeUrl}
                                width="100%"
                                height="600"
                                className="block border-0"
                                title={t('Booking form preview')}
                            />
                        </FramePanel>
                    </Frame>
                </section>
            </div>
        </SettingsLayout>
    );
}
