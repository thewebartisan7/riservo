import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { index as bookingsIndex } from '@/actions/App/Http/Controllers/Dashboard/BookingController';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import BookingDetailSheet from '@/components/dashboard/booking-detail-sheet';
import ManualBookingDialog from '@/components/dashboard/manual-booking-dialog';
import { BookingStatusBadge, BookingSourceBadge } from '@/components/dashboard/booking-status-badge';
import { useTrans } from '@/hooks/use-trans';
import { CalendarIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Frame, FrameFooter } from '@/components/ui/frame';
import { LinkButton } from '@/components/ui/link-button';
import { Popover, PopoverTrigger, PopoverPopup } from '@/components/ui/popover';
import { Select, SelectTrigger, SelectValue, SelectPopup, SelectItem } from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationLink,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import type { DashboardBooking, FilterOption, PageProps, ServiceWithCollaborators } from '@/types';

interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface BookingsPageProps extends PageProps {
    bookings: PaginatedData<DashboardBooking>;
    services: ServiceWithCollaborators[];
    collaborators: FilterOption[];
    filters: {
        status: string;
        service_id: string;
        collaborator_id: string;
        date_from: string;
        date_to: string;
        sort: string;
        direction: string;
    };
    isAdmin: boolean;
    timezone: string;
}

function formatDateTime(isoString: string, timezone: string): string {
    return new Date(isoString).toLocaleString([], {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: timezone,
    });
}

/** Parse "YYYY-MM-DD" filter string into a Date, or undefined */
function parseFilterDate(value: string): Date | undefined {
    if (!value) return undefined;
    const [y, m, d] = value.split('-').map(Number);
    return new Date(y, m - 1, d);
}

/** Format a Date into "YYYY-MM-DD" for the filter query string */
function toFilterString(date: Date): string {
    return date.toLocaleDateString('sv'); // sv locale produces YYYY-MM-DD
}

/** Display a Date in short human-readable format */
function formatShortDate(date: Date): string {
    return date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
}

