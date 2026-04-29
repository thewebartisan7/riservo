import { useState } from 'react';
import { router, useHttp } from '@inertiajs/react';
import { updateStatus, updateNotes } from '@/actions/App/Http/Controllers/Dashboard/BookingController';
import { resolve as resolvePaymentPendingAction } from '@/actions/App/Http/Controllers/Dashboard/PaymentPendingActionController';
import {
    payment as stripePaymentLink,
    refund as stripeRefundLink,
    dispute as stripeDisputeLink,
} from '@/actions/App/Http/Controllers/Dashboard/StripeDashboardLinkController';
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
import { PaymentStatusBadge } from './payment-status-badge';
import RefundDialog from './refund-dialog';
import { formatDateTimeMedium, formatTimeShort } from '@/lib/datetime-format';
import { formatPrice, formatDurationShort } from '@/lib/booking-format';
import { formatMoney } from '@/lib/format-money';
import { CalendarDaysIcon, ExternalLinkIcon } from 'lucide-react';
import type { DashboardBooking, DashboardBookingRefund } from '@/types';

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
    const [refundDialogOpen, setRefundDialogOpen] = useState(false);
    const notesHttp = useHttp({ internal_notes: '' });

    if (!booking) return null;

    // External (Google Calendar) bookings are managed in Google; the dashboard
    // sheet stays read-only for them.
    const actions = booking.external ? [] : (statusTransitions[booking.status] ?? []);
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

    // PAYMENTS Session 2b — admin-only payment surfaces. Staff don't see
    // the panel or the banner; the server payload still carries them but
    // the UI gates rendering on admin context via the Sheet's caller.
    //
    // Session 3 Codex Round 1 P2: dispute PAs ride a dedicated key
    // (`dispute_payment_action`) so the section below renders even when
    // the higher-priority `pending_payment_action` is a refund-failed or
    // cancelled-after-payment row.
    const payment = booking?.payment;
    const pendingPaymentAction = booking?.pending_payment_action ?? null;
    const disputePaymentAction = booking?.dispute_payment_action ?? null;
    const refunds = booking?.refunds ?? [];
    const remainingRefundableCents = payment?.remaining_refundable_cents ?? 0;
    const canIssueRefund =
        payment !== null
        && payment !== undefined
        && (payment.status === 'paid' || payment.status === 'partially_refunded')
        && remainingRefundableCents > 0;
    const isDisputePa = disputePaymentAction !== null;
    // D-184 / G-003 partial: use Intl.NumberFormat via the shared helper.
    const paidAmountFormatted =
        payment && payment.paid_amount_cents !== null && payment.currency !== null
            ? formatMoney(payment.paid_amount_cents, payment.currency)
            : null;
    const remainingFormatted =
        payment && payment.currency !== null && remainingRefundableCents > 0
            ? formatMoney(remainingRefundableCents, payment.currency)
            : null;
    // D-184 / G-001: deeplinks go through server-side redirect endpoints.
    // Raw `stripe_*_id` values no longer ride Inertia props; the React side
    // calls the Wayfinder helper, the controller resolves the IDs server-
    // side and 302s to Stripe.
    const stripeDashboardDeepLink = payment?.has_stripe_payment_link
        ? stripePaymentLink.url(booking.id)
        : null;
    const disputeDeepLink = disputePaymentAction?.has_dispute_link
        ? stripeDisputeLink.url(booking.id)
        : null;
    const disputeReason = (() => {
        if (!disputePaymentAction) return null;
        const payload = disputePaymentAction.payload as Record<string, unknown>;
        return typeof payload.reason === 'string' ? payload.reason : null;
    })();

    function handleResolvePendingAction() {
        if (!pendingPaymentAction) return;
        router.patch(
            resolvePaymentPendingAction.url(pendingPaymentAction.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    function handleDismissDisputePa() {
        if (!disputePaymentAction) return;
        router.patch(
            resolvePaymentPendingAction.url(disputePaymentAction.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetPopup side="right" className="w-full sm:max-w-md">
                <SheetHeader>
                    <p className="text-[10px] uppercase tracking-[0.22em] text-muted-foreground">
                        {booking.external ? t('External event') : t('Booking')}
                    </p>
                    <SheetTitle className="font-display">
                        {booking.external ? (
                            <span className="inline-flex items-center gap-2">
                                <CalendarDaysIcon aria-hidden="true" className="size-4 text-muted-foreground" />
                                {booking.external_title ?? t('External event')}
                            </span>
                        ) : (
                            booking.customer?.name ?? ''
                        )}
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
                    {pendingPaymentAction && (
                        <div
                            role="alert"
                            className="flex flex-col gap-2 rounded-md border border-warning/40 bg-warning/8 px-4 py-3 text-sm text-warning-foreground"
                        >
                            <p className="font-medium">
                                {pendingPaymentAction.type === 'payment.cancelled_after_payment'
                                    ? t('A cancelled booking was paid after the fact. A refund has been dispatched — reach out to the customer to confirm.')
                                    : t('The automatic refund for this booking could not be processed. Resolve in Stripe, then mark as resolved here.')}
                            </p>
                            <div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={handleResolvePendingAction}
                                >
                                    {t('Mark as resolved')}
                                </Button>
                            </div>
                        </div>
                    )}

                    <Meta label={t('When')}>
                        <div className="flex items-baseline gap-2">
                            <Display className="font-display tabular-nums text-base font-semibold">
                                {startTime}
                                <span className="mx-1.5 text-muted-foreground/80">–</span>
                                {endTime}
                            </Display>
                            {booking.service && (
                                <span className="text-xs text-muted-foreground">
                                    {formatDurationShort(booking.service.duration_minutes, t)}
                                </span>
                            )}
                        </div>
                    </Meta>

                    {!booking.external && (
                        <div className="grid grid-cols-2 gap-5">
                            <Meta label={t('Service')}>
                                <p className="font-medium">{booking.service?.name ?? '—'}</p>
                                {booking.service?.price !== null && booking.service?.price !== undefined && (
                                    <p className="text-xs text-muted-foreground">
                                        {formatPrice(booking.service.price, t)}
                                    </p>
                                )}
                            </Meta>
                            <Meta label={t('With')}>
                                <p className="font-medium">
                                    {booking.provider.is_active
                                        ? booking.provider.name
                                        : t(':name (deactivated)', { name: booking.provider.name })}
                                </p>
                            </Meta>
                        </div>
                    )}

                    {booking.external && booking.external_html_link && (
                        <Meta label={t('Source')}>
                            <a
                                href={booking.external_html_link}
                                target="_blank"
                                rel="noreferrer noopener"
                                className="inline-flex items-center gap-1.5 text-sm font-medium text-foreground underline-offset-4 hover:underline"
                            >
                                {t('Open in Google Calendar')}
                                <ExternalLinkIcon aria-hidden="true" className="size-3.5" />
                            </a>
                        </Meta>
                    )}

                    {booking.customer && !booking.external && (
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
                    )}

                    {payment && payment.status !== 'not_applicable' && (
                        <Meta label={t('Payment')}>
                            <div className="flex items-center gap-2">
                                <PaymentStatusBadge status={payment.status} />
                                {paidAmountFormatted && (
                                    <span className="text-sm font-medium tabular-nums">
                                        {paidAmountFormatted}
                                    </span>
                                )}
                            </div>
                            {payment.status === 'awaiting_payment' && (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {t('Customer was sent to Stripe Checkout; booking confirms when payment settles.')}
                                </p>
                            )}
                            {payment.status === 'unpaid' && (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {t('Customer attempted online payment but did not complete — payment due at the appointment.')}
                                </p>
                            )}
                            {payment.status === 'paid' && booking.status === 'pending' && (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {t('Payment received. Confirming the booking finalises the transaction; rejecting it triggers an automatic full refund.')}
                                </p>
                            )}
                            {payment.status === 'refund_failed' && (
                                <p className="mt-1 text-xs text-destructive-foreground">
                                    {t('Stripe refused the automatic refund. See the banner above to resolve.')}
                                </p>
                            )}
                            {remainingFormatted && (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {t(':amount refundable', { amount: remainingFormatted })}
                                </p>
                            )}
                            <div className="mt-2 flex items-center gap-3">
                                {stripeDashboardDeepLink && (
                                    <a
                                        href={stripeDashboardDeepLink}
                                        target="_blank"
                                        rel="noreferrer noopener"
                                        className="inline-flex items-center gap-1.5 text-xs font-medium text-foreground underline-offset-4 hover:underline"
                                    >
                                        {t('Open in Stripe')}
                                        <ExternalLinkIcon aria-hidden="true" className="size-3.5" />
                                    </a>
                                )}
                                {canIssueRefund && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setRefundDialogOpen(true)}
                                    >
                                        {t('Refund')}
                                    </Button>
                                )}
                            </div>
                        </Meta>
                    )}

                    {refunds.length > 0 && (
                        <Meta label={t('Refunds')}>
                            <ul className="flex flex-col gap-2">
                                {refunds.map((refund) => (
                                    <li
                                        key={refund.id}
                                        className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border/60 bg-background px-3 py-2 text-xs"
                                    >
                                        <div className="flex flex-col gap-0.5">
                                            <span className="font-medium tabular-nums text-foreground">
                                                {formatMoney(refund.amount_cents, refund.currency)}
                                                <span className="ml-2 text-muted-foreground">
                                                    {formatRefundStatus(refund.status, t)}
                                                </span>
                                            </span>
                                            <span className="text-muted-foreground">
                                                {formatRefundReason(refund.reason, t)}
                                                {' · '}
                                                {refund.initiator_name ?? t('System')}
                                                {refund.stripe_refund_id_last4 && (
                                                    <>
                                                        {' · '}
                                                        <span className="tabular-nums">re_…{refund.stripe_refund_id_last4}</span>
                                                    </>
                                                )}
                                            </span>
                                        </div>
                                        {refund.has_stripe_link && (
                                            <a
                                                href={stripeRefundLink.url({ booking: booking.id, refund: refund.id })}
                                                target="_blank"
                                                rel="noreferrer noopener"
                                                className="inline-flex items-center gap-1 text-[11px] font-medium text-foreground underline-offset-4 hover:underline"
                                            >
                                                {t('Stripe')}
                                                <ExternalLinkIcon aria-hidden="true" className="size-3" />
                                            </a>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </Meta>
                    )}

                    {isDisputePa && disputePaymentAction && (
                        <div
                            role="alert"
                            className="flex flex-col gap-2 rounded-md border border-destructive/40 bg-destructive/8 px-4 py-3 text-sm"
                        >
                            <p className="font-medium text-destructive-foreground">
                                {t('A dispute was opened on this booking. Resolve it in Stripe.')}
                            </p>
                            {disputeReason && (
                                <p className="text-xs text-muted-foreground">
                                    {t('Reason: :reason', { reason: disputeReason })}
                                </p>
                            )}
                            <div className="flex flex-wrap items-center gap-2">
                                {disputeDeepLink && (
                                    <a
                                        href={disputeDeepLink}
                                        target="_blank"
                                        rel="noreferrer noopener"
                                        className="inline-flex items-center gap-1.5 text-xs font-medium text-foreground underline-offset-4 hover:underline"
                                    >
                                        {t('Respond in Stripe')}
                                        <ExternalLinkIcon aria-hidden="true" className="size-3.5" />
                                    </a>
                                )}
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={handleDismissDisputePa}
                                >
                                    {t('Dismiss')}
                                </Button>
                            </div>
                        </div>
                    )}

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
            {canIssueRefund && payment && payment.currency && (
                <RefundDialog
                    bookingId={booking.id}
                    currency={payment.currency}
                    remainingCents={remainingRefundableCents}
                    open={refundDialogOpen}
                    onOpenChange={setRefundDialogOpen}
                />
            )}
        </Sheet>
    );
}

function formatRefundStatus(status: DashboardBookingRefund['status'], t: (s: string) => string): string {
    switch (status) {
        case 'succeeded':
            return t('Succeeded');
        case 'failed':
            return t('Failed');
        case 'pending':
        default:
            return t('Pending');
    }
}

function formatRefundReason(reason: string, t: (s: string) => string): string {
    switch (reason) {
        case 'cancelled-after-payment':
            return t('Late payment');
        case 'customer-requested':
            return t('Customer cancellation');
        case 'business-cancelled':
            return t('Business cancellation');
        case 'admin-manual':
            return t('Manual refund');
        case 'business-rejected-pending':
            return t('Booking rejected');
        default:
            // G-007 (PAYMENTS Hardening Round 2): unknown reasons surface
            // as a generic localized fallback rather than the raw internal
            // string. New refund reasons added server-side without a UI
            // update degrade gracefully instead of leaking internals.
            return t('Unknown');
    }
}
