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
    /**
     * True when the request was aborted client-side before a definitive
     * response. Server state is indeterminate — caller should stay silent
     * (no toast, no reload) rather than guess an outcome.
     */
    cancelled?: boolean;
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
    // `useHttp` reads the request body from its form state. The reschedule
    // payload is fully dynamic per call (a different booking + delta on every
    // drag), so we seed the hook with a placeholder shape and swap in the
    // real values right before each PATCH via `transform()` — Inertia's
    // canonical sync-before-submit hook.
    const http = useHttp({ starts_at: '', duration_minutes: 0 });
    const [pendingIds, setPendingIds] = useState<Set<number>>(new Set());

    const reschedule = useCallback(
        async ({ bookingId, startsAtUtc, durationMinutes }: ReschedulePayload): Promise<RescheduleResult> => {
            setPendingIds((prev) => new Set(prev).add(bookingId));

            http.transform(() => ({
                starts_at: startsAtUtc,
                duration_minutes: durationMinutes,
            }));

            try {
                const result = await new Promise<RescheduleResult>((resolve) => {
                    let settled = false;
                    const settle = (value: RescheduleResult) => {
                        if (settled) return;
                        settled = true;
                        resolve(value);
                    };

                    http.patch(rescheduleAction.url({ booking: bookingId }), {
                        onSuccess: (resp: unknown) => {
                            const data = resp as { booking: DashboardBooking };
                            settle({ success: true, booking: data.booking });
                        },
                        // 422 validation errors. The controller throws
                        // ValidationException::withMessages(['booking' => $msg])
                        // so `errors` always carries a keyed message for us.
                        onError: (errors: Record<string, string>) => {
                            const msg = Object.values(errors)[0] ?? 'Reschedule failed.';
                            settle({ success: false, message: msg });
                        },
                        // Non-422 4xx/5xx (403, 500). Returning `false` keeps
                        // Inertia from routing to an error page so the calendar
                        // stays mounted.
                        onHttpException: (response: { status: number }) => {
                            const status = response.status;
                            const msg =
                                status === 403
                                    ? 'You cannot reschedule this booking.'
                                    : status >= 500
                                        ? 'Something went wrong. Please try again.'
                                        : 'Reschedule failed.';
                            settle({ success: false, message: msg });
                            return false;
                        },
                        onNetworkError: () => {
                            settle({
                                success: false,
                                message: 'Network error. Please check your connection and try again.',
                            });
                        },
                        // Inertia fires this when the in-flight request is
                        // aborted (navigation / unmount / explicit cancel).
                        // Abort means "client stopped waiting" — the server
                        // may or may not have committed. We can't claim
                        // success and can't claim failure without lying. Mark
                        // `cancelled` so the caller stays silent (no toast,
                        // no reload that could run against a page the user
                        // just navigated away from).
                        onCancel: () => {
                            settle({ success: false, cancelled: true });
                        },
                    })
                        // `.patch()` also rejects on non-2xx in addition to
                        // invoking the callbacks above. Swallow here so the
                        // rejection doesn't surface as an unhandledrejection —
                        // our callbacks have already resolved `settle()`.
                        .catch(() => undefined);
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
