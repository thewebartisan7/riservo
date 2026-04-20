import {
    type ReactNode,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { createPortal } from 'react-dom';
import {
    DndContext,
    PointerSensor,
    type DragEndEvent,
    type DragMoveEvent,
    type DragStartEvent,
    useDraggable,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import { restrictToWindowEdges } from '@dnd-kit/modifiers';
import { differenceInCalendarDays, parseISO, startOfWeek } from 'date-fns';
import { fromZonedTime } from 'date-fns-tz';
import type { DashboardBooking } from '@/types';
import { useReschedule } from '@/hooks/use-reschedule';
import { getBookingGridPosition, getDateInTimezone } from './calendar-event';
import { formatTimeShort } from '@/lib/datetime-format';
import {
    DndCalendarContext,
    type DndCalendarContextValue,
    type DraggableBookingProps,
    type ResizeHandleProps,
} from './dnd-context';

/**
 * DndCalendarShell — the only module that statically imports `@dnd-kit/core`.
 * Loaded via React.lazy() on the calendar route (D-100) so the dnd-kit chunk
 * doesn't land in the main bundle.
 *
 * Provides:
 *   - DndContext with a 5-px activation threshold (so clicks on empty cells
 *     and bookings keep firing normally up to that distance).
 *   - A `DraggableBooking` component via context — CalendarEvent reads this
 *     from DndCalendarContext and wraps its button with the draggable refs.
 *   - A `ResizeHandle` component via context — rendered at the bottom edge
 *     of CalendarEvent; drag updates the booking's duration.
 *   - A ghost rectangle (portalled to `document.body` to escape any
 *     ancestor with a `transform` that would break `position: fixed`) that
 *     highlights the snapped target slot and carries the booking title plus
 *     the projected new start time.
 *
 * Drag math (no droppables — keeps the grid clean for click-to-create):
 *   - vertical delta → time delta via container.height / 288 rows × 5 min.
 *   - horizontal delta → day delta (week view only) via container.width / 7.
 *   - snapped to 15-minute client-side grid (SNAP_MINUTES). Server enforces
 *     the actual service.slot_interval_minutes and 422s off-grid (D-106).
 */
interface DragData {
    kind: 'move' | 'resize';
    bookingId: number;
    startsAtUtc: string;
    endsAtUtc: string;
    durationMinutes: number;
}

interface DndCalendarShellProps {
    children: ReactNode;
    bookings: DashboardBooking[];
    view: 'day' | 'week' | 'month';
    /** yyyy-MM-dd of the page's reference date. Used to pick the week start for ghost column math. */
    date: string;
    /** Business timezone. Needed to place the ghost slot at the correct local hour. */
    timezone: string;
    onErrorMessage: (message: string) => void;
    /**
     * Ref to the time-grid container (the <ol> in week/day view). Passed by
     * the page so the shell can measure row/column size at drag-end. Kept as
     * a mutable ref-like object (MutableRefObject) rather than a callback
     * ref so the shell can read the current DOM node in its handlers.
     */
    gridContainerRef: { current: HTMLElement | null };
    /** Kept for API symmetry; registration actually happens via the page. */
    registerGridContainer?: (el: HTMLElement | null) => void;
}

interface DragGhost {
    top: number;
    left: number;
    width: number;
    height: number;
    /** Snapped new start time, localised. */
    label: string;
    /** Booking title shown inside the ghost. */
    title: string;
}

interface ReschedulePreview {
    /** Visual ghost — derived from the same clamped math as the payload. */
    ghost: DragGhost;
    /** UTC ISO string of the snapped new start time. */
    startsAtUtc: string;
    /** Snapped new duration. For `move` drags, equals the original duration. */
    durationMinutes: number;
    /**
     * True when the preview differs from the booking's current state.
     * `handleDragEnd` short-circuits when false so we don't fire a no-op PATCH.
     */
    changed: boolean;
}

const MIN_DURATION_MINUTES = 15;
const SNAP_MINUTES = 15;

function DraggableBookingImpl({
    bookingId,
    startsAtUtc,
    endsAtUtc,
    durationMinutes,
    children,
}: DraggableBookingProps) {
    const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
        id: `booking-${bookingId}-move`,
        data: {
            kind: 'move',
            bookingId,
            startsAtUtc,
            endsAtUtc,
            durationMinutes,
        } satisfies DragData,
    });

    return (
        <div
            ref={setNodeRef}
            {...listeners}
            {...attributes}
            className={`h-full w-full touch-none ${isDragging ? 'opacity-40' : ''}`}
        >
            {children}
        </div>
    );
}

