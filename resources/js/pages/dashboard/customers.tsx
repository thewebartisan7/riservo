import { useState, useEffect } from 'react';
import { router, usePage } from '@inertiajs/react';
import {
    index as customersIndex,
    show as customerShow,
} from '@/actions/App/Http/Controllers/Dashboard/CustomerController';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { useTrans } from '@/hooks/use-trans';
import { Field, FieldLabel } from '@/components/ui/field';
import { Frame, FrameFooter } from '@/components/ui/frame';
import { Input } from '@/components/ui/input';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
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
import { formatDateMedium } from '@/lib/datetime-format';
import { SearchIcon } from 'lucide-react';
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
        <AuthenticatedLayout
            title={t('Customers')}
            eyebrow={t('Customers')}
            heading={t('Your regulars')}
            description={t(
                'Search the people behind your bookings. Open any row to see their visit history.',
            )}
        >
            <div className="flex flex-col gap-5">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <Field className="w-full max-w-sm">
                        <FieldLabel className="text-[10px] uppercase tracking-[0.18em] text-muted-foreground">
                            {t('Search')}
                        </FieldLabel>
                        <InputGroup>
                            <InputGroupAddon align="inline-start">
                                <SearchIcon className="size-4 text-muted-foreground" />
                            </InputGroupAddon>
                            <InputGroupInput
                                placeholder={t('Name, email, or phone')}
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </InputGroup>
                    </Field>
                    <p className="pb-2 text-[11px] uppercase tracking-[0.22em] text-muted-foreground">
                        <span className="font-display text-foreground">
                            {customers.total}
                        </span>
                        <span className="mx-2 text-rule-strong">·</span>
                        {customers.total === 1 ? t('customer') : t('customers')}
                    </p>
                </div>

                <Frame className="w-full">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('Name')}</TableHead>
                                <TableHead>{t('Email')}</TableHead>
                                <TableHead>{t('Phone')}</TableHead>
                                <TableHead className="text-right">{t('Visits')}</TableHead>
                                <TableHead>{t('Last visit')}</TableHead>
                                <TableHead className="w-0" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {customers.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="py-10 text-center"
                                    >
                                        <div className="flex flex-col items-center gap-1">
                                            <p className="text-sm text-foreground">
                                                {t('No one matches that yet.')}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {search
                                                    ? t('Try a different spelling or email.')
                                                    : t('Customers appear here after their first booking.')}
                                            </p>
                                        </div>
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
                                        <TableCell className="text-right font-display tabular-nums text-sm">
                                            {customer.bookings_count}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground text-sm">
                                            {formatDateMedium(customer.last_booking_at)}
                                        </TableCell>
                                        <TableCell className="w-0">
                                            <LinkButton
                                                href={customerShow.url(customer.id)}
                                                variant="ghost"
                                                size="sm"
                                                onClick={(e) => e.stopPropagation()}
                                            >
                                                {t('Open')}
                                            </LinkButton>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                    {customers.last_page > 1 && (
                        <FrameFooter className="flex items-center justify-between px-4 py-3">
                            <span className="text-xs text-muted-foreground">
                                {t('Showing :from–:end of :total', {
                                    from: (customers.current_page - 1) * customers.per_page + 1,
                                    end: Math.min(
                                        customers.current_page * customers.per_page,
                                        customers.total,
                                    ),
                                    total: customers.total,
                                })}
                            </span>
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
                                            <PaginationPrevious className="pointer-events-none opacity-50" />
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
                                            <PaginationNext className="pointer-events-none opacity-50" />
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
