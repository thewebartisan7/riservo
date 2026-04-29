import { Badge } from '@/components/ui/badge';
import { useTrans } from '@/hooks/use-trans';

/**
 * PAYMENTS Session 2b — chip for the admin bookings-list Payment column
 * and the BookingDetailSheet Payment panel.
 *
 * `not_applicable` is displayed as "Offline" because that's what it means
 * in admin terms — the booking has no online-payment expectation. The URL
 * filter key maps "offline" ↔ `not_applicable` in BookingController::index.
 *
 * G-007 (PAYMENTS Hardening Round 2): every label goes through `t()` so
 * the badge respects the active locale.
 *
 * H-003 (Codex Round 3): unknown statuses now fall back to a localized
 * `t('Unknown')` rather than the raw internal string. A future PaymentStatus
 * enum value added server-side without a UI map update degrades gracefully
 * instead of leaking the internal token to end users.
 */
type Variant = 'success' | 'warning' | 'destructive' | 'error' | 'secondary' | 'info';

const variantByStatus: Record<string, Variant> = {
    paid: 'success',
    awaiting_payment: 'warning',
    unpaid: 'warning',
    refunded: 'secondary',
    partially_refunded: 'secondary',
    refund_failed: 'error',
    not_applicable: 'secondary',
};

export function PaymentStatusBadge({ status }: { status: string }) {
    const { t } = useTrans();

    const labelByStatus: Record<string, string> = {
        paid: t('Paid'),
        awaiting_payment: t('Awaiting'),
        unpaid: t('Unpaid'),
        refunded: t('Refunded'),
        partially_refunded: t('Partial refund'),
        refund_failed: t('Refund failed'),
        not_applicable: t('Offline'),
    };

    const variant = variantByStatus[status] ?? 'secondary';
    const label = labelByStatus[status] ?? t('Unknown');

    return <Badge variant={variant}>{label}</Badge>;
}