function ResizeHandleImpl({
    bookingId,
    startsAtUtc,
    endsAtUtc,
    durationMinutes,
    className,
}: ResizeHandleProps) {
    const { attributes, listeners, setNodeRef } = useDraggable({
        id: `booking-${bookingId}-resize`,
        data: {
            kind: 'resize',
            bookingId,
            startsAtUtc,
            endsAtUtc,
            durationMinutes,
        } satisfies DragData,
    });

    return (
        <div
            ref={setNodeRef}
            {...listeners}
            {...attributes}
            data-no-slot-click=""
            aria-label="Resize booking"
            className={
                className ??
                'absolute inset-x-2 bottom-0 z-10 h-2 cursor-ns-resize touch-none rounded-sm bg-foreground/10 opacity-0 transition-opacity hover:opacity-100 focus-visible:opacity-100 group-hover:opacity-60'
            }
        />
    );
}

export default function DndCalendarShell({
    children,
    bookings,
    view,
    date,
    timezone,
    onErrorMessage,
    gridContainerRef,
}: DndCalendarShellProps) {
    const { reschedule, pendingIds } = useReschedule();
    const [ghost, setGhost] = useState<DragGhost | null>(null);
    // onDragMove fires at pointer rate; we coalesce to one update per frame
    // and stash the latest pending ghost so we never block the drag thread.
    const ghostRafRef = useRef<number | null>(null);
    const pendingGhostRef = useRef<DragGhost | null>(null);

    useEffect(() => {
        return () => {
            if (ghostRafRef.current !== null) {
                cancelAnimationFrame(ghostRafRef.current);
            }
        };
    }, []);

    const sensors = useSensors(
        useSensor(PointerSensor, {
            activationConstraint: { distance: 5 },
        }),
    );

    /**
     * Single source of truth for drag snapping + clamping. Produces both the
     * ghost visual and the exact payload the PATCH will send, so the preview
     * and the commit can never diverge — if the UI shows "12:00 PM Monday",
     * that is what the server receives.
     */
    const computePreview = useCallback(
        (data: DragData, deltaX: number, deltaY: number): ReschedulePreview | null => {
            const container = gridContainerRef.current;
            if (!container) return null;
            const booking = bookings.find((b) => b.id === data.bookingId);
            const title =
                booking?.service?.name ?? booking?.external_title ?? '—';

            const rect = container.getBoundingClientRect();
            const HEADER_PX = 28; // 1.75rem offset at the top of the grid
            const gridHeight = rect.height - HEADER_PX;
            if (gridHeight <= 0) return null;

            const minutesPerPixel = (24 * 60) / gridHeight;
            const deltaMinutes =
                Math.round((deltaY * minutesPerPixel) / SNAP_MINUTES) * SNAP_MINUTES;

            const { gridRow, gridSpan } = getBookingGridPosition(
                data.startsAtUtc,
                data.endsAtUtc,
                timezone,
            );
            const originalStartMinutes = (gridRow - 2) * 5; // row 2 = 00:00
            const originalDurationMinutes = gridSpan * 5;

            let newStartMinutes = originalStartMinutes;
            let newDurationMinutes = originalDurationMinutes;

            if (data.kind === 'resize') {
                newDurationMinutes = Math.max(
                    MIN_DURATION_MINUTES,
                    Math.round((data.durationMinutes + deltaMinutes) / SNAP_MINUTES) * SNAP_MINUTES,
                );
            } else {
                newStartMinutes = originalStartMinutes + deltaMinutes;
            }

            // Clamp inside the 24h grid.
            //   - `move`: pin the start so the full (unchanged) duration still
            //     fits before midnight. Otherwise the ghost would show a
            //     truncated slot while the PATCH would ask the server to
            //     straddle two days.
            //   - `resize`: the start is fixed (the user is dragging the
            //     bottom edge, not the whole card), so clampedStart must be
            //     the ORIGINAL start — not 24h-SNAP, which would paint the
            //     ghost at 23:45 for bookings that legitimately start later.
            const clampedStart =
                data.kind === 'resize'
                    ? originalStartMinutes
                    : Math.max(
                        0,
                        Math.min(newStartMinutes, Math.max(0, 24 * 60 - originalDurationMinutes)),
                    );
            const clampedDuration = Math.max(
                MIN_DURATION_MINUTES,
                Math.min(newDurationMinutes, 24 * 60 - clampedStart),
            );

            // Column math — week view snaps horizontally by whole days.
            let originalColumn = 0;
            let columns = 1;
            let columnWidth = rect.width;
            if (view === 'week') {
                columns = 7;
                columnWidth = rect.width / columns;
                const bookingDay = parseISO(getDateInTimezone(data.startsAtUtc, timezone));
                const weekStart = startOfWeek(parseISO(date), { weekStartsOn: 1 });
                originalColumn = Math.max(
                    0,
                    Math.min(6, differenceInCalendarDays(bookingDay, weekStart)),
                );
            }
            let newColumn = originalColumn;
            if (data.kind === 'move' && view === 'week' && columnWidth > 0) {
                const deltaDays = Math.round(deltaX / columnWidth);
                newColumn = Math.max(0, Math.min(columns - 1, originalColumn + deltaDays));
            }

            const top = rect.top + HEADER_PX + (clampedStart / (24 * 60)) * gridHeight;
            const left = rect.left + newColumn * columnWidth;
            const width = columnWidth;
            const height = (clampedDuration / (24 * 60)) * gridHeight;

            // Build the UTC instant for the clamped wall-clock slot via the
            // target local date + local minutes in the business tz, then
            // convert to UTC with `fromZonedTime`. DST-safe: the offset is
            // resolved at the target instant, so dragging across a DST
            // transition keeps the local hour the user saw in the ghost.
            const originalLocalKey = getDateInTimezone(data.startsAtUtc, timezone);
            const [origYear, origMonth, origDay] = originalLocalKey.split('-').map(Number);
            // Advance the calendar day via UTC arithmetic (DST-free) to land
            // on the right day-of-month without browser-tz interference.
            const localBase = new Date(Date.UTC(origYear, origMonth - 1, origDay));
            localBase.setUTCDate(localBase.getUTCDate() + (newColumn - originalColumn));
            const targetYear = localBase.getUTCFullYear();
            const targetMonth = String(localBase.getUTCMonth() + 1).padStart(2, '0');
            const targetDay = String(localBase.getUTCDate()).padStart(2, '0');
            const targetHour = String(Math.floor(clampedStart / 60)).padStart(2, '0');
            const targetMinute = String(clampedStart % 60).padStart(2, '0');
            const newStartUtc =
                data.kind === 'resize'
                    ? new Date(data.startsAtUtc)
                    : fromZonedTime(
                        `${targetYear}-${targetMonth}-${targetDay}T${targetHour}:${targetMinute}:00`,
                        timezone,
                    );

            const startsAtUtc = newStartUtc.toISOString();
            const durationMinutes =
                data.kind === 'resize' ? clampedDuration : data.durationMinutes;
            const changed =
                data.kind === 'resize'
                    ? clampedDuration !== data.durationMinutes
                    : clampedStart !== originalStartMinutes || newColumn !== originalColumn;

            return {
                ghost: {
                    top,
                    left,
                    width,
                    height,
                    label: formatTimeShort(startsAtUtc, timezone),
                    title,
                },
                startsAtUtc,
                durationMinutes,
                changed,
            };
        },
        [bookings, date, gridContainerRef, timezone, view],
    );

    const handleDragStart = useCallback(
        (event: DragStartEvent) => {
            const data = event.active.data.current as DragData | undefined;
            if (!data) return;
            // Seed the ghost at the original position so it appears immediately.
            const preview = computePreview(data, 0, 0);
            setGhost(preview?.ghost ?? null);
        },
        [computePreview],
    );

    const handleDragMove = useCallback(
        (event: DragMoveEvent) => {
            const data = event.active.data.current as DragData | undefined;
            if (!data) return;
            const preview = computePreview(data, event.delta.x, event.delta.y);
            pendingGhostRef.current = preview?.ghost ?? null;
            if (ghostRafRef.current !== null) return;
            ghostRafRef.current = requestAnimationFrame(() => {
                ghostRafRef.current = null;
                setGhost(pendingGhostRef.current);
            });
        },
        [computePreview],
    );

    const handleDragEnd = useCallback(
        async (event: DragEndEvent) => {
            setGhost(null);
            if (ghostRafRef.current !== null) {
                cancelAnimationFrame(ghostRafRef.current);
                ghostRafRef.current = null;
            }

            const data = event.active.data.current as DragData | undefined;
            if (!data) return;

            const preview = computePreview(data, event.delta.x, event.delta.y);
            if (!preview || !preview.changed) return;

            const result = await reschedule({
                bookingId: data.bookingId,
                startsAtUtc: preview.startsAtUtc,
                durationMinutes: preview.durationMinutes,
            });
            // `cancelled` means client-abort (navigation/unmount): indeterminate
            // server state, stay silent. Real failures still surface a toast.
            if (!result.success && !result.cancelled) {
                onErrorMessage(result.message ?? 'Reschedule failed.');
            }
        },
        [computePreview, reschedule, onErrorMessage],
    );

    const ctxValue = useMemo<DndCalendarContextValue>(
        () => ({
            DraggableBooking: DraggableBookingImpl,
            ResizeHandle: ResizeHandleImpl,
            pendingIds,
        }),
        [pendingIds],
    );

    const handleDragCancel = useCallback(() => {
        setGhost(null);
        if (ghostRafRef.current !== null) {
            cancelAnimationFrame(ghostRafRef.current);
            ghostRafRef.current = null;
        }
    }, []);

    return (
        <DndCalendarContext.Provider value={ctxValue}>
            <DndContext
                sensors={sensors}
                onDragStart={handleDragStart}
                onDragMove={handleDragMove}
                onDragEnd={handleDragEnd}
                onDragCancel={handleDragCancel}
                modifiers={[restrictToWindowEdges]}
            >
                {children}
                {ghost &&
                    createPortal(
                        <div
                            aria-hidden="true"
                            className="pointer-events-none fixed z-50 flex flex-col gap-0.5 overflow-hidden rounded-md border-2 border-primary/70 bg-primary/10 px-1.5 py-1 text-[11px] font-semibold text-foreground shadow-lg"
                            style={{
                                top: ghost.top,
                                left: ghost.left,
                                width: ghost.width,
                                height: ghost.height,
                            }}
                        >
                            <span className="truncate">{ghost.title}</span>
                            <span className="font-normal tabular-nums text-muted-foreground">
                                {ghost.label}
                            </span>
                        </div>,
                        document.body,
                    )}
            </DndContext>
        </DndCalendarContext.Provider>
    );
}
