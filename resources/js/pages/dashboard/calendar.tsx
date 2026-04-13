import { useState, useCallback } from 'react';
import { usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { useTrans } from '@/hooks/use-trans';
import { getCollaboratorColorMap } from '@/lib/calendar-colors';
import { CalendarHeader } from '@/components/calendar/calendar-header';
import { CollaboratorFilter } from '@/components/calendar/collaborator-filter';
import { WeekView } from '@/components/calendar/week-view';
import { DayView } from '@/components/calendar/day-view';
import { MonthView } from '@/components/calendar/month-view';
import BookingDetailSheet from '@/components/dashboard/booking-detail-sheet';
import ManualBookingDialog from '@/components/dashboard/manual-booking-dialog';
import type { PageProps, DashboardBooking, CalendarCollaborator, ServiceWithCollaborators } from '@/types';

interface CalendarPageProps extends PageProps {
    bookings: DashboardBooking[];
    collaborators: CalendarCollaborator[];
    services: ServiceWithCollaborators[];
    view: 'day' | 'week' | 'month';
    date: string;
    isAdmin: boolean;
    timezone: string;
}

export default function CalendarPage() {
    const { bookings, collaborators, services, view, date, isAdmin, timezone } =
        usePage<CalendarPageProps>().props;
    const { t } = useTrans();

    // Booking detail sheet state
    const [selectedBooking, setSelectedBooking] = useState<DashboardBooking | null>(null);
    const [sheetOpen, setSheetOpen] = useState(false);

    // Manual booking dialog state
    const [dialogOpen, setDialogOpen] = useState(false);

    // Collaborator filter state (admin only) — D-060
    const [visibleIds, setVisibleIds] = useState<Set<number>>(
        () => new Set(collaborators.map((c) => c.id)),
    );

    // Color map based on collaborator order (D-059)
    const colorMap = getCollaboratorColorMap(collaborators.map((c) => c.id));

    // Filter bookings by visible collaborators
    const filteredBookings = isAdmin
        ? bookings.filter((b) => visibleIds.has(b.collaborator.id))
        : bookings;

    const handleBookingClick = useCallback((booking: DashboardBooking) => {
        setSelectedBooking(booking);
        setSheetOpen(true);
    }, []);

    function toggleCollaborator(id: number) {
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
            if (prev.size === collaborators.length) {
                return new Set();
            }
            return new Set(collaborators.map((c) => c.id));
        });
    }

    const ViewComponent = view === 'day' ? DayView : view === 'month' ? MonthView : WeekView;

    return (
        <AuthenticatedLayout title={t('Calendar')}>
            <div className="flex h-[calc(100vh-3.5rem)] flex-col">
                <CalendarHeader
                    view={view}
                    date={date}
                    isAdmin={isAdmin}
                    onNewBooking={() => setDialogOpen(true)}
                />

                {isAdmin && collaborators.length > 1 && (
                    <div className="flex-none border-b border-gray-200 px-6 py-3">
                        <CollaboratorFilter
                            collaborators={collaborators}
                            visibleIds={visibleIds}
                            colorMap={colorMap}
                            onToggle={toggleCollaborator}
                            onToggleAll={toggleAll}
                        />
                    </div>
                )}

                <div className="flex-1 overflow-hidden">
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
