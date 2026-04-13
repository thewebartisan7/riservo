import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/hooks/use-trans';
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { CheckCircleIcon, ClipboardIcon, PartyPopperIcon, SettingsIcon, UsersIcon, BellIcon } from 'lucide-react';

interface Props {
    publicUrl: string;
    businessName: string;
}

export default function Welcome({ publicUrl, businessName }: Props) {
    const { t } = useTrans();
    const [copied, setCopied] = useState(false);

    function copyUrl() {
        navigator.clipboard.writeText(publicUrl);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }

    return (
        <AuthenticatedLayout title={t('Welcome')}>
            <div className="mx-auto max-w-2xl space-y-6">
                <div className="text-center">
                    <div className="mb-4 inline-flex h-16 w-16 items-center justify-center rounded-full bg-primary/10">
                        <PartyPopperIcon className="h-8 w-8 text-primary" />
                    </div>
                    <h1 className="text-2xl font-bold">{t("You're all set!")}</h1>
                    <p className="mt-2 text-muted-foreground">
                        {t(':business is now live and ready to accept bookings.', { business: businessName })}
                    </p>
                </div>

                <Card>
                    <CardPanel className="p-6">
                        <p className="mb-3 text-sm font-medium">{t('Your public booking URL')}</p>
                        <div className="flex items-center gap-2">
                            <code className="flex-1 rounded-md border bg-muted/50 px-3 py-2 text-sm">
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
                        <p className="mt-2 text-xs text-muted-foreground">
                            {t('Share this link with your customers so they can book appointments.')}
                        </p>
                    </CardPanel>
                </Card>

                <div className="grid gap-4 sm:grid-cols-3">
                    <NextStepCard
                        icon={<SettingsIcon className="h-5 w-5" />}
                        title={t('Add more services')}
                        description={t('Create additional services for your customers to book.')}
                    />
                    <NextStepCard
                        icon={<UsersIcon className="h-5 w-5" />}
                        title={t('Manage your team')}
                        description={t('Invite more collaborators and manage schedules.')}
                    />
                    <NextStepCard
                        icon={<BellIcon className="h-5 w-5" />}
                        title={t('Set up notifications')}
                        description={t('Configure email reminders for your customers.')}
                    />
                </div>

                <div className="text-center">
                    <Link href="/dashboard">
                        <Button>{t('Go to Dashboard')}</Button>
                    </Link>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function NextStepCard({ icon, title, description }: { icon: React.ReactNode; title: string; description: string }) {
    return (
        <Card>
            <CardPanel className="p-4">
                <div className="mb-2 text-primary">{icon}</div>
                <h3 className="text-sm font-medium">{title}</h3>
                <p className="mt-1 text-xs text-muted-foreground">{description}</p>
            </CardPanel>
        </Card>
    );
}
