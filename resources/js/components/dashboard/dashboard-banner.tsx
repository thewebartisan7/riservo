import type { ReactNode } from 'react';
import { Alert, AlertAction, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { AlertTriangleIcon } from 'lucide-react';

type BannerVariant = 'info' | 'warning' | 'error' | 'success';

interface Props {
    variant: BannerVariant;
    title: string;
    description: ReactNode;
    action?: ReactNode;
    testId: string;
    fullBleed: boolean;
}

/**
 * PAYMENTS Session 5: single wrapper for the dashboard-wide banners stack
 * (subscription lapse, payment-mode mismatch, unbookable services). All
 * three previously duplicated the same padding / max-width wrapper and the
 * same Alert+icon+title+description+action structure; this collapses them
 * into one presentational component. No priority / stacking logic — the
 * layout renders all applicable banners in source order.
 */
export function DashboardBanner({
    variant,
    title,
    description,
    action,
    testId,
    fullBleed,
}: Props) {
    return (
        <div
            className={
                fullBleed
                    ? 'border-b border-border/60 px-5 pb-3 pt-3 sm:px-8'
                    : 'mx-auto w-full max-w-6xl px-5 pt-5 sm:px-8 sm:pt-8'
            }
            data-testid={testId}
        >
            <Alert variant={variant} role="alert">
                <AlertTriangleIcon aria-hidden="true" />
                <AlertTitle>{title}</AlertTitle>
                <AlertDescription>{description}</AlertDescription>
                {action && <AlertAction>{action}</AlertAction>}
            </Alert>
        </div>
    );
}
