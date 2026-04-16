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
import { Field, FieldLabel } from '@/components/ui/field';
import { Display } from '@/components/ui/display';
import { BookingStatusBadge, BookingSourceBadge } from './booking-status-badge';
import { formatDateTimeMedium, formatTimeShort } from '@/lib/datetime-format';
import { formatPrice, formatDurationShort } from '@/lib/booking-format';
import type { DashboardBooking } from '@/types';

interface BookingDetailSheetProps {
    booking: DashboardBooking | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    timezone: string;
}

const statusTransitions: Record<
    string,
    { label: string; target: string; variant?: 'default' | 'destructive' | 'outline' }[]
> = {
    pending: [
        { label: 'Confirm', target: 'confirmed' },
        { label: 'Cancel', target: 'cancelled', variant: 'destructive' },
    ],
    confirmed: [
        { label: 'Mark complete', target: 'completed' },
        { label: 'No show', target: 'no_show', variant: 'outline' },
        { label: 'Cancel', target: 'cancelled', variant: 'destructive' },
    ],
};

function Meta({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1">
            <span className="text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                {label}
            </span>
            <div className="text-sm text-foreground">{children}</div>
        </div>
    );
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
    const startTime = formatTimeShort(booking.starts_at, timezone);
    const endTime = formatTimeShort(booking.ends_at, timezone);

    function handleStatusChange(target: string) {
        router.patch(
            updateStatus.url(booking!.id),
            { status: target },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    function handleSaveNotes() {
        notesHttp.setData('internal_notes', notesValue);
        router.patch(
            updateNotes.url(booking!.id),
            { internal_notes: notesValue },
            {
                preserveScroll: true,
                onSuccess: () => setEditingNotes(false),
            },
        );
    }

    function startEditingNotes() {
        setNotesValue(booking?.internal_notes ?? '');
        setEditingNotes(true);
    }

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetPopup side="right" className="w-full sm:max-w-md">
                <SheetHeader>
                    <p className="text-[10px] uppercase tracking-[0.22em] text-muted-foreground">
                        {t('Booking')}
                    </p>
                    <SheetTitle className="font-display">
                        {booking.customer.name}
                    </SheetTitle>
                    <SheetDescription>
                        {formatDateTimeMedium(booking.starts_at, timezone)}
                    </SheetDescription>
                    <div className="mt-1 flex items-center gap-2">
                        <BookingStatusBadge status={booking.status} />
                        <BookingSourceBadge source={booking.source} />
                    </div>
                </SheetHeader>

                <div className="flex flex-col gap-6 border-t border-border/60 px-6 py-6">
                    <Meta label={t('When')}>
                        <div className="flex items-baseline gap-2">
                            <Display className="font-display tabular-nums text-base font-semibold">
                                {startTime}
                                <span className="mx-1.5 text-muted-foreground/80">–</span>
                                {endTime}
                            </Display>
                            <span className="text-xs text-muted-foreground">
                                {formatDurationShort(booking.service.duration_minutes, t)}
                            </span>
                        </div>
                    </Meta>

                    <div className="grid grid-cols-2 gap-5">
                        <Meta label={t('Service')}>
                            <p className="font-medium">{booking.service.name}</p>
                            {booking.service.price !== null && (
                                <p className="text-xs text-muted-foreground">
                                    {formatPrice(booking.service.price, t)}
                                </p>
                            )}
                        </Meta>
                        <Meta label={t('With')}>
                            <p className="font-medium">{booking.provider.name}</p>
                        </Meta>
                    </div>

                    <Meta label={t('Customer')}>
                        <p className="font-medium">{booking.customer.name}</p>
                        <p className="text-xs text-muted-foreground">
                            {booking.customer.email}
                        </p>
                        {booking.customer.phone && (
                            <p className="text-xs text-muted-foreground">
                                {booking.customer.phone}
                            </p>
                        )}
                    </Meta>

                    {booking.notes && (
                        <Meta label={t('Customer note')}>
                            <p className="whitespace-pre-wrap text-sm leading-relaxed text-foreground">
                                {booking.notes}
                            </p>
                        </Meta>
                    )}

                    <div className="flex flex-col gap-2">
                        <div className="flex items-center justify-between">
                            <span className="text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                                {t('Internal note')}
                            </span>
                            {!editingNotes && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={startEditingNotes}
                                    className="text-muted-foreground"
                                >
                                    {booking.internal_notes ? t('Edit') : t('Add')}
                                </Button>
                            )}
                        </div>
                        {editingNotes ? (
                            <Field>
                                <FieldLabel className="sr-only">
                                    {t('Internal note')}
                                </FieldLabel>
                                <Textarea
                                    value={notesValue}
                                    onChange={(e) => setNotesValue(e.target.value)}
                                    rows={3}
                                    placeholder={t('Anything worth remembering for next time…')}
                                    autoFocus
                                />
                                <div className="flex gap-2">
                                    <Button size="sm" onClick={handleSaveNotes}>
                                        {t('Save note')}
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => setEditingNotes(false)}
                                    >
                                        {t('Cancel')}
                                    </Button>
                                </div>
                            </Field>
                        ) : (
                            <p className="whitespace-pre-wrap text-sm leading-relaxed text-muted-foreground">
                                {booking.internal_notes || t('No internal notes yet.')}
                            </p>
                        )}
                    </div>
                </div>

                {actions.length > 0 && (
                    <SheetFooter className="flex-row flex-wrap gap-2">
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
