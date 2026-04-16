import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
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
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Display } from '@/components/ui/display';
import {
    SectionHeading,
    SectionTitle,
    SectionRule,
} from '@/components/ui/section-heading';
import { useTrans } from '@/hooks/use-trans';
import { router, useHttp } from '@inertiajs/react';
import { uploadAvatar as uploadAvatarAction } from '@/actions/App/Http/Controllers/Dashboard/Settings/StaffController';
import {
    updateSchedule,
    storeException,
    updateException,
    destroyException,
} from '@/actions/App/Http/Controllers/Dashboard/Settings/ProviderController';
import { WeekScheduleEditor } from '@/components/onboarding/week-schedule-editor';
import type { DaySchedule } from '@/components/onboarding/day-row';
import { ExceptionDialog, type ExceptionData } from '@/components/settings/exception-dialog';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import type { AvatarUploadResponse } from '@/types';
import { getInitials } from '@/lib/booking-format';
import { PlusIcon } from 'lucide-react';

interface StaffDetail {
    id: number;
    name: string;
    email: string;
    avatar_url: string | null;
    is_active: boolean;
    provider_id: number | null;
}

interface ServiceAssignment {
    id: number;
    name: string;
    assigned: boolean;
}

interface Props {
    staff: StaffDetail;
    schedule: DaySchedule[];
    exceptions: ExceptionData[];
    services: ServiceAssignment[];
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

export default function StaffShow({
    staff,
    schedule: initialSchedule,
    exceptions,
    services,
}: Props) {
    const { t } = useTrans();
    const [scheduleData, setScheduleData] = useState<DaySchedule[]>(initialSchedule);
    const [avatarUrl, setAvatarUrl] = useState<string | null>(staff.avatar_url);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingException, setEditingException] = useState<ExceptionData | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const scheduleHttp = useHttp({ rules: [] as DaySchedule[] });
    const avatarHttp = useHttp({ avatar: null as File | null });
    const pendingAvatarUpload = useRef(false);
    const pendingScheduleSubmit = useRef(false);

    function submitSchedule(e: FormEvent) {
        e.preventDefault();
        scheduleHttp.setData('rules', scheduleData);
        pendingScheduleSubmit.current = true;
    }

    useEffect(() => {
        if (pendingScheduleSubmit.current && scheduleHttp.data.rules.length > 0) {
            pendingScheduleSubmit.current = false;
            if (staff.provider_id !== null) {
                scheduleHttp.put(updateSchedule.url(staff.provider_id), {
                    onSuccess: () => router.reload(),
                });
            }
        }
    }, [scheduleHttp.data.rules]);

    function handleAvatarChange(e: React.ChangeEvent<HTMLInputElement>) {
        const file = e.target.files?.[0];
        if (!file) return;

        avatarHttp.setData('avatar', file);
        pendingAvatarUpload.current = true;
    }

    useEffect(() => {
        if (pendingAvatarUpload.current && avatarHttp.data.avatar) {
            pendingAvatarUpload.current = false;
            avatarHttp.post(uploadAvatarAction.url(staff.id), {
                onSuccess: (response: unknown) => {
                    const data = response as AvatarUploadResponse;
                    setAvatarUrl(data.url);
                },
            });
        }
    }, [avatarHttp.data.avatar]);

    function handleEditException(exception: ExceptionData) {
        setEditingException(exception);
        setDialogOpen(true);
    }

    function handleAddException() {
        setEditingException(null);
        setDialogOpen(true);
    }

    function handleDeleteException(id: number) {
        if (staff.provider_id === null) return;
        router.delete(destroyException.url({ provider: staff.provider_id, exception: id }));
    }

    const scheduleErrors = Object.values(scheduleHttp.errors ?? {}) as string[];

    return (
        <SettingsLayout
            title={staff.name}
            eyebrow={t('Settings · Team')}
            heading={staff.name}
            description={staff.email}
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
                                src={avatarUrl ?? undefined}
                                alt=""
                                className="rounded-2xl object-cover"
                            />
                            <AvatarFallback className="rounded-2xl bg-muted font-display text-base font-semibold text-muted-foreground">
                                {getInitials(staff.name)}
                            </AvatarFallback>
                        </Avatar>
                        <div className="flex flex-col items-start gap-1.5">
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                onChange={handleAvatarChange}
                                className="sr-only"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => fileInputRef.current?.click()}
                                loading={avatarHttp.processing}
                            >
                                {avatarUrl ? t('Replace photo') : t('Upload photo')}
                            </Button>
                            <p className="text-xs text-muted-foreground">
                                {t('JPG, PNG, or WebP · up to 2 MB')}
                            </p>
                            {!staff.is_active && (
                                <p className="text-[10px] font-medium uppercase tracking-[0.2em] text-muted-foreground">
                                    {t('Inactive')}
                                </p>
                            )}
                        </div>
                    </div>
                </section>

                {staff.provider_id !== null && (
                    <section className="flex flex-col gap-4">
                        <SectionHeading>
                            <SectionTitle>{t('Weekly schedule')}</SectionTitle>
                            <SectionRule />
                        </SectionHeading>

                        <form onSubmit={submitSchedule}>
                            <Card>
                                <CardPanel className="p-5 sm:p-6">
                                    <WeekScheduleEditor hours={scheduleData} onChange={setScheduleData} />
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
                )}

                {staff.provider_id !== null && (
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
                                {t('Absences, extra availability, and overrides for this provider go here.')}
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
                                                            <AlertDialogTitle>{t('Delete this exception?')}</AlertDialogTitle>
                                                            <AlertDialogDescription>
                                                                {t('The provider will revert to their default hours for this period.')}
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
                )}

                {services.length > 0 && (
                    <section className="flex flex-col gap-4">
                        <SectionHeading>
                            <SectionTitle>{t('Services performed')}</SectionTitle>
                            <SectionRule />
                        </SectionHeading>

                        <div className="flex flex-wrap gap-2">
                            {services.map((service) => (
                                <span
                                    key={service.id}
                                    className={
                                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs transition-colors ' +
                                        (service.assigned
                                            ? 'border-primary/30 bg-honey-soft/70 text-foreground'
                                            : 'border-border/70 bg-background text-muted-foreground')
                                    }
                                >
                                    <span
                                        aria-hidden="true"
                                        className={
                                            'size-1 rounded-full ' +
                                            (service.assigned ? 'bg-primary' : 'bg-muted-foreground/40')
                                        }
                                    />
                                    {service.name}
                                </span>
                            ))}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {t('Assignments are managed from the service settings.')}
                        </p>
                    </section>
                )}
            </div>

            {staff.provider_id !== null && (
                <ExceptionDialog
                    open={dialogOpen}
                    onOpenChange={setDialogOpen}
                    exception={editingException}
                    storeUrl={storeException.url(staff.provider_id)}
                    updateUrl={
                        editingException?.id
                            ? updateException.url({ provider: staff.provider_id, exception: editingException.id })
                            : undefined
                    }
                />
            )}
        </SettingsLayout>
    );
}
