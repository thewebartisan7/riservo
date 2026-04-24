import { Badge } from '@/components/ui/badge';

/**
 * PAYMENTS Session 2b — chip for the admin bookings-list Payment column
 * and the BookingDetailSheet Payment panel.
 *
 * `not_applicable` is displayed as "Offline" because that's what it means
 * in admin terms — the booking has no online-payment expectation. The URL
 * filter key maps "offline" ↔ `not_applicable` in BookingController::index.
 */
const paymentConfig: Record<
    string,
    {
        variant: 'success' | 'warning' | 'destructive' | 'error' | 'secondary' | 'info';
        label: string;
    }
> = {
    paid: { variant: 'success', label: 'Paid' },
    awaiting_payment: { variant: 'warning', label: 'Awaiting' },
    unpaid: { variant: 'warning', label: 'Unpaid' },
    refunded: { variant: 'secondary', label: 'Refunded' },
    partially_refunded: { variant: 'secondary', label: 'Partial refund' },
    refund_failed: { variant: 'error', label: 'Refund failed' },
    not_applicable: { variant: 'secondary', label: 'Offline' },
};

export function PaymentStatusBadge({ status }: { status: string }) {
    const config = paymentConfig[status] ?? { variant: 'secondary' as const, label: status };
    return <Badge variant={config.variant}>{config.label}</Badge>;
}
