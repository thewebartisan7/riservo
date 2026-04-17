import { lazy, Suspense, useCallback, useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { addDays, addMonths, addWeeks, format, parseISO, subDays, subMonths, subWeeks } from 'date-fns';
import { index as calendarIndex } from '@/actions/App/Http/Controllers/Dashboard/CalendarController';
import { Spinner } from '@/components/ui/spinner';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { useTrans } from '@/hooks/use-trans';
import { getProviderColorMap } from '@/lib/calendar-colors';
import { CalendarHeader } from '@/components/calendar/calendar-header';
import { ProviderFilter } from '@/components/calendar/provider-filter';
import { WeekView } from '@/components/calendar/week-view';
import { DayView } from '@/components/calendar/day-view';
import { MonthView } from '@/components/calendar/month-view';
import BookingDetailSheet from '@/components/dashboard/booking-detail-sheet';
import ManualBookingDialog, {
    type ManualBookingDialogSeed,
} from '@/components/dashboard/manual-booking-dialog';
import type { PageProps, DashboardBooking, CalendarProvider, ServiceWithProviders } from '@/types';

// D-100: dnd-kit lives only inside this lazy-loaded shell so it lands in a
// dedicated chunk, not the main bundle. When the shell has not resolved yet,
// the calendar renders without drag affordance via the pass-through defaults
// in dnd-context.tsx.
const DndCalendarShell = lazy(() => import('@/components/calendar/dnd-calendar-shell'));

interface CalendarPageProps extends PageProps {
    bookings: DashboardBooking[];
    providers: CalendarProvider[];
    services: ServiceWithProviders[];
    view: 'day' | 'week' | 'month';
    date: string;
    isAdmin: boolean;
    timezone: string;
}

export default function CalendarPage() {
    const { bookings, providers, services, view, date, isAdmin, timezone } =
        usePage<CalendarPageProps>().props;
    const { t } = useTrans();

    const [selectedBooking, setSelectedBooking] = useState<DashboardBooking | null>(null);
    const [sheetOpen, setSheetOpen] = useState(false);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [dialogSeed, setDialogSeed] = useState<ManualBookingDialogSeed | undefined>();
    const [rescheduleError, setRescheduleError] = useState<string | null>(null);
    const gridContainerRef = useRef<HTMLElement | null>(null);

    const [visibleIds, setVisibleIds] = useState<Set<number>>(
        () => new Set(providers.map((p) => p.id)),
    );

    const colorMap = getProviderColorMap(providers.map((p) => p.id));

    const filteredBookings = isAdmin
        ? bookings.filter((b) => visibleIds.has(b.provider.id))
        : bookings;

    const handleBookingClick = useCallback((booking: DashboardBooking) => {
        setSelectedBooking(booking);
        setSheetOpen(true);
    }, []);

    const handleEmptySlotClick = useCallback((seed: ManualBookingDialogSeed) => {
        setDialogSeed(seed);
        setDialogOpen(true);
    }, []);

    const handleHeaderNewBooking = useCallback(() => {
        setDialogSeed(undefined);
        setDialogOpen(true);
    }, []);

    const handleRescheduleError = useCallback((message: string) => {
        setRescheduleError(message);
        // Auto-clear after 6s so the banner doesn't linger forever.
        window.setTimeout(() => setRescheduleError(null), 6000);
    }, []);

    const registerGridContainer = useCallback((el: HTMLElement | null) => {
        gridContainerRef.current = el;
    }, []);

    // Keyboard navigation — ←/→ moves the view by one unit; `t` jumps to
    // today. Skipped when an input / textarea / select / contenteditable is
    // focused so typing inside the manual-booking dialog never hijacks.
    useEffect(() => {
        const handler = (e: KeyboardEvent) => {
            const target = e.target as HTMLElement | null;
            if (
                target?.closest('input, textarea, select, [contenteditable="true"], [role="textbox"]')
            ) {
                return;
            }
            // Respect modifier keys (copy/paste etc.).
            if (e.metaKey || e.ctrlKey || e.altKey) return;

            const parsed = parseISO(date);
            let next: Date | null = null;
            if (e.key === 'ArrowLeft') {
                next = view === 'day'
                    ? subDays(parsed, 1)
                    : view === 'week'
                        ? subWeeks(parsed, 1)
                        : subMonths(parsed, 1);
            } else if (e.key === 'ArrowRight') {
                next = view === 'day'
                    ? addDays(parsed, 1)
                    : view === 'week'
                        ? addWeeks(parsed, 1)
                        : addMonths(parsed, 1);
            } else if (e.key.toLowerCase() === 't') {
                next = new Date();
            }
            if (!next) return;
            e.preventDefault();
            router.get(
                calendarIndex.url(),
                { view, date: format(next, 'yyyy-MM-dd') },
                { preserveState: true, preserveScroll: true, only: ['bookings', 'view', 'date'] },
            );
        };
        document.addEventListener('keydown', handler);
        return () => document.removeEventListener('keydown', handler);
    }, [date, view]);

    // Loading state for calendar navigation (Inertia partial reload).
    const [navLoading, setNavLoading] = useState(false);
    useEffect(() => {
        const start = router.on('start', (e) => {
            const url = e.detail.visit.url.pathname;
            if (url === calendarIndex.url()) setNavLoading(true);
        });
        const finish = router.on('finish', () => setNavLoading(false));
        return () => {
            start();
            finish();
        };
    }, []);

    function toggleProvider(id: number) {
        setVisibleIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    }

    function toggleAll() {
        setVisibleIds((prev) => {
            if (prev.size === providers.length) {
                return new Set();
            }
            return new Set(providers.map((p) => p.id));
        });
    }

    const ViewComponent = view === 'day' ? DayView : view === 'month' ? MonthView : WeekView;
    const showFilter = isAdmin && providers.length > 1;

    return (
        <AuthenticatedLayout title={t('Calendar')} fullBleed>
            <div className="flex min-h-0 flex-1 flex-col">
                <CalendarHeader
                    view={view}
                    date={date}
                    isAdmin={isAdmin}
                    onNewBooking={handleHeaderNewBooking}
                />

                {showFilter && (
                    <div className="flex-none border-b border-border/70 bg-background/80 px-5 py-2.5 backdrop-blur-sm sm:px-7">
                        <ProviderFilter
                            providers={providers}
                            visibleIds={visibleIds}
                            colorMap={colorMap}
                            onToggle={toggleProvider}
                            onToggleAll={toggleAll}
                        />
                    </div>
                )}

                {rescheduleError && (
                    <div
                        role="alert"
                        className="flex-none border-b border-destructive/40 bg-destructive/10 px-5 py-2 text-sm text-destructive sm:px-7"
                    >
                        {rescheduleError}
                    </div>
                )}

                <div className="relative flex min-h-0 flex-1 flex-col">
                    {navLoading && (
                        <div
                            aria-live="polite"
                            aria-label={t('Loading calendar…')}
                            className="pointer-events-none absolute right-3 top-3 z-30 flex items-center gap-1.5 rounded-full bg-background/90 px-2.5 py-1 text-xs text-muted-foreground shadow-sm/5"
                        >
                            <Spinner className="size-3.5" />
                            {t('Loading…')}
                        </div>
                    )}
                    <Suspense
                        fallback={
                            <ViewComponent
                                bookings={filteredBookings}
                                date={date}
                                timezone={timezone}
                                colorMap={colorMap}
                                onBookingClick={handleBookingClick}
                                onEmptySlotClick={handleEmptySlotClick}
                                onRegisterGridContainer={registerGridContainer}
                            />
                        }
                    >
                        <DndCalendarShell
                            bookings={filteredBookings}
                            view={view}
                            onErrorMessage={handleRescheduleError}
                            registerGridContainer={registerGridContainer}
                            gridContainerRef={gridContainerRef}
                        >
                            <ViewComponent
                                bookings={filteredBookings}
                                date={date}
                                timezone={timezone}
                                colorMap={colorMap}
                                onBookingClick={handleBookingClick}
                                onEmptySlotClick={handleEmptySlotClick}
                                onRegisterGridContainer={registerGridContainer}
                            />
                        </DndCalendarShell>
                    </Suspense>
                </div>
            </div>

            <BookingDetailSheet
                booking={selectedBooking}
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                timezone={timezone}
            />

            {/* Dialog available to admin + staff (D-103). The header CTA is
                still admin-only via CalendarHeader; staff reach the dialog
                through click-to-create in the views. */}
            <ManualBookingDialog
                open={dialogOpen}
                onOpenChange={(open) => {
                    setDialogOpen(open);
                    if (!open) setDialogSeed(undefined);
                }}
                services={services}
                timezone={timezone}
                initial={dialogSeed}
            />
        </AuthenticatedLayout>
    );
}
