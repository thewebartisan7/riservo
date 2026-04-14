import SettingsLayout from '@/layouts/settings-layout';
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
import { Frame, FrameHeader } from '@/components/ui/frame';
import { Display } from '@/components/ui/display';
import { useTrans } from '@/hooks/use-trans';
import { router } from '@inertiajs/react';
import {
    store,
    update as updateException,
    destroy,
} from '@/actions/App/Http/Controllers/Dashboard/Settings/BusinessExceptionController';
import { ExceptionDialog, type ExceptionData } from '@/components/settings/exception-dialog';
import { useState } from 'react';
import { PlusIcon } from 'lucide-react';

interface Props {
    exceptions: ExceptionData[];
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
        <SettingsLayout
            title={t('Business Exceptions')}
            eyebrow={t('Settings · Schedule')}
            heading={t('Business exceptions')}
            description={t(
                'Holidays, closures, and special openings. These override your default working hours for every collaborator.',
            )}
            actions={
                <Button onClick={handleAdd}>
                    <PlusIcon />
                    {t('New exception')}
                </Button>
            }
        >
            {exceptions.length === 0 ? (
                <Frame>
                    <FrameHeader className="items-center gap-2 py-12 text-center">
                        <p className="text-sm text-foreground">{t('No exceptions yet.')}</p>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            {t(
                                'Closures and special hours go here. Start with your next holiday, a vacation week, or a special evening.',
                            )}
                        </p>
                    </FrameHeader>
                </Frame>
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
                                                {isBlock ? t('Closed') : t('Open')}
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
                                    <Button variant="ghost" size="sm" onClick={() => handleEdit(exception)}>
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
                                                    {t('Bookings already on the calendar will stay as they are. New bookings will follow your default hours again.')}
                                                </AlertDialogDescription>
                                            </AlertDialogHeader>
                                            <AlertDialogFooter>
                                                <AlertDialogClose render={<Button variant="outline" />}>
                                                    {t('Cancel')}
                                                </AlertDialogClose>
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
                            </li>
                        );
                    })}
                </ul>
            )}

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
