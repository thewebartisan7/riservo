import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardPanel } from '@/components/ui/card';
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    SectionHeading,
    SectionTitle,
    SectionRule,
} from '@/components/ui/section-heading';
import { useTrans } from '@/hooks/use-trans';
import { Form, usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';
import {
    connect,
    disconnect,
} from '@/actions/App/Http/Controllers/Dashboard/Settings/CalendarIntegrationController';

interface Props {
    connected: boolean;
    googleAccountEmail: string | null;
    error: string | null;
}

export default function CalendarIntegration({ connected, googleAccountEmail, error }: Props) {
    const { t } = useTrans();
    const flash = usePage<PageProps>().props.flash;

    // `flash.error` is a one-shot message (e.g. OAuth cancelled at Google).
    // `error` is Session 2's persistent sync-error surface.
    const bannerError = flash.error ?? error;

    return (
        <SettingsLayout
            title={t('Calendar Integration')}
            eyebrow={t('Settings · You')}
            heading={t('Calendar integration')}
            description={t('Connect your Google Calendar so bookings flow both ways between riservo and your calendar.')}
        >
            <div className="flex flex-col gap-10">
                {bannerError && (
                    <Alert variant="error">
                        <AlertTitle>{t('Calendar sync error')}</AlertTitle>
                        <AlertDescription>{bannerError}</AlertDescription>
                    </Alert>
                )}

                <section className="flex flex-col gap-4">
                    <SectionHeading>
                        <SectionTitle>{t('Google Calendar')}</SectionTitle>
                        <SectionRule />
                    </SectionHeading>

                    <Card>
                        <CardPanel className="flex flex-col gap-5 p-5 sm:p-6">
                            {connected ? (
                                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="flex min-w-0 flex-col gap-1">
                                        <p className="text-[10px] font-medium uppercase tracking-[0.22em] text-primary">
                                            {t('Connected')}
                                        </p>
                                        <p className="truncate text-sm text-foreground">
                                            {googleAccountEmail}
                                        </p>
                                    </div>
                                    <Form
                                        action={disconnect()}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="destructive-outline"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                {t('Disconnect')}
                                            </Button>
                                        )}
                                    </Form>
                                </div>
                            ) : (
                                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                    <p className="max-w-xl text-sm text-muted-foreground">
                                        {t('Not connected. Connect your Google account to sync bookings with Google Calendar.')}
                                    </p>
                                    <Form
                                        action={connect()}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                {t('Connect Google Calendar')}
                                            </Button>
                                        )}
                                    </Form>
                                </div>
                            )}
                        </CardPanel>
                    </Card>
                </section>
            </div>
        </SettingsLayout>
    );
}
