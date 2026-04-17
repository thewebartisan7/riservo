import { createContext, useContext, type ComponentType, type ReactNode } from 'react';

/**
 * Shared (NOT lazy-loaded) context for the DndCalendarShell.
 *
 * Views and the CalendarEvent card read `DraggableBooking` / `ResizeHandle`
 * from this context. When the shell has mounted, both are wired into
 * dnd-kit. When absent (mobile, or before the shell lazy-chunk has loaded),
 * both fall back to `null` and the views render without drag affordance.
 *
 * This split keeps `@dnd-kit/core` out of the main bundle (D-100). Only the
 * shell module statically imports dnd-kit; this file carries no such cost.
 */
export interface DraggableBookingProps {
    bookingId: number;
    startsAtUtc: string;
    endsAtUtc: string;
    durationMinutes: number;
    children: ReactNode;
}

export interface ResizeHandleProps {
    bookingId: number;
    startsAtUtc: string;
    endsAtUtc: string;
    durationMinutes: number;
    className?: string;
}

export interface DndCalendarContextValue {
    DraggableBooking: ComponentType<DraggableBookingProps>;
    ResizeHandle: ComponentType<ResizeHandleProps>;
    pendingIds: Set<number>;
}

/**
 * Default: pass-through (no drag). Used on mobile and before the shell's
 * lazy chunk has resolved.
 */
const PassThroughDraggable: ComponentType<DraggableBookingProps> = ({ children }) => (
    <>{children}</>
);

const PassThroughResize: ComponentType<ResizeHandleProps> = () => null;

export const DndCalendarContext = createContext<DndCalendarContextValue>({
    DraggableBooking: PassThroughDraggable,
    ResizeHandle: PassThroughResize,
    pendingIds: new Set(),
});

export function useDndCalendarContext(): DndCalendarContextValue {
    return useContext(DndCalendarContext);
}
