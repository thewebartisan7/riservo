import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Display } from '@/components/ui/display';
import {
    SectionHeading,
    SectionTitle,
    SectionRule,
} from '@/components/ui/section-heading';
import {
    AlertDialog,
    AlertDialogClose,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogPopup,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { useTrans } from '@/hooks/use-trans';
import { router, useHttp } from '@inertiajs/react';
import {
    updateSchedule,
    storeException,
    updateException,
    destroyException,
    updateServices,
} from '@/actions/App/Http/Controllers/Dashboard/Settings/AvailabilityController';
import { WeekScheduleEditor } from '@/components/onboarding/week-schedule-editor';
import type { DaySchedule } from '@/components/onboarding/day-row';
import { ExceptionDialog, type ExceptionData } from '@/components/settings/exception-dialog';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import { PlusIcon } from 'lucide-react';

interface ServiceAssignment {
    id: number;
    name: string;
    assigned: boolean;
}

interface Props {
    schedule: DaySchedule[];
    exceptions: ExceptionData[];
    services: ServiceAssignment[];
    canEditServices: boolean;
    upcomingBookingsCount: number;
}

function formatDateRange(start: string, end: string, t: (key: string) => string): string {
    if (!start) return '';
    const fmt = (d: string) =>
        new Date(d + 'T00:00:00').toLocaleDateString([], {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
    if (start === end) return fmt(start);
    return t(':start — :end').replace(':start', fmt(start)).replace(':end', fmt(end));
}

export default function Availability({
    schedule: initialSchedule,
    exceptions,
    services,
    canEditServices,
    upcomingBookingsCount,
}: Props) {
    const { t } = useTrans();
    const [scheduleData, setScheduleData] = useState<DaySchedule[]>(initialSchedule);
    const [serviceIds, setServiceIds] = useState<number[]>(
        services.filter((s) => s.assigned).map((s) => s.id),
    );
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingException, setEditingException] = useState<ExceptionData | null>(null);

    const scheduleHttp = useHttp({ rules: [] as DaySchedule[] });
    const servicesHttp = useHttp({ service_ids: [] as number[] });
    const pendingScheduleSubmit = useRef(false);
    const pendingServicesSubmit = useRef(false);

    function submitSchedule(e: FormEvent) {
        e.preventDefault();
        scheduleHttp.setData('rules', scheduleData);
        pendingScheduleSubmit.current = true;
    }

    useEffect(() => {
        if (pendingScheduleSubmit.current && scheduleHttp.data.rules.length > 0) {
            pendingScheduleSubmit.current = false;
            scheduleHttp.put(updateSchedule.url(), {
                onSuccess: () => router.reload(),
            });
        }
    }, [scheduleHttp.data.rules]);

    function submitServices(e: FormEvent) {
        e.preventDefault();
        servicesHttp.setData('service_ids', serviceIds);
        pendingServicesSubmit.current = true;
    }

    useEffect(() => {
        if (pendingServicesSubmit.current) {
            pendingServicesSubmit.current = false;
            servicesHttp.put(updateServices.url(), {
                onSuccess: () => router.reload(),
            });
        }
    }, [servicesHttp.data.service_ids]);

    function handleEditException(exception: ExceptionData) {
        setEditingException(exception);
        setDialogOpen(true);
    }

    function handleAddException() {
        setEditingException(null);
        setDialogOpen(true);
    }

    function handleDeleteException(id: number) {
        router.delete(destroyException.url({ exception: id }));
    }

    function toggleService(id: number, checked: boolean) {
        setServiceIds((prev) =>
            checked ? [...new Set([...prev, id])] : prev.filter((sid) => sid !== id),
        );
    }

    const scheduleErrors = Object.values(scheduleHttp.errors ?? {}) as string[];
    const assignedServiceNames = services.filter((s) => s.assigned).map((s) => s.name);

    return (
        <SettingsLayout
            title={t('Availability')}
            eyebrow={t('Settings · You')}
            heading={t('Your availability')}
            description={t('Your weekly schedule, exceptions, and the services you perform.')}
        >
            <div className="flex flex-col gap-10">
                {upcomingBookingsCount > 0 && (
                    <p className="text-xs text-muted-foreground">
                        {t('You have :count upcoming booking(s). Schedule changes do not affect existing bookings.', {
                            count: upcomingBookingsCount,
                        })}
                    </p>
                )}

                <section className="flex flex-col gap-4">
                    <SectionHeading>
                        <SectionTitle>{t('Weekly schedule')}</SectionTitle>
                        <SectionRule />
                    </SectionHeading>

                    <form onSubmit={submitSchedule}>
                        <Card>
                            <CardPanel className="p-5 sm:p-6">
                                <WeekScheduleEditor
                                    hours={scheduleData}
                                    onChange={setScheduleData}
                                />
                                {scheduleErrors.length > 0 && (
                                    <ul className="mt-5 flex flex-col gap-1 rounded-lg border border-primary/20 bg-honey-soft/60 px-4 py-3">
                                        {scheduleErrors.map((error, i) => (
                                            <li key={i} className="text-xs text-primary">
                                                {error}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardPanel>
                            <CardFooter className="justify-end border-t bg-muted/50 px-5 py-3 sm:px-6">
                                <Button type="submit" loading={scheduleHttp.processing}>
                                    {t('Save schedule')}
                                </Button>
                            </CardFooter>
                        </Card>
                    </form>
                </section>

                <section className="flex flex-col gap-4">
                    <SectionHeading>
                        <SectionTitle>{t('Exceptions')}</SectionTitle>
                        <SectionRule />
                        <Button size="sm" variant="outline" onClick={handleAddException}>
                            <PlusIcon />
                            {t('Add')}
                        </Button>
                    </SectionHeading>

                    {exceptions.length === 0 ? (
                        <p className="py-4 text-sm text-muted-foreground">
                            {t('Absences, extra availability, and overrides go here.')}
                        </p>
                    ) : (
                        <ul className="flex flex-col divide-y divide-border/70 border-y border-border/70">
                            {exceptions.map((exception) => {
                                const isBlock = exception.type === 'block';
                                return (
                                    <li
                                        key={exception.id}
                                        className="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-6"
                                    >
                                        <div className="flex min-w-0 items-start gap-4">
                                            <span
                                                aria-hidden="true"
                                                className={
                                                    'mt-1.5 size-1.5 shrink-0 rounded-full ' +
                                                    (isBlock ? 'bg-muted-foreground/50' : 'bg-primary')
                                                }
                                            />
                                            <div className="flex min-w-0 flex-col gap-1">
                                                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                                    <Display className="text-sm font-medium text-foreground">
                                                        {formatDateRange(exception.start_date, exception.end_date, t)}
                                                    </Display>
                                                    <span className="text-[10px] font-medium uppercase tracking-[0.2em] text-muted-foreground">
                                                        {isBlock ? t('Unavailable') : t('Extra')}
                                                    </span>
                                                    {exception.start_time && exception.end_time && (
                                                        <span className="font-display text-xs tabular-nums text-muted-foreground">
                                                            {exception.start_time} – {exception.end_time}
                                                        </span>
                                                    )}
                                                </div>
                                                {exception.reason && (
                                                    <p className="max-w-xl text-xs leading-relaxed text-muted-foreground">
                                                        {exception.reason}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-1 sm:ml-auto">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => handleEditException(exception)}
                                            >
                                                {t('Edit')}
                                            </Button>
                                            <AlertDialog>
                                                <AlertDialogTrigger
                                                    render={
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="text-muted-foreground hover:text-foreground"
                                                        />
                                                    }
                                                >
                                                    {t('Delete')}
                                                </AlertDialogTrigger>
                                                <AlertDialogPopup>
                                                    <AlertDialogHeader>
                                                        <AlertDialogTitle>
                                                            {t('Delete this exception?')}
                                                        </AlertDialogTitle>
                                                        <AlertDialogDescription>
                                                            {t('You will revert to your default hours for this period.')}
                                                        </AlertDialogDescription>
                                                    </AlertDialogHeader>
                                                    <AlertDialogFooter>
                                                        <AlertDialogClose render={<Button variant="outline" />}>
                                                            {t('Cancel')}
                                                        </AlertDialogClose>
                                                        <AlertDialogClose
                                                            render={<Button variant="destructive" />}
                                                            onClick={() => handleDeleteException(exception.id!)}
                                                        >
                                                            {t('Delete')}
                                                        </AlertDialogClose>
                                                    </AlertDialogFooter>
                                                </AlertDialogPopup>
                                            </AlertDialog>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>

                {services.length > 0 && (
                    <section className="flex flex-col gap-4">
                        <SectionHeading>
                            <SectionTitle>
                                {canEditServices ? t('Services I perform') : t('Services you perform')}
                            </SectionTitle>
                            <SectionRule />
                        </SectionHeading>

                        {canEditServices ? (
                            <form onSubmit={submitServices}>
                                <Card>
                                    <CardPanel className="flex flex-col gap-3 p-5 sm:p-6">
                                        {services.map((service) => (
                                            <label
                                                key={service.id}
                                                className="flex cursor-pointer items-center gap-3 text-sm text-foreground"
                                            >
                                                <Checkbox
                                                    checked={serviceIds.includes(service.id)}
                                                    onCheckedChange={(checked) =>
                                                        toggleService(service.id, checked === true)
                                                    }
                                                />
                                                <span>{service.name}</span>
                                            </label>
                                        ))}
                                    </CardPanel>
                                    <CardFooter className="justify-end border-t bg-muted/50 px-5 py-3 sm:px-6">
                                        <Button type="submit" loading={servicesHttp.processing}>
                                            {t('Save services')}
                                        </Button>
                                    </CardFooter>
                                </Card>
                            </form>
                        ) : (
                            <Card>
                                <CardPanel className="flex flex-col gap-3 p-5 sm:p-6">
                                    {assignedServiceNames.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            {t('You are not assigned to any services yet. Ask an admin to add you.')}
                                        </p>
                                    ) : (
                                        <ul className="flex flex-wrap gap-2">
                                            {assignedServiceNames.map((name) => (
                                                <li
                                                    key={name}
                                                    className="rounded-full border border-border/70 bg-muted/40 px-3 py-1 text-xs text-foreground"
                                                >
                                                    {name}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    <p className="text-xs text-muted-foreground">
                                        {t('Ask an admin to update which services you perform.')}
                                    </p>
                                </CardPanel>
                            </Card>
                        )}
                    </section>
                )}
            </div>

            <ExceptionDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                exception={editingException}
                storeUrl={storeException.url()}
                updateUrl={
                    editingException?.id
                        ? updateException.url({ exception: editingException.id })
                        : undefined
                }
            />
        </SettingsLayout>
    );
}
