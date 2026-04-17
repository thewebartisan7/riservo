import { useState } from 'react';
import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardPanel } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Field, FieldLabel, FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    SectionHeading,
    SectionTitle,
    SectionRule,
} from '@/components/ui/section-heading';
import {
    Select,
    SelectTrigger,
    SelectValue,
    SelectPopup,
    SelectItem,
} from '@/components/ui/select';
import { useTrans } from '@/hooks/use-trans';
import { Form } from '@inertiajs/react';
import { saveConfiguration } from '@/actions/App/Http/Controllers/Dashboard/Settings/CalendarIntegrationController';

interface CalendarOption {
    id: string;
    summary: string;
    primary: boolean;
    accessRole: string;
}

interface Props {
    calendars: CalendarOption[];
    destinationCalendarId: string | null;
    conflictCalendarIds: string[];
    businessName: string | null;
}

export default function CalendarIntegrationConfigure({
    calendars,
    destinationCalendarId,
    conflictCalendarIds,
    businessName,
}: Props) {
    const { t } = useTrans();

    const [destination, setDestination] = useState<string>(destinationCalendarId ?? '');
    const [createNew, setCreateNew] = useState<boolean>(false);
    const [newCalendarName, setNewCalendarName] = useState<string>('');
    const [conflicts, setConflicts] = useState<string[]>(conflictCalendarIds);

    // Only writable calendars make sense as a destination.
    const writableCalendars = calendars.filter(
        (c) => c.accessRole === 'owner' || c.accessRole === 'writer',
    );

    function toggleConflict(id: string) {
        setConflicts((prev) =>
            prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
        );
    }

    return (
        <SettingsLayout
            title={t('Configure calendar sync')}
            eyebrow={t('Settings · You')}
            heading={t('Configure calendar sync')}
            description={t('Choose where riservo writes bookings and which calendars to watch for external events.')}
        >
            <Form
                action={saveConfiguration()}
                options={{ preserveScroll: true }}
                transform={(data) => ({
                    ...data,
                    destination_calendar_id: createNew ? null : destination,
                    conflict_calendar_ids: conflicts,
                    create_new_calendar_name: createNew ? newCalendarName : null,
                })}
            >
                {({ processing, errors }) => (
                    <div className="flex flex-col gap-8">
                        {businessName && (
                            <p className="text-sm text-muted-foreground">
                                {t('Syncing to :business', { business: businessName })}
                            </p>
                        )}

                        <section className="flex flex-col gap-4">
                            <SectionHeading>
                                <SectionTitle>{t('Destination calendar')}</SectionTitle>
                                <SectionRule />
                            </SectionHeading>
                            <Card>
                                <CardPanel className="flex flex-col gap-4 p-5 sm:p-6">
                                    <p className="text-sm text-muted-foreground">
                                        {t('Riservo will create Google Calendar events here for every booking.')}
                                    </p>

                                    {!createNew ? (
                                        <Field>
                                            <FieldLabel>{t('Add riservo bookings to')}</FieldLabel>
                                            <Select
                                                value={destination}
                                                onValueChange={(value) => setDestination(value ?? '')}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder={t('Select a calendar')} />
                                                </SelectTrigger>
                                                <SelectPopup>
                                                    {writableCalendars.map((c) => (
                                                        <SelectItem key={c.id} value={c.id}>
                                                            {c.summary}
                                                            {c.primary && ` (${t('primary')})`}
                                                        </SelectItem>
                                                    ))}
                                                </SelectPopup>
                                            </Select>
                                            {errors.destination_calendar_id && (
                                                <FieldError match>{String(errors.destination_calendar_id)}</FieldError>
                                            )}
                                        </Field>
                                    ) : (
                                        <Field>
                                            <FieldLabel>{t('New calendar name')}</FieldLabel>
                                            <Input
                                                value={newCalendarName}
                                                onChange={(e) => setNewCalendarName(e.target.value)}
                                                placeholder={t('e.g. Riservo bookings')}
                                            />
                                            {errors.create_new_calendar_name && (
                                                <FieldError match>{String(errors.create_new_calendar_name)}</FieldError>
                                            )}
                                        </Field>
                                    )}

                                    <Label className="flex items-center gap-2.5 text-sm">
                                        <Checkbox
                                            checked={createNew}
                                            onCheckedChange={(v) => setCreateNew(Boolean(v))}
                                        />
                                        <span>{t('Create a dedicated new calendar instead')}</span>
                                    </Label>
                                </CardPanel>
                            </Card>
                        </section>

                        <section className="flex flex-col gap-4">
                            <SectionHeading>
                                <SectionTitle>{t('Conflict calendars')}</SectionTitle>
                                <SectionRule />
                            </SectionHeading>
                            <Card>
                                <CardPanel className="flex flex-col gap-4 p-5 sm:p-6">
                                    <p className="text-sm text-muted-foreground">
                                        {t('Events on these calendars appear as external bookings in riservo and block availability.')}
                                    </p>
                                    <ul className="flex flex-col gap-2">
                                        {calendars.map((c) => (
                                            <li key={c.id}>
                                                <Label className="flex items-center gap-2.5 rounded-md border border-border/60 bg-background px-3 py-2 text-sm transition-colors hover:border-border">
                                                    <Checkbox
                                                        checked={conflicts.includes(c.id)}
                                                        onCheckedChange={() => toggleConflict(c.id)}
                                                    />
                                                    <span className="flex-1 truncate">
                                                        {c.summary}
                                                        {c.primary && ` (${t('primary')})`}
                                                    </span>
                                                </Label>
                                            </li>
                                        ))}
                                    </ul>
                                </CardPanel>
                            </Card>
                        </section>

                        <div className="flex justify-end gap-3">
                            <Button type="submit" disabled={processing}>
                                {t('Save and start sync')}
                            </Button>
                        </div>
                    </div>
                )}
            </Form>
        </SettingsLayout>
    );
}
