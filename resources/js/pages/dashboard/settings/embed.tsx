import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel } from '@/components/ui/card';
import { useTrans } from '@/hooks/use-trans';
import { EmbedSnippet } from '@/components/settings/embed-snippet';
import { useState } from 'react';

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
    const popupSnippet = `<script src="${appUrl}/embed.js" data-slug="${slug}"></script>\n<button data-riservo-open>${t('Book Now')}</button>`;

    return (
        <SettingsLayout title={t('Embed & Share')}>
            <div className="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>{t('Booking Link')}</CardTitle>
                        <CardDescription>{t('Share this link with your customers')}</CardDescription>
                    </CardHeader>
                    <CardPanel>
                        <EmbedSnippet label={t('Public booking URL')} code={baseUrl} />
                    </CardPanel>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('Iframe Embed')}</CardTitle>
                        <CardDescription>{t('Embed your booking form directly on your website')}</CardDescription>
                    </CardHeader>
                    <CardPanel className="flex flex-col gap-4">
                        {services.length > 0 && (
                            <div className="flex flex-col gap-2">
                                <label className="text-sm font-medium">{t('Pre-filter by service (optional)')}</label>
                                <select
                                    value={previewService}
                                    onChange={(e) => setPreviewService(e.target.value)}
                                    className="flex h-9 w-full rounded-lg border bg-background px-3 py-1 text-sm shadow-xs sm:h-8"
                                >
                                    <option value="">{t('All services')}</option>
                                    {services.map((s) => (
                                        <option key={s.id} value={s.slug}>{s.name}</option>
                                    ))}
                                </select>
                            </div>
                        )}
                        <EmbedSnippet label={t('Iframe snippet')} code={iframeSnippet} />
                    </CardPanel>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('Popup Embed')}</CardTitle>
                        <CardDescription>{t('Add a button that opens the booking form in a modal overlay')}</CardDescription>
                    </CardHeader>
                    <CardPanel>
                        <EmbedSnippet label={t('Popup snippet')} code={popupSnippet} />
                    </CardPanel>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('Preview')}</CardTitle>
                        <CardDescription>{t('Live preview of your embedded booking form')}</CardDescription>
                    </CardHeader>
                    <CardPanel>
                        <div className="overflow-hidden rounded-lg border">
                            <iframe
                                src={iframeUrl}
                                width="100%"
                                height="600"
                                className="border-0"
                                title={t('Booking form preview')}
                            />
                        </div>
                    </CardPanel>
                </Card>
            </div>
        </SettingsLayout>
    );
}