export default function BookingsPage() {
    const { bookings, services, collaborators, filters, isAdmin, timezone } =
        usePage<BookingsPageProps>().props;
    const { t } = useTrans();
    const [selectedBooking, setSelectedBooking] = useState<DashboardBooking | null>(null);
    const [sheetOpen, setSheetOpen] = useState(false);
    const [dialogOpen, setDialogOpen] = useState(false);

    function applyFilter(key: string, value: string) {
        const params = { ...filters, [key]: value };
        // Remove empty filters
        const cleaned = Object.fromEntries(
            Object.entries(params).filter(([, v]) => v !== ''),
        );
        router.get(bookingsIndex.url(), cleaned, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    function openDetail(booking: DashboardBooking) {
        setSelectedBooking(booking);
        setSheetOpen(true);
    }

    const statuses = [
        { value: '', label: t('All Statuses') },
        { value: 'pending', label: t('Pending') },
        { value: 'confirmed', label: t('Confirmed') },
        { value: 'cancelled', label: t('Cancelled') },
        { value: 'completed', label: t('Completed') },
        { value: 'no_show', label: t('No Show') },
    ];

    return (
        <AuthenticatedLayout title={t('Bookings')}>
            <div className="space-y-4">
                {/* Filters */}
                <div className="flex flex-wrap items-end gap-3">
                    <div>
                        <label className="text-muted-foreground mb-1 block text-xs">
                            {t('From')}
                        </label>
                        <Popover>
                            <PopoverTrigger
                                render={<Button className="w-40 justify-start font-normal" variant="outline" />}
                            >
                                <CalendarIcon aria-hidden="true" />
                                {filters.date_from
                                    ? formatShortDate(parseFilterDate(filters.date_from)!)
                                    : <span className="text-muted-foreground">{t('Pick a date')}</span>}
                            </PopoverTrigger>
                            <PopoverPopup>
                                <Calendar
                                    mode="single"
                                    selected={parseFilterDate(filters.date_from)}
                                    defaultMonth={parseFilterDate(filters.date_from)}
                                    onSelect={(date) => applyFilter('date_from', date ? toFilterString(date) : '')}
                                />
                            </PopoverPopup>
                        </Popover>
                    </div>
                    <div>
                        <label className="text-muted-foreground mb-1 block text-xs">
                            {t('To')}
                        </label>
                        <Popover>
                            <PopoverTrigger
                                render={<Button className="w-40 justify-start font-normal" variant="outline" />}
                            >
                                <CalendarIcon aria-hidden="true" />
                                {filters.date_to
                                    ? formatShortDate(parseFilterDate(filters.date_to)!)
                                    : <span className="text-muted-foreground">{t('Pick a date')}</span>}
                            </PopoverTrigger>
                            <PopoverPopup>
                                <Calendar
                                    mode="single"
                                    selected={parseFilterDate(filters.date_to)}
                                    defaultMonth={parseFilterDate(filters.date_to)}
                                    onSelect={(date) => applyFilter('date_to', date ? toFilterString(date) : '')}
                                />
                            </PopoverPopup>
                        </Popover>
                    </div>
                    <div>
                        <label className="text-muted-foreground mb-1 block text-xs">
                            {t('Status')}
                        </label>
                        <Select
                            value={filters.status}
                            onValueChange={(val) => applyFilter('status', val ?? '')}
                        >
                            <SelectTrigger className="w-36">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectPopup>
                                {statuses.map((s) => (
                                    <SelectItem key={s.value} value={s.value}>
                                        {s.label}
                                    </SelectItem>
                                ))}
                            </SelectPopup>
                        </Select>
                    </div>
                    <div>
                        <label className="text-muted-foreground mb-1 block text-xs">
                            {t('Service')}
                        </label>
                        <Select
                            value={filters.service_id}
                            onValueChange={(val) => applyFilter('service_id', val ?? '')}
                        >
                            <SelectTrigger className="w-40">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectPopup>
                                <SelectItem value="">{t('All Services')}</SelectItem>
                                {services.map((s) => (
                                    <SelectItem key={s.id} value={String(s.id)}>
                                        {s.name}
                                    </SelectItem>
                                ))}
                            </SelectPopup>
                        </Select>
                    </div>
                    {isAdmin && (
                        <div>
                            <label className="text-muted-foreground mb-1 block text-xs">
                                {t('Collaborator')}
                            </label>
                            <Select
                                value={filters.collaborator_id}
                                onValueChange={(val) =>
                                    applyFilter('collaborator_id', val ?? '')
                                }
                            >
                                <SelectTrigger className="w-40">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectPopup>
                                    <SelectItem value="">
                                        {t('All Collaborators')}
                                    </SelectItem>
                                    {collaborators.map((c) => (
                                        <SelectItem
                                            key={c.id}
                                            value={String(c.id)}
                                        >
                                            {c.name}
                                        </SelectItem>
                                    ))}
                                </SelectPopup>
                            </Select>
                        </div>
                    )}
                    <div className="ml-auto">
                        <Button onClick={() => setDialogOpen(true)}>
                            {t('New Booking')}
                        </Button>
                    </div>
                </div>

                {/* Table */}
                <Frame className="w-full">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('Date / Time')}</TableHead>
                                <TableHead>{t('Customer')}</TableHead>
                                <TableHead>{t('Service')}</TableHead>
                                {isAdmin && (
                                    <TableHead>{t('Collaborator')}</TableHead>
                                )}
                                <TableHead>{t('Status')}</TableHead>
                                <TableHead>{t('Source')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {bookings.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={isAdmin ? 6 : 5}
                                        className="text-muted-foreground py-8 text-center"
                                    >
                                        {t('No bookings found.')}
                                    </TableCell>
                                </TableRow>
                            ) : (
                                bookings.data.map((booking) => (
                                    <TableRow
                                        key={booking.id}
                                        className="cursor-pointer"
                                        onClick={() => openDetail(booking)}
                                    >
                                        <TableCell className="text-sm">
                                            {formatDateTime(
                                                booking.starts_at,
                                                timezone,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="text-sm font-medium">
                                                {booking.customer.name}
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                {booking.customer.email}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {booking.service.name}
                                        </TableCell>
                                        {isAdmin && (
                                            <TableCell className="text-sm">
                                                {booking.collaborator.name}
                                            </TableCell>
                                        )}
                                        <TableCell>
                                            <BookingStatusBadge
                                                status={booking.status}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <BookingSourceBadge
                                                source={booking.source}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                    {bookings.last_page > 1 && (
                        <FrameFooter className="px-2 py-3">
                            <Pagination>
                                <PaginationContent>
                                    <PaginationItem>
                                        {bookings.links[0].url ? (
                                            <PaginationPrevious
                                                render={
                                                    <LinkButton
                                                        href={bookings.links[0].url}
                                                        variant="ghost"
                                                        size="default"
                                                        preserveState
                                                        preserveScroll
                                                    />
                                                }
                                            />
                                        ) : (
                                            <PaginationPrevious
                                                className="pointer-events-none opacity-50"
                                            />
                                        )}
                                    </PaginationItem>
                                    {bookings.links.slice(1, -1).map((link) => (
                                        <PaginationItem key={link.label}>
                                            {link.label === '...' ? (
                                                <PaginationEllipsis />
                                            ) : link.url ? (
                                                <PaginationLink
                                                    isActive={link.active}
                                                    render={
                                                        <LinkButton
                                                            href={link.url}
                                                            variant={link.active ? 'outline' : 'ghost'}
                                                            size="icon"
                                                            preserveState
                                                            preserveScroll
                                                        />
                                                    }
                                                >
                                                    {link.label}
                                                </PaginationLink>
                                            ) : (
                                                <PaginationLink isActive={link.active}>
                                                    {link.label}
                                                </PaginationLink>
                                            )}
                                        </PaginationItem>
                                    ))}
                                    <PaginationItem>
                                        {bookings.links[bookings.links.length - 1].url ? (
                                            <PaginationNext
                                                render={
                                                    <LinkButton
                                                        href={bookings.links[bookings.links.length - 1].url!}
                                                        variant="ghost"
                                                        size="default"
                                                        preserveState
                                                        preserveScroll
                                                    />
                                                }
                                            />
                                        ) : (
                                            <PaginationNext
                                                className="pointer-events-none opacity-50"
                                            />
                                        )}
                                    </PaginationItem>
                                </PaginationContent>
                            </Pagination>
                        </FrameFooter>
                    )}
                </Frame>
            </div>

            <BookingDetailSheet
                booking={selectedBooking}
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                timezone={timezone}
            />

            <ManualBookingDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                services={services}
                timezone={timezone}
            />
        </AuthenticatedLayout>
    );
}
