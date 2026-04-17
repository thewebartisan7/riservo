import { Form } from '@inertiajs/react';
import { Card, CardPanel } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    SectionHeading,
    SectionTitle,
    SectionRule,
} from '@/components/ui/section-heading';
import { useTrans } from '@/hooks/use-trans';
import { resolve as resolveAction } from '@/actions/App/Http/Controllers/Dashboard/CalendarPendingActionController';
import type { CalendarPendingAction } from '@/types';
import { CalendarDaysIcon } from 'lucide-react';

interface Props {
    actions: CalendarPendingAction[];
    timezone: string;
}

export function CalendarPendingActionsSection({ actions, timezone }: Props) {
    const { t } = useTrans();

    if (actions.length === 0) {
        return null;
    }

    return (
        <section className="flex flex-col gap-4">
            <SectionHeading>
                <SectionTitle>
                    <span className="inline-flex items-center gap-2">
                        <CalendarDaysIcon aria-hidden="true" className="size-4" />
                        {t('Calendar sync — pending actions')}
                        <Badge variant="secondary">{actions.length}</Badge>
                    </span>
                </SectionTitle>
                <SectionRule />
            </SectionHeading>

            <div className="flex flex-col gap-3">
                {actions.map((action) => (
                    <Card key={action.id}>
                        <CardPanel className="flex flex-col gap-4 p-5 sm:p-6">
                            {action.type === 'riservo_event_deleted_in_google' ? (
                                <RiservoDeletedRow action={action} timezone={timezone} />
                            ) : (
                                <ConflictRow action={action} timezone={timezone} />
                            )}
                        </CardPanel>
                    </Card>
                ))}
            </div>
        </section>
    );
}

function RiservoDeletedRow({ action, timezone }: { action: CalendarPendingAction; timezone: string }) {
    const { t } = useTrans();
    const booking = action.booking;

    return (
        <>
            <div className="flex flex-col gap-1">
                <p className="text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                    {t('Google event deleted')}
                </p>
                <p className="text-sm text-foreground">
                    {t('A booking you pushed to Google Calendar was deleted there, but the riservo booking is still active.')}
                </p>
                {booking && (
                    <p className="text-sm text-muted-foreground">
                        {booking.customer_name ?? t('—')} · {booking.service_name ?? t('External event')}
                        {' · '}
                        {new Date(booking.starts_at).toLocaleString([], { timeZone: timezone })}
                    </p>
                )}
            </div>
            <div className="flex flex-wrap gap-2">
                <Form action={resolveAction(action.id)}>
                    {({ processing }) => (
                        <>
                            <input type="hidden" name="choice" value="cancel_and_notify" />
                            <Button type="submit" size="sm" variant="destructive-outline" disabled={processing}>
                                {t('Cancel booking and notify customer')}
                            </Button>
                        </>
                    )}
                </Form>
                <Form action={resolveAction(action.id)}>
                    {({ processing }) => (
                        <>
                            <input type="hidden" name="choice" value="keep_and_dismiss" />
                            <Button type="submit" size="sm" variant="secondary" disabled={processing}>
                                {t('Keep booking and dismiss')}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

function ConflictRow({ action, timezone }: { action: CalendarPendingAction; timezone: string }) {
    const { t } = useTrans();
    const summary = (action.payload.external_summary as string | null | undefined) ?? t('External event');
    const start = action.payload.external_start as string | undefined;

    return (
        <>
            <div className="flex flex-col gap-1">
                <p className="text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                    {t('Calendar conflict')}
                </p>
                <p className="text-sm text-foreground">
                    {summary}
                    {start && <> · {new Date(start).toLocaleString([], { timeZone: timezone })}</>}
                </p>
                <p className="text-sm text-muted-foreground">
                    {t('This external event overlaps an existing riservo booking. Decide how to resolve it.')}
                </p>
            </div>
            <div className="flex flex-wrap gap-2">
                <Form action={resolveAction(action.id)}>
                    {({ processing }) => (
                        <>
                            <input type="hidden" name="choice" value="keep_riservo_ignore_external" />
                            <Button type="submit" size="sm" variant="secondary" disabled={processing}>
                                {t('Keep riservo booking (ignore external)')}
                            </Button>
                        </>
                    )}
                </Form>
                <Form action={resolveAction(action.id)}>
                    {({ processing }) => (
                        <>
                            <input type="hidden" name="choice" value="cancel_external" />
                            <Button type="submit" size="sm" variant="secondary" disabled={processing}>
                                {t('Cancel external event')}
                            </Button>
                        </>
                    )}
                </Form>
                <Form action={resolveAction(action.id)}>
                    {({ processing }) => (
                        <>
                            <input type="hidden" name="choice" value="cancel_riservo_booking" />
                            <Button type="submit" size="sm" variant="destructive-outline" disabled={processing}>
                                {t('Cancel riservo booking')}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
