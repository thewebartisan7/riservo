import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { index as bookingsIndex } from '@/actions/App/Http/Controllers/Dashboard/BookingController';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import BookingDetailSheet from '@/components/dashboard/booking-detail-sheet';
import ManualBookingDialog from '@/components/dashboard/manual-booking-dialog';
import { BookingStatusBadge, BookingSourceBadge } from '@/components/dashboard/booking-status-badge';
import { useTrans } from '@/hooks/use-trans';
import { CalendarIcon, PlusIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Field, FieldLabel } from '@/components/ui/field';
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
import { formatDateTimeShort } from '@/lib/datetime-format';
import type { DashboardBooking, FilterOption, PageProps, ServiceWithProviders } from '@/types';

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
    services: ServiceWithProviders[];
    providers: FilterOption[];
    filters: {
        status: string;
        service_id: string;
        provider_id: string;
        date_from: string;
        date_to: string;
        sort: string;
        direction: string;
    };
    isAdmin: boolean;
    timezone: string;
}

function parseFilterDate(value: string): Date | undefined {
    if (!value) return undefined;
    const [y, m, d] = value.split('-').map(Number);
    return new Date(y, m - 1, d);
}

function toFilterString(date: Date): string {
    return date.toLocaleDateString('sv');
}

function formatShortDate(date: Date): string {
    return date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
}

