import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
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
import { InputError } from '@/components/input-error';
import { useTrans } from '@/hooks/use-trans';
import { router, useHttp } from '@inertiajs/react';
import {
    updateSchedule,
    storeException,
    updateException,
    destroyException,
    uploadAvatar as uploadAvatarAction,
} from '@/actions/App/Http/Controllers/Dashboard/Settings/CollaboratorController';
import { WeekScheduleEditor } from '@/components/onboarding/week-schedule-editor';
import type { DaySchedule } from '@/components/onboarding/day-row';
import { ExceptionDialog, type ExceptionData } from '@/components/settings/exception-dialog';
import { type FormEvent, useEffect, useRef, useState } from 'react';

interface CollaboratorDetail {
    id: number;
    name: string;
    email: string;
    avatar_url: string | null;
    is_active: boolean;
}

interface ServiceAssignment {
    id: number;
    name: string;
    assigned: boolean;
}

interface Props {
    collaborator: CollaboratorDetail;
    schedule: DaySchedule[];
    exceptions: ExceptionData[];
    services: ServiceAssignment[];
}

function getInitials(name: string): string {
    return name.split(' ').map((w) => w[0]).join('').toUpperCase().slice(0, 2);
}

export default function CollaboratorShow({ collaborator, schedule: initialSchedule, exceptions, services }: Props) {
    const { t } = useTrans();
    const [scheduleData, setScheduleData] = useState<DaySchedule[]>(initialSchedule);
    const [avatarUrl, setAvatarUrl] = useState<string | null>(collaborator.avatar_url);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingException, setEditingException] = useState<ExceptionData | null>(null);

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
            scheduleHttp.put(updateSchedule.url(collaborator.id), {
                onSuccess: () => router.reload(),
            });
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
            avatarHttp.post(uploadAvatarAction.url(collaborator.id), {
                onSuccess: (response: unknown) => {
                    const data = response as { url: string };
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
        router.delete(destroyException.url({ user: collaborator.id, exception: id }));
    }

    return (
        <SettingsLayout title={collaborator.name}>
            <div className="flex flex-col gap-6">
                {/* Profile card */}
                <Card>
                    <CardHeader>
                        <CardTitle>{collaborator.name}</CardTitle>
                        <CardDescription>{collaborator.email}</CardDescription>
                    </CardHeader>
                    <CardPanel>
                        <div className="flex items-center gap-4">
                            <Avatar className="size-16">
                                <AvatarImage src={avatarUrl ?? undefined} alt={collaborator.name} />
                                <AvatarFallback className="text-lg">{getInitials(collaborator.name)}</AvatarFallback>
                            </Avatar>
                            <div>
                                <input
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    onChange={handleAvatarChange}
                                    className="text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                                />
                                <p className="mt-1 text-xs text-muted-foreground">{t('JPG, PNG or WebP. Max 2MB.')}</p>
                                {avatarHttp.processing && <p className="text-xs text-muted-foreground">{t('Uploading...')}</p>}
                            </div>
                        </div>
                        {!collaborator.is_active && (
                            <Badge variant="secondary" className="mt-3">{t('Inactive')}</Badge>
                        )}
                    </CardPanel>
                </Card>

                {/* Schedule */}
                <Card>
                    <CardHeader>
                        <CardTitle>{t('Weekly Schedule')}</CardTitle>
                        <CardDescription>{t("Set this collaborator's working hours")}</CardDescription>
                    </CardHeader>
                    <form onSubmit={submitSchedule}>
                        <CardPanel>
                            <WeekScheduleEditor hours={scheduleData} onChange={setScheduleData} />
                            {scheduleHttp.hasErrors && (
                                <div className="mt-4">
                                    {Object.values(scheduleHttp.errors).map((error: string, i: number) => (
                                        <InputError key={i} message={error} />
                                    ))}
                                </div>
                            )}
                        </CardPanel>
                        <CardFooter className="flex justify-end">
                            <Button type="submit" disabled={scheduleHttp.processing}>{t('Save Schedule')}</Button>
                        </CardFooter>
                    </form>
                </Card>

                {/* Exceptions */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <div>
                            <CardTitle>{t('Exceptions')}</CardTitle>
                            <CardDescription>{t('Absences, extra availability, and other schedule overrides')}</CardDescription>
                        </div>
                        <Button onClick={handleAddException}>{t('Add Exception')}</Button>
                    </CardHeader>
                    <CardPanel>
                        {exceptions.length === 0 ? (
                            <p className="py-4 text-center text-sm text-muted-foreground">{t('No exceptions defined.')}</p>
                        ) : (
                            <div className="divide-y">
                                {exceptions.map((exception) => (
                                    <div key={exception.id} className="flex items-center justify-between py-3">
                                        <div className="flex flex-col gap-1">
                                            <div className="flex items-center gap-2">
                                                <Badge variant={exception.type === 'block' ? 'destructive' : 'default'}>
                                                    {exception.type === 'block' ? t('Unavailable') : t('Extra')}
                                                </Badge>
                                                <span className="text-sm font-medium">
                                                    {exception.start_date === exception.end_date
                                                        ? exception.start_date
                                                        : `${exception.start_date} — ${exception.end_date}`}
                                                </span>
                                                {exception.start_time && (
                                                    <span className="text-sm text-muted-foreground">
                                                        {exception.start_time} – {exception.end_time}
                                                    </span>
                                                )}
                                            </div>
                                            {exception.reason && (
                                                <span className="text-xs text-muted-foreground">{exception.reason}</span>
                                            )}
                                        </div>
                                        <div className="flex gap-2">
                                            <Button variant="ghost" size="sm" onClick={() => handleEditException(exception)}>{t('Edit')}</Button>
                                            <AlertDialog>
                                                <AlertDialogTrigger render={<Button variant="ghost" size="sm" />}>
                                                    {t('Delete')}
                                                </AlertDialogTrigger>
                                                <AlertDialogPopup>
                                                    <AlertDialogHeader>
                                                        <AlertDialogTitle>{t('Delete Exception')}</AlertDialogTitle>
                                                        <AlertDialogDescription>
                                                            {t('Are you sure you want to delete this exception? This action cannot be undone.')}
                                                        </AlertDialogDescription>
                                                    </AlertDialogHeader>
                                                    <AlertDialogFooter>
                                                        <AlertDialogClose render={<Button variant="outline" />}>{t('Cancel')}</AlertDialogClose>
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
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardPanel>
                </Card>

                {/* Services */}
                {services.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Assigned Services')}</CardTitle>
                            <CardDescription>{t('Services this collaborator can perform. Manage assignments from the service settings.')}</CardDescription>
                        </CardHeader>
                        <CardPanel>
                            <div className="flex flex-wrap gap-2">
                                {services.map((service) => (
                                    <Badge key={service.id} variant={service.assigned ? 'default' : 'outline'}>
                                        {service.name}
                                    </Badge>
                                ))}
                            </div>
                        </CardPanel>
                    </Card>
                )}
            </div>

            <ExceptionDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                exception={editingException}
                storeUrl={storeException.url(collaborator.id)}
                updateUrl={editingException?.id ? updateException.url({ user: collaborator.id, exception: editingException.id }) : undefined}
            />
        </SettingsLayout>
    );
}
