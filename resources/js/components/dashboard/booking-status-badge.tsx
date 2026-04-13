import { Badge } from '@/components/ui/badge';

const statusConfig: Record<string, { variant: 'success' | 'warning' | 'destructive' | 'error' | 'secondary'; label: string }> = {
    pending: { variant: 'warning', label: 'Pending' },
    confirmed: { variant: 'success', label: 'Confirmed' },
    cancelled: { variant: 'destructive', label: 'Cancelled' },
    completed: { variant: 'secondary', label: 'Completed' },
    no_show: { variant: 'error', label: 'No Show' },
};

const sourceConfig: Record<string, { variant: 'outline' | 'secondary' | 'info'; label: string }> = {
    riservo: { variant: 'info', label: 'Online' },
    manual: { variant: 'secondary', label: 'Manual' },
    google_calendar: { variant: 'outline', label: 'Google' },
};

export function BookingStatusBadge({ status }: { status: string }) {
    const config = statusConfig[status] ?? { variant: 'secondary' as const, label: status };
    return <Badge variant={config.variant}>{config.label}</Badge>;
}

export function BookingSourceBadge({ source }: { source: string }) {
    const config = sourceConfig[source] ?? { variant: 'outline' as const, label: source };
    return <Badge variant={config.variant}>{config.label}</Badge>;
}
