import { useState } from 'react';
import { router, useHttp } from '@inertiajs/react';
import { updateStatus, updateNotes } from '@/actions/App/Http/Controllers/Dashboard/BookingController';
import { useTrans } from '@/hooks/use-trans';
import {
    Sheet,
    SheetPopup,
    SheetHeader,
    SheetTitle,
    SheetDescription,
    SheetFooter,
} from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Separator } from '@/components/ui/separator';
import { BookingStatusBadge, BookingSourceBadge } from './booking-status-badge';
import type { DashboardBooking } from '@/types';

interface BookingDetailSheetProps {
    booking: DashboardBooking | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    timezone: string;
}

const statusTransitions: Record<string, { label: string; target: string; variant?: 'default' | 'destructive' | 'outline' }[]> = {
    pending: [
        { label: 'Confirm', target: 'confirmed' },
        { label: 'Cancel', target: 'cancelled', variant: 'destructive' },
    ],
    confirmed: [
        { label: 'Complete', target: 'completed' },
        { label: 'No Show', target: 'no_show', variant: 'outline' },
        { label: 'Cancel', target: 'cancelled', variant: 'destructive' },
    ],
};

function formatDateTime(isoString: string, timezone: string): string {
    return new Date(isoString).toLocaleString([], {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: timezone,
    });
}

export default function BookingDetailSheet({
    booking,
    open,
    onOpenChange,
    timezone,
}: BookingDetailSheetProps) {
    const { t } = useTrans();
    const [editingNotes, setEditingNotes] = useState(false);
    const [notesValue, setNotesValue] = useState('');
    const notesHttp = useHttp({ internal_notes: '' });

    if (!booking) return null;

    const actions = statusTransitions[booking.status] ?? [];

    function handleStatusChange(target: string) {
        router.patch(updateStatus.url(booking!.id), { status: target }, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    }

    function handleSaveNotes() {
        notesHttp.setData('internal_notes', notesValue);
        router.patch(updateNotes.url(booking!.id), { internal_notes: notesValue }, {
            preserveScroll: true,
            onSuccess: () => setEditingNotes(false),
        });
    }

    function startEditingNotes() {
        setNotesValue(booking?.internal_notes ?? '');
        setEditingNotes(true);
    }

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetPopup side="right" className="w-full sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>{t('Booking Details')}</SheetTitle>
                    <SheetDescription>
                        {booking.service.name} &middot;{' '}
                        {formatDateTime(booking.starts_at, timezone)}
                    </SheetDescription>
                </SheetHeader>

                <div className="space-y-4 p-4">
                    {/* Status & Source */}
                    <div className="flex items-center gap-2">
                        <BookingStatusBadge status={booking.status} />
                        <BookingSourceBadge source={booking.source} />
                    </div>

                    {/* Time */}
                    <div>
                        <h4 className="text-muted-foreground mb-1 text-xs font-medium uppercase">
                            {t('Time')}
                        </h4>
                        <p className="text-sm">
                            {formatDateTime(booking.starts_at, timezone)} &ndash;{' '}
                            {new Date(booking.ends_at).toLocaleTimeString([], {
                                hour: '2-digit',
                                minute: '2-digit',
                                timeZone: timezone,
                            })}
                        </p>
                        <p className="text-muted-foreground text-xs">
                            {booking.service.duration_minutes} {t('min')}
                        </p>
                    </div>

                    <Separator />

                    {/* Service & Collaborator */}
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <h4 className="text-muted-foreground mb-1 text-xs font-medium uppercase">
                                {t('Service')}
                            </h4>
                            <p className="text-sm">{booking.service.name}</p>
                            {booking.service.price !== null && (
                                <p className="text-muted-foreground text-xs">
                                    {booking.service.price === 0
                                        ? t('Free')
                                        : `CHF ${booking.service.price}`}
                                </p>
                            )}
                        </div>
                        <div>
                            <h4 className="text-muted-foreground mb-1 text-xs font-medium uppercase">
                                {t('Collaborator')}
                            </h4>
                            <p className="text-sm">{booking.collaborator.name}</p>
                        </div>
                    </div>

                    <Separator />

                    {/* Customer */}
                    <div>
                        <h4 className="text-muted-foreground mb-1 text-xs font-medium uppercase">
                            {t('Customer')}
                        </h4>
                        <p className="text-sm font-medium">{booking.customer.name}</p>
                        <p className="text-muted-foreground text-sm">{booking.customer.email}</p>
                        {booking.customer.phone && (
                            <p className="text-muted-foreground text-sm">{booking.customer.phone}</p>
                        )}
                    </div>

                    {/* Customer Notes */}
                    {booking.notes && (
                        <>
                            <Separator />
                            <div>
                                <h4 className="text-muted-foreground mb-1 text-xs font-medium uppercase">
                                    {t('Customer Notes')}
                                </h4>
                                <p className="text-sm whitespace-pre-wrap">{booking.notes}</p>
                            </div>
                        </>
                    )}

                    <Separator />

                    {/* Internal Notes */}
                    <div>
                        <div className="mb-1 flex items-center justify-between">
                            <h4 className="text-muted-foreground text-xs font-medium uppercase">
                                {t('Internal Notes')}
                            </h4>
                            {!editingNotes && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={startEditingNotes}
                                >
                                    {booking.internal_notes ? t('Edit') : t('Add')}
                                </Button>
                            )}
                        </div>
                        {editingNotes ? (
                            <div className="space-y-2">
                                <Textarea
                                    value={notesValue}
                                    onChange={(e) => setNotesValue(e.target.value)}
                                    rows={3}
                                    placeholder={t('Add internal notes...')}
                                />
                                <div className="flex gap-2">
                                    <Button size="sm" onClick={handleSaveNotes}>
                                        {t('Save')}
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setEditingNotes(false)}
                                    >
                                        {t('Cancel')}
                                    </Button>
                                </div>
                            </div>
                        ) : (
                            <p className="text-muted-foreground text-sm whitespace-pre-wrap">
                                {booking.internal_notes || t('No internal notes.')}
                            </p>
                        )}
                    </div>
                </div>

                {/* Status Actions */}
                {actions.length > 0 && (
                    <SheetFooter className="flex-row gap-2 px-4 pb-4">
                        {actions.map((action) => (
                            <Button
                                key={action.target}
                                variant={action.variant ?? 'default'}
                                size="sm"
                                onClick={() => handleStatusChange(action.target)}
                            >
                                {t(action.label)}
                            </Button>
                        ))}
                    </SheetFooter>
                )}
            </SheetPopup>
        </Sheet>
    );
}
