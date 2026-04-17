import { useCallback, useState } from 'react';
import { router, useHttp } from '@inertiajs/react';
import { reschedule as rescheduleAction } from '@/actions/App/Http/Controllers/Dashboard/BookingController';
import type { DashboardBooking } from '@/types';

interface ReschedulePayload {
    bookingId: number;
    startsAtUtc: string;
    durationMinutes: number;
}

interface RescheduleResult {
    /** True when the PATCH was accepted and the booking actually moved. */
    success: boolean;
    /** Authoritative server response shape. */
    booking?: DashboardBooking;
    /** Human-readable error reason, when success === false. */
    message?: string;
}

/**
 * Reschedule a booking (drag / resize). Encapsulates the PATCH, the JSON
 * response shape, and the server-error → inline-message translation so the
 * calling view only deals with success / message.
 *
 * Optimistic update is the caller's responsibility (they already hold the
 * local booking list). This hook is the thin HTTP layer; the shell wraps
 * the optimistic-and-revert dance around it.
 *
 * D-105: request body is `{ starts_at, duration_minutes }`. Server
 * recomputes `ends_at`. See PLAN-MVPC-5 cluster 7.
 */
export function useReschedule(): {
    reschedule: (payload: ReschedulePayload) => Promise<RescheduleResult>;
    pendingIds: Set<number>;
} {
    const http = useHttp({});
    const [pendingIds, setPendingIds] = useState<Set<number>>(new Set());

    const reschedule = useCallback(
        async ({ bookingId, startsAtUtc, durationMinutes }: ReschedulePayload): Promise<RescheduleResult> => {
            setPendingIds((prev) => new Set(prev).add(bookingId));

            try {
                const result = await new Promise<RescheduleResult>((resolve) => {
                    http.patch(
                        rescheduleAction.url({ booking: bookingId }),
                        {
                            starts_at: startsAtUtc,
                            duration_minutes: durationMinutes,
                        },
                        {
                            onSuccess: (resp: unknown) => {
                                const data = resp as { booking: DashboardBooking };
                                resolve({ success: true, booking: data.booking });
                            },
                            onError: (errors: Record<string, string>) => {
                                // 422 / 409 responses surface here with the
                                // controller's JSON `message` field mapped to
                                // a single-field errors object by useHttp.
                                const msg =
                                    (errors as unknown as { message?: string }).message ??
                                    Object.values(errors)[0] ??
                                    'Reschedule failed.';
                                resolve({ success: false, message: msg });
                            },
                        },
                    );
                });
                // Reload the calendar's booking list on success so the
                // server is the source of truth (optimistic revert at the
                // caller handles the failure path without a reload).
                if (result.success) {
                    router.reload({ only: ['bookings'] });
                }
                return result;
            } finally {
                setPendingIds((prev) => {
                    const next = new Set(prev);
                    next.delete(bookingId);
                    return next;
                });
            }
        },
        [http],
    );

    return { reschedule, pendingIds };
}
