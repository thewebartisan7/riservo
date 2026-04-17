import {
    type ReactNode,
    useCallback,
    useMemo,
    useState,
} from 'react';
import {
    DndContext,
    DragOverlay,
    PointerSensor,
    type DragEndEvent,
    type DragStartEvent,
    useDraggable,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import { restrictToWindowEdges } from '@dnd-kit/modifiers';
import type { DashboardBooking } from '@/types';
import { useReschedule } from '@/hooks/use-reschedule';
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
 *   - A `DragOverlay` rendering a faded copy of the dragged card (D-101).
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
    onErrorMessage,
    gridContainerRef,
}: DndCalendarShellProps) {
    const { reschedule, pendingIds } = useReschedule();
    const [draggingBooking, setDraggingBooking] = useState<DashboardBooking | null>(null);

    const sensors = useSensors(
        useSensor(PointerSensor, {
            activationConstraint: { distance: 5 },
        }),
    );

    const handleDragStart = useCallback(
        (event: DragStartEvent) => {
            const data = event.active.data.current as DragData | undefined;
            if (!data) return;
            const booking = bookings.find((b) => b.id === data.bookingId);
            setDraggingBooking(booking ?? null);
        },
        [bookings],
    );

    const handleDragEnd = useCallback(
        async (event: DragEndEvent) => {
            setDraggingBooking(null);

            const data = event.active.data.current as DragData | undefined;
            if (!data) return;

            const container = gridContainerRef.current;
            if (!container) return;

            const rect = container.getBoundingClientRect();
            const HEADER_PX = 28; // 1.75rem offset at the top of the grid
            const gridHeight = rect.height - HEADER_PX;
            if (gridHeight <= 0) return;

            const minutesPerPixel = (24 * 60) / gridHeight;
            const deltaMinutes =
                Math.round((event.delta.y * minutesPerPixel) / SNAP_MINUTES) * SNAP_MINUTES;

            if (data.kind === 'resize') {
                const newDuration = Math.max(
                    MIN_DURATION_MINUTES,
                    Math.round((data.durationMinutes + deltaMinutes) / SNAP_MINUTES) * SNAP_MINUTES,
                );
                if (newDuration === data.durationMinutes) return;

                const result = await reschedule({
                    bookingId: data.bookingId,
                    startsAtUtc: data.startsAtUtc,
                    durationMinutes: newDuration,
                });
                if (!result.success) {
                    onErrorMessage(result.message ?? 'Resize failed.');
                }
                return;
            }

            let deltaDays = 0;
            if (view === 'week') {
                const columnWidth = rect.width / 7;
                if (columnWidth > 0) {
                    deltaDays = Math.round(event.delta.x / columnWidth);
                }
            }

            if (deltaMinutes === 0 && deltaDays === 0) return;

            const originalStart = new Date(data.startsAtUtc);
            const newStart = new Date(originalStart);
            newStart.setUTCDate(newStart.getUTCDate() + deltaDays);
            newStart.setUTCMinutes(newStart.getUTCMinutes() + deltaMinutes);

            const result = await reschedule({
                bookingId: data.bookingId,
                startsAtUtc: newStart.toISOString(),
                durationMinutes: data.durationMinutes,
            });
            if (!result.success) {
                onErrorMessage(result.message ?? 'Reschedule failed.');
            }
        },
        [reschedule, onErrorMessage, view, gridContainerRef],
    );

    const ctxValue = useMemo<DndCalendarContextValue>(
        () => ({
            DraggableBooking: DraggableBookingImpl,
            ResizeHandle: ResizeHandleImpl,
            pendingIds,
        }),
        [pendingIds],
    );

    return (
        <DndCalendarContext.Provider value={ctxValue}>
            <DndContext
                sensors={sensors}
                onDragStart={handleDragStart}
                onDragEnd={handleDragEnd}
                modifiers={[restrictToWindowEdges]}
            >
                {children}
                <DragOverlay dropAnimation={null}>
                    {draggingBooking ? (
                        <div className="pointer-events-none rounded-md border-2 border-primary bg-background/90 px-2 py-1 text-[11px] font-semibold text-foreground shadow-lg ring-2 ring-primary/30">
                            {draggingBooking.service?.name ??
                                draggingBooking.external_title ??
                                '—'}
                        </div>
                    ) : null}
                </DragOverlay>
            </DndContext>
        </DndCalendarContext.Provider>
    );
}
