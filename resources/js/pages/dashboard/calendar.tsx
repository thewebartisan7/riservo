import { useState, useCallback } from 'react';
import { usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { useTrans } from '@/hooks/use-trans';
import { getProviderColorMap } from '@/lib/calendar-colors';
import { CalendarHeader } from '@/components/calendar/calendar-header';
import { ProviderFilter } from '@/components/calendar/provider-filter';
import { WeekView } from '@/components/calendar/week-view';
import { DayView } from '@/components/calendar/day-view';
import { MonthView } from '@/components/calendar/month-view';
import BookingDetailSheet from '@/components/dashboard/booking-detail-sheet';
import ManualBookingDialog from '@/components/dashboard/manual-booking-dialog';
import type { PageProps, DashboardBooking, CalendarProvider, ServiceWithProviders } from '@/types';

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
                    onNewBooking={() => setDialogOpen(true)}
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

                <div className="flex min-h-0 flex-1 flex-col">
                    <ViewComponent
                        bookings={filteredBookings}
                        date={date}
                        timezone={timezone}
                        colorMap={colorMap}
                        onBookingClick={handleBookingClick}
                    />
                </div>
            </div>

            <BookingDetailSheet
                booking={selectedBooking}
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                timezone={timezone}
            />

            {isAdmin && (
                <ManualBookingDialog
                    open={dialogOpen}
                    onOpenChange={setDialogOpen}
                    services={services}
                    timezone={timezone}
                />
            )}
        </AuthenticatedLayout>
    );
}
