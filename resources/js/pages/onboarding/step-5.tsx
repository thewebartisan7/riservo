import OnboardingLayout from '@/layouts/onboarding-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/hooks/use-trans';
import { router } from '@inertiajs/react';
import { store, show } from '@/actions/App/Http/Controllers/OnboardingController';
import { useState } from 'react';
import { CheckCircleIcon, ClipboardIcon, PencilIcon } from 'lucide-react';

const DAY_NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

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
    hours: Array<{
        day_of_week: number;
        windows: Array<{ open_time: string; close_time: string }>;
    }>;
    service: {
        name: string;
        duration_minutes: number;
        price: number | null;
    } | null;
    invitations: Array<{
        email: string;
        service_ids: number[] | null;
    }>;
    publicUrl: string;
}

export default function Step5({ business, logoUrl, hours, service, invitations, publicUrl }: Props) {
    const { t } = useTrans();
    const [processing, setProcessing] = useState(false);
    const [copied, setCopied] = useState(false);

    function launch() {
        setProcessing(true);
        router.post(store(5), {}, {
            onFinish: () => setProcessing(false),
        });
    }

    function copyUrl() {
        navigator.clipboard.writeText(publicUrl);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }

    function formatPrice(price: number | null): string {
        if (price === null) return t('On request');
        if (price === 0) return t('Free');
        return `CHF ${Number(price).toFixed(2)}`;
    }

    return (
        <OnboardingLayout step={5} title={t('Review & Launch')}>
            <div className="flex flex-col gap-4">
                <Card>
                    <CardHeader>
                        <CardTitle>{t('Review & Launch')}</CardTitle>
                        <CardDescription>{t('Review your setup and launch your booking page')}</CardDescription>
                    </CardHeader>
                    <CardPanel className="flex flex-col gap-6">
                        {/* Public URL */}
                        <div className="rounded-lg border border-primary/20 bg-primary/5 p-4">
                            <p className="mb-2 text-sm font-medium">{t('Your public booking URL')}</p>
                            <div className="flex items-center gap-2">
                                <code className="flex-1 rounded-md bg-background px-3 py-2 text-sm">
                                    {publicUrl}
                                </code>
                                <Button type="button" variant="outline" size="sm" onClick={copyUrl}>
                                    {copied ? (
                                        <><CheckCircleIcon className="mr-1 h-4 w-4" />{t('Copied')}</>
                                    ) : (
                                        <><ClipboardIcon className="mr-1 h-4 w-4" />{t('Copy')}</>
                                    )}
                                </Button>
                            </div>
                        </div>

                        {/* Business Profile */}
                        <SummarySection
                            title={t('Business Profile')}
                            editStep={1}
                        >
                            <div className="flex items-start gap-4">
                                {logoUrl && (
                                    <img src={logoUrl} alt="" className="h-12 w-12 rounded-lg object-cover" />
                                )}
                                <div className="flex flex-col gap-1 text-sm">
                                    <p className="font-medium">{business.name}</p>
                                    {business.description && <p className="text-muted-foreground">{business.description}</p>}
                                    {business.phone && <p>{business.phone}</p>}
                                    {business.email && <p>{business.email}</p>}
                                    {business.address && <p>{business.address}</p>}
                                </div>
                            </div>
                        </SummarySection>

                        {/* Working Hours */}
                        <SummarySection title={t('Working Hours')} editStep={2}>
                            <div className="grid gap-1 text-sm">
                                {Array.from({ length: 7 }, (_, i) => i + 1).map((day) => {
                                    const dayHours = hours.find((h) => h.day_of_week === day);
                                    return (
                                        <div key={day} className="flex gap-2">
                                            <span className="w-24 font-medium">{t(DAY_NAMES[day - 1])}</span>
                                            <span className="text-muted-foreground">
                                                {dayHours && dayHours.windows.length > 0
                                                    ? dayHours.windows.map((w) => `${w.open_time} - ${w.close_time}`).join(', ')
                                                    : t('Closed')}
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                        </SummarySection>

                        {/* Service */}
                        {service && (
                            <SummarySection title={t('First Service')} editStep={3}>
                                <div className="flex items-center justify-between text-sm">
                                    <div>
                                        <p className="font-medium">{service.name}</p>
                                        <p className="text-muted-foreground">{service.duration_minutes} {t('minutes')}</p>
                                    </div>
                                    <p className="font-medium">{formatPrice(service.price)}</p>
                                </div>
                            </SummarySection>
                        )}

                        {/* Invitations */}
                        {invitations.length > 0 && (
                            <SummarySection title={t('Invited Collaborators')} editStep={4}>
                                <div className="flex flex-col gap-1 text-sm">
                                    {invitations.map((inv, i) => (
                                        <p key={i} className="text-muted-foreground">{inv.email}</p>
                                    ))}
                                </div>
                            </SummarySection>
                        )}
                    </CardPanel>
                    <CardFooter className="flex justify-between">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.visit(show(4))}
                        >
                            {t('Back')}
                        </Button>
                        <Button onClick={launch} disabled={processing}>
                            {t('Launch your business')}
                        </Button>
                    </CardFooter>
                </Card>
            </div>
        </OnboardingLayout>
    );
}

function SummarySection({ title, editStep, children }: { title: string; editStep: number; children: React.ReactNode }) {
    const { t } = useTrans();

    return (
        <div className="rounded-lg border p-4">
            <div className="mb-3 flex items-center justify-between">
                <h3 className="text-sm font-medium">{title}</h3>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-7 text-xs"
                    onClick={() => router.visit(show(editStep))}
                >
                    <PencilIcon className="mr-1 h-3 w-3" />
                    {t('Edit')}
                </Button>
            </div>
            {children}
        </div>
    );
}