export default function BookingsPage() {
    const { bookings, services, providers, filters, isAdmin, timezone } =
        usePage<BookingsPageProps>().props;
    const { t } = useTrans();
    const [selectedBooking, setSelectedBooking] = useState<DashboardBooking | null>(null);
    const [sheetOpen, setSheetOpen] = useState(false);
    const [dialogOpen, setDialogOpen] = useState(false);

    function applyFilter(key: string, value: string) {
        const params = { ...filters, [key]: value };
        const cleaned = Object.fromEntries(
            Object.entries(params).filter(([, v]) => v !== ''),
        );
        router.get(bookingsIndex.url(), cleaned, {
            preserveState: true,
            preserveScroll: true,
            only: ['bookings'],
        });
    }

    function openDetail(booking: DashboardBooking) {
        setSelectedBooking(booking);
        setSheetOpen(true);
    }

    const statuses = [
        { value: '', label: t('All statuses') },
        { value: 'pending', label: t('Pending') },
        { value: 'confirmed', label: t('Confirmed') },
        { value: 'cancelled', label: t('Cancelled') },
        { value: 'completed', label: t('Completed') },
        { value: 'no_show', label: t('No Show') },
    ];

    const hasActiveFilters =
        filters.date_from !== '' ||
        filters.date_to !== '' ||
        filters.status !== '' ||
        filters.service_id !== '' ||
        filters.provider_id !== '';

    function clearFilters() {
        router.get(
            bookingsIndex.url(),
            {},
            { preserveState: true, preserveScroll: true, only: ['bookings'] },
        );
    }

    return (
        <AuthenticatedLayout
            title={t('Bookings')}
            eyebrow={t('Bookings')}
            heading={t('All appointments')}
            description={t('Filter, triage, and keep every appointment moving forward.')}
            actions={
                <Button onClick={() => setDialogOpen(true)}>
                    <PlusIcon />
                    {t('New booking')}
                </Button>
            }
        >
            <div className="flex flex-col gap-5">
                <div className="flex flex-wrap items-end gap-3">
                    <Field className="w-40">
                        <FieldLabel className="text-[10px] uppercase tracking-[0.18em] text-muted-foreground">
                            {t('From')}
                        </FieldLabel>
                        <Popover>
                            <PopoverTrigger
                                render={<Button className="w-full justify-start font-normal" variant="outline" />}
                            >
                                <CalendarIcon aria-hidden="true" />
                                {filters.date_from
                                    ? formatShortDate(parseFilterDate(filters.date_from)!)
                                    : <span className="text-muted-foreground">{t('Any date')}</span>}
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
                    </Field>
                    <Field className="w-40">
                        <FieldLabel className="text-[10px] uppercase tracking-[0.18em] text-muted-foreground">
                            {t('To')}
                        </FieldLabel>
                        <Popover>
                            <PopoverTrigger
                                render={<Button className="w-full justify-start font-normal" variant="outline" />}
                            >
                                <CalendarIcon aria-hidden="true" />
                                {filters.date_to
                                    ? formatShortDate(parseFilterDate(filters.date_to)!)
                                    : <span className="text-muted-foreground">{t('Any date')}</span>}
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
                    </Field>
                    <Field className="w-36">
                        <FieldLabel className="text-[10px] uppercase tracking-[0.18em] text-muted-foreground">
                            {t('Status')}
                        </FieldLabel>
                        <Select
                            value={filters.status}
                            onValueChange={(val) => applyFilter('status', val ?? '')}
                        >
                            <SelectTrigger className="w-full">
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
                    </Field>
                    <Field className="w-40">
                        <FieldLabel className="text-[10px] uppercase tracking-[0.18em] text-muted-foreground">
                            {t('Service')}
                        </FieldLabel>
                        <Select
                            value={filters.service_id}
                            onValueChange={(val) => applyFilter('service_id', val ?? '')}
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectPopup>
                                <SelectItem value="">{t('All services')}</SelectItem>
                                {services.map((s) => (
                                    <SelectItem key={s.id} value={String(s.id)}>
                                        {s.name}
                                    </SelectItem>
                                ))}
                            </SelectPopup>
                        </Select>
                    </Field>
                    {isAdmin && (
                        <Field className="w-40">
                            <FieldLabel className="text-[10px] uppercase tracking-[0.18em] text-muted-foreground">
                                {t('Provider')}
                            </FieldLabel>
                            <Select
                                value={filters.provider_id}
                                onValueChange={(val) => applyFilter('provider_id', val ?? '')}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectPopup>
                                    <SelectItem value="">
                                        {t('All providers')}
                                    </SelectItem>
                                    {providers.map((p) => (
                                        <SelectItem key={p.id} value={String(p.id)}>
                                            {p.name}
                                        </SelectItem>
                                    ))}
                                </SelectPopup>
                            </Select>
                        </Field>
                    )}
                    {hasActiveFilters && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={clearFilters}
                            className="text-muted-foreground"
                        >
                            {t('Clear filters')}
                        </Button>
                    )}
                </div>

                <Frame className="w-full">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-44">{t('When')}</TableHead>
                                <TableHead>{t('Customer')}</TableHead>
                                <TableHead>{t('Service')}</TableHead>
                                {isAdmin && <TableHead>{t('Provider')}</TableHead>}
                                <TableHead>{t('Status')}</TableHead>
                                <TableHead>{t('Source')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {bookings.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={isAdmin ? 6 : 5}
                                        className="py-10 text-center"
                                    >
                                        <div className="flex flex-col items-center gap-1">
                                            <p className="text-sm text-foreground">
                                                {t('Nothing to show here yet.')}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {hasActiveFilters
                                                    ? t('Try loosening the filters above.')
                                                    : t('New bookings will appear as they come in.')}
                                            </p>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ) : (
                                bookings.data.map((booking) => (
                                    <TableRow
                                        key={booking.id}
                                        className="cursor-pointer"
                                        onClick={() => openDetail(booking)}
                                    >
                                        <TableCell className="font-display tabular-nums text-sm">
                                            {formatDateTimeShort(booking.starts_at, timezone)}
                                        </TableCell>
                                        <TableCell>
                                            {booking.external ? (
                                                <div className="text-sm font-medium text-muted-foreground">
                                                    {booking.external_title ?? t('External event')}
                                                </div>
                                            ) : (
                                                <>
                                                    <div className="text-sm font-medium">
                                                        {booking.customer?.name ?? '—'}
                                                    </div>
                                                    <div className="text-muted-foreground text-xs">
                                                        {booking.customer?.email ?? ''}
                                                    </div>
                                                </>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {booking.service?.name ?? (booking.external ? t('External') : '—')}
                                        </TableCell>
                                        {isAdmin && (
                                            <TableCell className="text-muted-foreground text-sm">
                                                {booking.provider.is_active
                                                    ? booking.provider.name
                                                    : t(':name (deactivated)', { name: booking.provider.name })}
                                            </TableCell>
                                        )}
                                        <TableCell>
                                            <BookingStatusBadge status={booking.status} />
                                        </TableCell>
                                        <TableCell>
                                            <BookingSourceBadge source={booking.source} />
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                    {bookings.last_page > 1 && (
                        <FrameFooter className="flex items-center justify-between px-4 py-3">
                            <span className="text-xs text-muted-foreground">
                                {t('Showing :from–:end of :total', {
                                    from: (bookings.current_page - 1) * bookings.per_page + 1,
                                    end: Math.min(
                                        bookings.current_page * bookings.per_page,
                                        bookings.total,
                                    ),
                                    total: bookings.total,
                                })}
                            </span>
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
                                            <PaginationPrevious className="pointer-events-none opacity-50" />
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
                                            <PaginationNext className="pointer-events-none opacity-50" />
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
