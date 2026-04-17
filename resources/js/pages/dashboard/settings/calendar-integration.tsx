import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardPanel } from '@/components/ui/card';
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    SectionHeading,
    SectionTitle,
    SectionRule,
} from '@/components/ui/section-heading';
import { useTrans } from '@/hooks/use-trans';
import { Form, Link, usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';
import {
    connect,
    disconnect,
    syncNow,
    configure,
} from '@/actions/App/Http/Controllers/Dashboard/Settings/CalendarIntegrationController';

interface Props {
    connected: boolean;
    configured: boolean;
    googleAccountEmail: string | null;
    destinationCalendarId: string | null;
    conflictCalendarIds: string[];
    lastSyncedAt: string | null;
    pendingActionsCount: number;
    pinnedBusinessName: string | null;
    pinnedBusinessMismatch: boolean;
    error: string | null;
}

export default function CalendarIntegration(props: Props) {
    const { t } = useTrans();
    const flash = usePage<PageProps>().props.flash;

    const {
        connected,
        configured,
        googleAccountEmail,
        destinationCalendarId,
        conflictCalendarIds,
        lastSyncedAt,
        pendingActionsCount,
        pinnedBusinessName,
        pinnedBusinessMismatch,
        error,
    } = props;

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
                        <SectionTitle>
                            <span className="inline-flex items-center gap-2">
                                {t('Google Calendar')}
                                {pendingActionsCount > 0 && (
                                    <Badge variant="secondary">{pendingActionsCount}</Badge>
                                )}
                            </span>
                        </SectionTitle>
                        <SectionRule />
                    </SectionHeading>

                    <Card>
                        <CardPanel className="flex flex-col gap-5 p-5 sm:p-6">
                            {connected ? (
                                <>
                                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                        <div className="flex min-w-0 flex-col gap-1">
                                            <p className="text-[10px] font-medium uppercase tracking-[0.22em] text-primary">
                                                {configured ? t('Connected') : t('Connected — configuration pending')}
                                            </p>
                                            <p className="truncate text-sm text-foreground">
                                                {googleAccountEmail}
                                            </p>
                                            {configured && pinnedBusinessName && (
                                                <p className="text-xs text-muted-foreground">
                                                    {t('Syncing to :business', { business: pinnedBusinessName })}
                                                </p>
                                            )}
                                            {pinnedBusinessMismatch && pinnedBusinessName && (
                                                <p className="text-sm text-muted-foreground">
                                                    {t('This integration is pinned to :business.', { business: pinnedBusinessName })}
                                                </p>
                                            )}
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

                                    {configured ? (
                                        <div className="flex flex-col gap-3 border-t border-border/60 pt-5">
                                            <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                                <div className="flex flex-col gap-0.5">
                                                    <dt className="text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                                                        {t('Destination calendar')}
                                                    </dt>
                                                    <dd className="truncate text-sm text-foreground">
                                                        {destinationCalendarId}
                                                    </dd>
                                                </div>
                                                <div className="flex flex-col gap-0.5">
                                                    <dt className="text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                                                        {t('Conflict calendars')}
                                                    </dt>
                                                    <dd className="truncate text-sm text-foreground">
                                                        {conflictCalendarIds.length > 0
                                                            ? conflictCalendarIds.join(', ')
                                                            : t('None')}
                                                    </dd>
                                                </div>
                                                {lastSyncedAt && (
                                                    <div className="flex flex-col gap-0.5">
                                                        <dt className="text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                                                            {t('Last synced')}
                                                        </dt>
                                                        <dd className="text-sm text-foreground">
                                                            {new Date(lastSyncedAt).toLocaleString()}
                                                        </dd>
                                                    </div>
                                                )}
                                            </dl>

                                            <div className="flex flex-wrap gap-2 pt-1">
                                                <Link
                                                    href={configure.url()}
                                                    className="inline-flex"
                                                >
                                                    <Button type="button" size="sm" variant="secondary">
                                                        {t('Change settings')}
                                                    </Button>
                                                </Link>
                                                <Form
                                                    action={syncNow()}
                                                    options={{ preserveScroll: true }}
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant="secondary"
                                                            disabled={processing}
                                                        >
                                                            {t('Sync now')}
                                                        </Button>
                                                    )}
                                                </Form>
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="flex flex-col gap-3 border-t border-border/60 pt-5">
                                            <p className="text-sm text-muted-foreground">
                                                {t('Pick a destination calendar and any conflict calendars to finish the setup.')}
                                            </p>
                                            <Link href={configure.url()} className="inline-flex">
                                                <Button type="button" size="sm">
                                                    {t('Continue setup')}
                                                </Button>
                                            </Link>
                                        </div>
                                    )}
                                </>
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
