import { useState, useEffect } from 'react';
import { router, usePage } from '@inertiajs/react';
import { index as customersIndex, show as customerShow } from '@/actions/App/Http/Controllers/Dashboard/CustomerController';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { useTrans } from '@/hooks/use-trans';
import { Frame, FrameFooter } from '@/components/ui/frame';
import { Input } from '@/components/ui/input';
import { LinkButton } from '@/components/ui/link-button';
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
import type { DashboardCustomer, PageProps } from '@/types';

interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface CustomersPageProps extends PageProps {
    customers: PaginatedData<DashboardCustomer>;
    filters: {
        search: string;
    };
}

function formatDate(isoString: string | null): string {
    if (!isoString) return '—';
    return new Date(isoString).toLocaleDateString([], { dateStyle: 'medium' });
}

export default function CustomersPage() {
    const { customers, filters } = usePage<CustomersPageProps>().props;
    const { t } = useTrans();
    const [search, setSearch] = useState(filters.search);

    useEffect(() => {
        const timer = setTimeout(() => {
            if (search !== filters.search) {
                router.get(
                    customersIndex.url(),
                    search ? { search } : {},
                    { preserveState: true, preserveScroll: true, only: ['customers'] },
                );
            }
        }, 300);
        return () => clearTimeout(timer);
    }, [search]);

    return (
        <AuthenticatedLayout title={t('Customers')}>
            <div className="space-y-4">
                {/* Search */}
                <div className="flex items-center gap-3">
                    <Input
                        placeholder={t('Search by name, email, or phone...')}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="max-w-sm"
                    />
                    <span className="text-muted-foreground text-sm">
                        {customers.total} {t('customers')}
                    </span>
                </div>

                {/* Table */}
                <Frame className="w-full">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('Name')}</TableHead>
                                <TableHead>{t('Email')}</TableHead>
                                <TableHead>{t('Phone')}</TableHead>
                                <TableHead className="text-right">
                                    {t('Bookings')}
                                </TableHead>
                                <TableHead>{t('Last Visit')}</TableHead>
                                <TableHead className="w-0" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {customers.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="text-muted-foreground py-8 text-center"
                                    >
                                        {t('No customers found.')}
                                    </TableCell>
                                </TableRow>
                            ) : (
                                customers.data.map((customer) => (
                                    <TableRow
                                        key={customer.id}
                                        className="cursor-pointer"
                                        onClick={() => router.visit(customerShow.url(customer.id))}
                                    >
                                        <TableCell className="text-sm font-medium">
                                            {customer.name}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground text-sm">
                                            {customer.email}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground text-sm">
                                            {customer.phone || '—'}
                                        </TableCell>
                                        <TableCell className="text-right text-sm">
                                            {customer.bookings_count}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground text-sm">
                                            {formatDate(customer.last_booking_at)}
                                        </TableCell>
                                        <TableCell>
                                            <LinkButton
                                                href={customerShow.url(customer.id)}
                                                variant="ghost"
                                                size="sm"
                                                onClick={(e) => e.stopPropagation()}
                                            >
                                                {t('View')}
                                            </LinkButton>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                    {customers.last_page > 1 && (
                        <FrameFooter className="px-2 py-3">
                            <Pagination>
                                <PaginationContent>
                                    <PaginationItem>
                                        {customers.links[0].url ? (
                                            <PaginationPrevious
                                                render={
                                                    <LinkButton
                                                        href={customers.links[0].url}
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
                                    {customers.links.slice(1, -1).map((link) => (
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
                                        {customers.links[customers.links.length - 1].url ? (
                                            <PaginationNext
                                                render={
                                                    <LinkButton
                                                        href={customers.links[customers.links.length - 1].url!}
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
        </AuthenticatedLayout>
    );
}
