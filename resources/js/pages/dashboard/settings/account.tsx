import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { Checkbox } from '@/components/ui/checkbox';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
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
    toggleProvider,
    updateSchedule,
    storeException,
    updateException,
    destroyException,
    updateServices,
} from '@/actions/App/Http/Controllers/Dashboard/Settings/AccountController';
import { WeekScheduleEditor } from '@/components/onboarding/week-schedule-editor';
import type { DaySchedule } from '@/components/onboarding/day-row';
import { ExceptionDialog, type ExceptionData } from '@/components/settings/exception-dialog';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import { getInitials } from '@/lib/booking-format';
import { PlusIcon } from 'lucide-react';

interface AccountUser {
    name: string;
    email: string;
    avatar_url: string | null;
}

interface ServiceAssignment {
    id: number;
    name: string;
    assigned: boolean;
}

interface Props {
    user: AccountUser;
    isProvider: boolean;
    hasProviderRow: boolean;
    schedule: DaySchedule[];
    exceptions: ExceptionData[];
    services: ServiceAssignment[];
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

export default function Account({
    user,
    isProvider,
    hasProviderRow,
    schedule: initialSchedule,
    exceptions,
    services,
    upcomingBookingsCount,
}: Props) {
    const { t } = useTrans();
    const [scheduleData, setScheduleData] = useState<DaySchedule[]>(initialSchedule);
    const [serviceIds, setServiceIds] = useState<number[]>(
        services.filter((s) => s.assigned).map((s) => s.id),
    );
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingException, setEditingException] = useState<ExceptionData | null>(null);
    const [toggleLoading, setToggleLoading] = useState(false);

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

    function handleToggle() {
        setToggleLoading(true);
        router.post(
            toggleProvider().url,
            {},
            { onFinish: () => setToggleLoading(false) },
        );
    }

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

    return (
        <SettingsLayout
            title={t('Account')}
            eyebrow={t('Settings · You')}
            heading={t('Your account')}
            description={t('Your identity in this business and whether you take bookings yourself.')}
        >
            <div className="flex flex-col gap-10">
                <section className="flex flex-col gap-4">
                    <SectionHeading>
                        <SectionTitle>{t('Profile')}</SectionTitle>
                        <SectionRule />
                    </SectionHeading>

                    <div className="flex items-center gap-5">
                        <Avatar className="size-16 shrink-0 rounded-2xl border border-border bg-muted">
                            <AvatarImage
                                src={user.avatar_url ?? undefined}
                                alt=""
                                className="rounded-2xl object-cover"
                            />
                            <AvatarFallback className="rounded-2xl bg-muted font-display text-base font-semibold text-muted-foreground">
                                {getInitials(user.name)}
                            </AvatarFallback>
                        </Avatar>
                        <div className="flex flex-col gap-0.5">
                            <Display className="text-base font-medium text-foreground">
                                {user.name}
                            </Display>
                            <p className="text-xs text-muted-foreground">{user.email}</p>
                        </div>
                    </div>
                </section>

                <section className="flex flex-col gap-4">
                    <SectionHeading>
                        <SectionTitle>{t('Bookable provider')}</SectionTitle>
                        <SectionRule />
                    </SectionHeading>

                    <Card>
                        <CardPanel className="p-5 sm:p-6">
                            <label className="flex cursor-pointer items-start justify-between gap-4">
                                <span className="flex flex-col gap-1">
                                    <span className="text-sm font-medium text-foreground">
                                        {t('I take bookings myself')}
                                    </span>
                                    <span className="text-xs leading-relaxed text-muted-foreground">
                                        {t('Turn this on to appear as a provider on your booking page. Your schedule, exceptions, and services are managed below.')}
                                    </span>
                                </span>
                                {isProvider ? (
                                    <AlertDialog>
                                        <AlertDialogTrigger render={<Switch checked={true} />} />
                                        <AlertDialogPopup>
                                            <AlertDialogHeader>
                                                <AlertDialogTitle>
                                                    {t('Stop taking bookings?')}
                                                </AlertDialogTitle>
                                                <AlertDialogDescription>
                                                    {upcomingBookingsCount > 0
                                                        ? t('You have :count upcoming booking(s). They stay on your calendar, but customers cannot book new slots with you until you turn this back on.', { count: upcomingBookingsCount })
                                                        : t('Customers will not be able to book you until you turn this back on. Your schedule and exceptions are preserved.')}
                                                </AlertDialogDescription>
                                            </AlertDialogHeader>
                                            <AlertDialogFooter>
                                                <AlertDialogClose render={<Button variant="outline" />}>
                                                    {t('Cancel')}
                                                </AlertDialogClose>
                                                <AlertDialogClose
                                                    render={<Button variant="destructive" />}
                                                    onClick={handleToggle}
                                                >
                                                    {t('Stop taking bookings')}
                                                </AlertDialogClose>
                                            </AlertDialogFooter>
                                        </AlertDialogPopup>
                                    </AlertDialog>
                                ) : (
                                    <Switch
                                        checked={false}
                                        disabled={toggleLoading}
                                        onCheckedChange={handleToggle}
                                    />
                                )}
                            </label>
                        </CardPanel>
                    </Card>
                </section>

                {isProvider && (
                    <>
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
                                    <SectionTitle>{t('Services I perform')}</SectionTitle>
                                    <SectionRule />
                                </SectionHeading>

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
                            </section>
                        )}
                    </>
                )}

                {!isProvider && hasProviderRow && (
                    <p className="text-xs text-muted-foreground">
                        {t('Your previous schedule and exceptions are preserved. Turn "Bookable provider" back on to use them.')}
                    </p>
                )}
            </div>

            {isProvider && (
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
            )}
        </SettingsLayout>
    );
}
