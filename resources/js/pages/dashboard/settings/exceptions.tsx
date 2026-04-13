import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel } from '@/components/ui/card';
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
import { useTrans } from '@/hooks/use-trans';
import { router } from '@inertiajs/react';
import {
    store,
    update as updateException,
    destroy,
} from '@/actions/App/Http/Controllers/Dashboard/Settings/BusinessExceptionController';
import { ExceptionDialog, type ExceptionData } from '@/components/settings/exception-dialog';
import { useState } from 'react';

interface Props {
    exceptions: ExceptionData[];
}

export default function Exceptions({ exceptions }: Props) {
    const { t } = useTrans();
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingException, setEditingException] = useState<ExceptionData | null>(null);

    function handleEdit(exception: ExceptionData) {
        setEditingException(exception);
        setDialogOpen(true);
    }

    function handleAdd() {
        setEditingException(null);
        setDialogOpen(true);
    }

    return (
        <SettingsLayout title={t('Business Exceptions')}>
            <Card>
                <CardHeader className="flex flex-row items-center justify-between">
                    <div>
                        <CardTitle>{t('Business Exceptions')}</CardTitle>
                        <CardDescription>{t('Closures, holidays, and special hours that affect all collaborators')}</CardDescription>
                    </div>
                    <Button onClick={handleAdd}>{t('Add Exception')}</Button>
                </CardHeader>
                <CardPanel>
                    {exceptions.length === 0 ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">{t('No exceptions defined.')}</p>
                    ) : (
                        <div className="divide-y">
                            {exceptions.map((exception) => (
                                <div key={exception.id} className="flex items-center justify-between py-3">
                                    <div className="flex flex-col gap-1">
                                        <div className="flex items-center gap-2">
                                            <Badge variant={exception.type === 'block' ? 'destructive' : 'default'}>
                                                {exception.type === 'block' ? t('Closed') : t('Open')}
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
                                        <Button variant="ghost" size="sm" onClick={() => handleEdit(exception)}>{t('Edit')}</Button>
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
                                                        onClick={() => router.delete(destroy.url(exception.id!))}
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

            <ExceptionDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                exception={editingException}
                storeUrl={store.url()}
                updateUrl={editingException?.id ? updateException.url(editingException.id) : undefined}
            />
        </SettingsLayout>
    );
}
