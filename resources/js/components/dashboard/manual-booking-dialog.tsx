import { useState, useEffect, useCallback } from 'react';
import { router, useHttp } from '@inertiajs/react';
import {
    store as storeBooking,
    availableDates as availableDatesAction,
    slots as slotsAction,
} from '@/actions/App/Http/Controllers/Dashboard/BookingController';
import { search as customerSearchAction } from '@/actions/App/Http/Controllers/Dashboard/CustomerController';
import { useTrans } from '@/hooks/use-trans';
import type { AvailableDatesResponse, AvailableSlotsResponse } from '@/types';
import {
    Dialog,
    DialogPopup,
    DialogPanel,
    DialogHeader,
    DialogTitle,
    DialogFooter,
    DialogClose,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Calendar } from '@/components/ui/calendar';
import { Spinner } from '@/components/ui/spinner';
import { Field, FieldLabel } from '@/components/ui/field';
import { Display } from '@/components/ui/display';
import { formatDurationShort, formatPrice } from '@/lib/booking-format';
import type { FilterOption, ServiceWithProviders } from '@/types';

export interface ManualBookingDialogSeed {
    /** YYYY-MM-DD in the business timezone. */
    date?: string;
    /** HH:mm in the business timezone (only if the user clicked a time-grid cell). */
    time?: string;
    /** Future extension (per-provider column view — D-102). */
    providerId?: number;
}

interface ManualBookingDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    services: ServiceWithProviders[];
    timezone: string;
    /**
     * Seed values for click-to-create (D-102). When provided, the dialog
     * pre-populates the date / time / provider so the user only fills
     * customer + service + confirm. When undefined (header "New booking"
     * button), the dialog behaves as before — no pre-population.
     */
    initial?: ManualBookingDialogSeed;
}

interface CustomerResult {
    id: number;
    name: string;
    email: string;
    phone: string | null;
}

type Step = 'customer' | 'service' | 'provider' | 'datetime' | 'confirm';

const STEP_LABELS: Record<Step, string> = {
    customer: 'Customer',
    service: 'Service',
    provider: 'With',
    datetime: 'When',
    confirm: 'Review',
};

export default function ManualBookingDialog({
    open,
    onOpenChange,
    services,
    timezone: _timezone,
    initial,
}: ManualBookingDialogProps) {
    const { t } = useTrans();
    const [step, setStep] = useState<Step>('customer');

    const [customerName, setCustomerName] = useState('');
    const [customerEmail, setCustomerEmail] = useState('');
    const [customerPhone, setCustomerPhone] = useState('');
    const [searchQuery, setSearchQuery] = useState('');
    const [searchResults, setSearchResults] = useState<CustomerResult[]>([]);
    const [isNewCustomer, setIsNewCustomer] = useState(false);
    const customerSearch = useHttp({});

    const [selectedService, setSelectedService] = useState<ServiceWithProviders | null>(null);
    const [selectedProvider, setSelectedProvider] = useState<FilterOption | null>(null);

    const [selectedDate, setSelectedDate] = useState<Date | undefined>();
    const [selectedTime, setSelectedTime] = useState<string | null>(null);
    const [availableDates, setAvailableDates] = useState<Record<string, boolean>>({});
    const [availableSlots, setAvailableSlots] = useState<string[]>([]);
    const datesHttp = useHttp({});
    const slotsHttp = useHttp({});

    const [notes, setNotes] = useState('');

    useEffect(() => {
        if (open) {
            setStep('customer');
            setCustomerName('');
            setCustomerEmail('');
            setCustomerPhone('');
            setSearchQuery('');
            setSearchResults([]);
            setIsNewCustomer(false);
            setSelectedService(null);
            setSelectedProvider(null);
            // Seed date / time from the click-to-create origin when present.
            // Parsing local YYYY-MM-DD → Date without timezone drift: split
            // and construct with numeric year/month/day (avoids UTC midnight).
            if (initial?.date) {
                const [y, m, d] = initial.date.split('-').map(Number);
                setSelectedDate(new Date(y, m - 1, d));
            } else {
                setSelectedDate(undefined);
            }
            setSelectedTime(initial?.time ?? null);
            setAvailableDates({});
            setAvailableSlots([]);
            setNotes('');
        }
        // intentionally read initial at open-transition time — later edits to
        // initial while the dialog is open would surprise the user.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const handleSearch = useCallback(
        (query: string) => {
            setSearchQuery(query);
            if (query.length < 2) {
                setSearchResults([]);
                return;
            }
            customerSearch.get(customerSearchAction.url({ query: { q: query } }), {
                onSuccess: (resp: unknown) => {
                    const data = resp as { customers: CustomerResult[] };
                    setSearchResults(data.customers);
                },
            });
        },
        [customerSearch],
    );

    function selectCustomer(customer: CustomerResult) {
        setCustomerName(customer.name);
        setCustomerEmail(customer.email);
        setCustomerPhone(customer.phone ?? '');
        setSearchQuery('');
        setSearchResults([]);
        setIsNewCustomer(false);
    }

    function startNewCustomer() {
        setIsNewCustomer(true);
        setSearchResults([]);
        setSearchQuery('');
    }

    function loadDates(month: string) {
        if (!selectedService) return;
        const params: Record<string, string> = {
            service_id: String(selectedService.id),
            month,
        };
        if (selectedProvider) {
            params.provider_id = String(selectedProvider.id);
        }
        datesHttp.get(availableDatesAction.url({ query: params }), {
            onSuccess: (resp: unknown) => {
                const data = resp as AvailableDatesResponse;
                setAvailableDates(data.dates);
            },
        });
    }

    useEffect(() => {
        if (!selectedDate || !selectedService) return;
        const dateStr = selectedDate.toLocaleDateString('sv');
        const params: Record<string, string> = {
            service_id: String(selectedService.id),
            date: dateStr,
        };
        if (selectedProvider) {
            params.provider_id = String(selectedProvider.id);
        }
        slotsHttp.get(slotsAction.url({ query: params }), {
            onSuccess: (resp: unknown) => {
                const data = resp as AvailableSlotsResponse;
                setAvailableSlots(data.slots);
                // Preserve the currently-selected time (typically seeded by
                // click-to-create, or picked by the user on a previous step)
                // only if the server confirms it is actually available for
                // this date + service + provider combination. If not, clear
                // it so the user picks a real slot. This keeps the
                // click-to-create seed honoured across the service / provider
                // steps and still rejects stale selections after a switch.
                setSelectedTime((current) =>
                    current && data.slots.includes(current) ? current : null,
                );
            },
        });
    }, [selectedDate, selectedService, selectedProvider]);

    function handleSubmit() {
        if (!selectedService || !selectedDate || !selectedTime) return;
        const dateStr = selectedDate.toLocaleDateString('sv');
        router.post(
            storeBooking.url(),
            {
                customer_name: customerName,
                customer_email: customerEmail,
                customer_phone: customerPhone || null,
                service_id: selectedService.id,
                provider_id: selectedProvider?.id ?? null,
                date: dateStr,
                time: selectedTime,
                notes: notes || null,
            },
            {
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    const canProceedCustomer = customerName.trim() && customerEmail.trim();
    const canProceedService = !!selectedService;
    const canProceedDateTime = !!selectedDate && !!selectedTime;

    const steps: Step[] = ['customer', 'service', 'provider', 'datetime', 'confirm'];
    const stepIndex = steps.indexOf(step);
    const currentLabel = t(STEP_LABELS[step]);

    function goBack() {
        if (stepIndex > 0) setStep(steps[stepIndex - 1]);
    }

    function goNext() {
        if (step === 'customer' && canProceedCustomer) setStep('service');
        else if (step === 'service' && canProceedService) {
            const serviceProviders = selectedService!.providers;
            if (serviceProviders.length === 1) {
                setSelectedProvider(serviceProviders[0]);
                setStep('datetime');
            } else {
                setStep('provider');
            }
        } else if (step === 'provider') {
            setStep('datetime');
        } else if (step === 'datetime' && canProceedDateTime) setStep('confirm');
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogPopup className="sm:max-w-lg">
                <DialogHeader className="gap-3">
                    <div className="flex items-center justify-between gap-4">
                        <div className="tabular-nums text-[11px] uppercase tracking-[0.22em] text-muted-foreground">
                            <span className="text-foreground">
                                {String(stepIndex + 1).padStart(2, '0')}
                            </span>
                            <span className="mx-1.5 text-rule-strong">/</span>
                            <span>{String(steps.length).padStart(2, '0')}</span>
                            <span className="mx-3 text-rule-strong" aria-hidden="true">
                                ·
                            </span>
                            <span>{currentLabel}</span>
                        </div>
                    </div>
                    <DialogTitle className="font-display">
                        {t('New booking')}
                    </DialogTitle>
                    <div
                        className="relative h-px w-full overflow-hidden bg-border"
                        role="progressbar"
                        aria-valuenow={stepIndex + 1}
                        aria-valuemin={1}
                        aria-valuemax={steps.length}
                    >
                        <div
                            className="absolute inset-y-0 left-0 bg-primary transition-[width] duration-500 ease-[cubic-bezier(0.2,0.8,0.2,1)]"
                            style={{
                                width: `${((stepIndex + 1) / steps.length) * 100}%`,
                            }}
                        />
                    </div>
                </DialogHeader>

                <DialogPanel className="min-h-[320px]">
                    {step === 'customer' && (
                        <div className="flex flex-col gap-4">
                            {!isNewCustomer && !customerEmail && (
                                <>
                                    <Field>
                                        <FieldLabel>{t('Find a customer')}</FieldLabel>
                                        <Input
                                            placeholder={t('Search by name, email, or phone…')}
                                            value={searchQuery}
                                            onChange={(e) => handleSearch(e.target.value)}
                                            autoFocus
                                        />
                                    </Field>
                                    {customerSearch.processing && (
                                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                            <Spinner className="size-3.5" />
                                            {t('Searching…')}
                                        </div>
                                    )}
                                    {searchResults.length > 0 && (
                                        <ul className="flex max-h-56 flex-col divide-y divide-border/70 overflow-y-auto rounded-lg border border-border">
                                            {searchResults.map((c) => (
                                                <li key={c.id}>
                                                    <button
                                                        type="button"
                                                        className="flex w-full flex-col gap-0.5 px-3 py-2.5 text-left transition-colors hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none"
                                                        onClick={() => selectCustomer(c)}
                                                    >
                                                        <span className="text-sm font-medium">
                                                            {c.name}
                                                        </span>
                                                        <span className="text-xs text-muted-foreground">
                                                            {c.email}
                                                            {c.phone && ` · ${c.phone}`}
                                                        </span>
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    <div className="flex items-center gap-3">
                                        <span
                                            className="h-px flex-1 bg-border"
                                            aria-hidden="true"
                                        />
                                        <span className="text-[10px] uppercase tracking-[0.22em] text-muted-foreground">
                                            {t('or')}
                                        </span>
                                        <span
                                            className="h-px flex-1 bg-border"
                                            aria-hidden="true"
                                        />
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={startNewCustomer}
                                    >
                                        {t('Create a new customer')}
                                    </Button>
                                </>
                            )}
                            {(isNewCustomer || customerEmail) && (
                                <div className="flex flex-col gap-4">
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <Field>
                                            <FieldLabel>{t('Name')}</FieldLabel>
                                            <Input
                                                value={customerName}
                                                onChange={(e) =>
                                                    setCustomerName(e.target.value)
                                                }
                                                autoFocus={isNewCustomer}
                                            />
                                        </Field>
                                        <Field>
                                            <FieldLabel>{t('Email')}</FieldLabel>
                                            <Input
                                                type="email"
                                                value={customerEmail}
                                                onChange={(e) =>
                                                    setCustomerEmail(e.target.value)
                                                }
                                            />
                                        </Field>
                                    </div>
                                    <Field>
                                        <FieldLabel>
                                            {t('Phone (optional)')}
                                        </FieldLabel>
                                        <Input
                                            value={customerPhone}
                                            onChange={(e) =>
                                                setCustomerPhone(e.target.value)
                                            }
                                            placeholder="+41 79 000 00 00"
                                        />
                                    </Field>
                                    {customerEmail && !isNewCustomer && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="self-start text-muted-foreground"
                                            onClick={() => {
                                                setCustomerName('');
                                                setCustomerEmail('');
                                                setCustomerPhone('');
                                                setIsNewCustomer(false);
                                            }}
                                        >
                                            ← {t('Search again')}
                                        </Button>
                                    )}
                                </div>
                            )}
                        </div>
                    )}

                    {step === 'service' && (
                        <div className="flex flex-col gap-2">
                            <ul className="flex flex-col gap-2">
                                {services.map((service) => {
                                    const isSelected = selectedService?.id === service.id;
                                    return (
                                        <li key={service.id}>
                                            <button
                                                type="button"
                                                aria-pressed={isSelected}
                                                className="flex w-full items-center justify-between gap-4 rounded-lg border border-border bg-card px-4 py-3 text-left transition-colors hover:bg-muted/40 aria-pressed:border-primary aria-pressed:bg-honey-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                                onClick={() => setSelectedService(service)}
                                            >
                                                <div className="flex min-w-0 flex-col gap-0.5">
                                                    <span className="text-sm font-medium text-foreground">
                                                        {service.name}
                                                    </span>
                                                    <span className="text-xs text-muted-foreground">
                                                        {formatDurationShort(
                                                            service.duration_minutes,
                                                            t,
                                                        )}
                                                    </span>
                                                </div>
                                                {service.price !== null && (
                                                    <span className="tabular-nums text-sm font-medium text-foreground">
                                                        {formatPrice(service.price, t)}
                                                    </span>
                                                )}
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    )}

                    {step === 'provider' && selectedService && (
                        <div className="flex flex-col gap-2">
                            <ul className="flex flex-col gap-2">
                                <li>
                                    <button
                                        type="button"
                                        aria-pressed={!selectedProvider}
                                        className="flex w-full items-center justify-between gap-4 rounded-lg border border-border bg-card px-4 py-3 text-left transition-colors hover:bg-muted/40 aria-pressed:border-primary aria-pressed:bg-honey-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                        onClick={() => setSelectedProvider(null)}
                                    >
                                        <span className="text-sm font-medium">
                                            {t('Auto-assign')}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {t('Pick any available')}
                                        </span>
                                    </button>
                                </li>
                                {selectedService.providers.map((provider) => (
                                    <li key={provider.id}>
                                        <button
                                            type="button"
                                            aria-pressed={selectedProvider?.id === provider.id}
                                            className="flex w-full items-center justify-between gap-4 rounded-lg border border-border bg-card px-4 py-3 text-left transition-colors hover:bg-muted/40 aria-pressed:border-primary aria-pressed:bg-honey-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                            onClick={() => setSelectedProvider(provider)}
                                        >
                                            <span className="text-sm font-medium">
                                                {provider.name}
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {step === 'datetime' && selectedService && (
                        <div className="flex flex-col gap-4 sm:flex-row sm:gap-6">
                            <Calendar
                                mode="single"
                                selected={selectedDate}
                                onSelect={(date) => setSelectedDate(date ?? undefined)}
                                disabled={(date) => {
                                    const key = date.toLocaleDateString('sv');
                                    return (
                                        date < new Date(new Date().setHours(0, 0, 0, 0)) ||
                                        availableDates[key] === false
                                    );
                                }}
                                onMonthChange={(month) => {
                                    const m = `${month.getFullYear()}-${String(month.getMonth() + 1).padStart(2, '0')}`;
                                    loadDates(m);
                                }}
                                defaultMonth={new Date()}
                                className="self-start rounded-lg border"
                            />
                            <div className="flex min-h-48 flex-1 flex-col gap-2">
                                <span className="text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                                    {t('Available times')}
                                </span>
                                {!selectedDate && (
                                    <p className="text-sm text-muted-foreground">
                                        {t('Pick a date to see what opens up.')}
                                    </p>
                                )}
                                {selectedDate && slotsHttp.processing && (
                                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                        <Spinner className="size-3.5" />
                                        {t('Checking availability…')}
                                    </div>
                                )}
                                {selectedDate &&
                                    !slotsHttp.processing &&
                                    availableSlots.length === 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            {t('Nothing free on this date.')}
                                        </p>
                                    )}
                                {selectedDate &&
                                    !slotsHttp.processing &&
                                    availableSlots.length > 0 && (
                                        <div className="grid max-h-60 grid-cols-3 gap-1.5 overflow-y-auto pe-1">
                                            {availableSlots.map((slot) => {
                                                const isSelected = selectedTime === slot;
                                                return (
                                                    <button
                                                        key={slot}
                                                        type="button"
                                                        aria-pressed={isSelected}
                                                        className="rounded-md border border-border bg-background px-2 py-1.5 text-center font-display tabular-nums text-sm text-foreground transition-colors hover:bg-muted/60 aria-pressed:border-primary aria-pressed:bg-primary aria-pressed:text-primary-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                                        onClick={() => setSelectedTime(slot)}
                                                    >
                                                        {slot}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    )}
                            </div>
                        </div>
                    )}

                    {step === 'confirm' && selectedService && (
                        <div className="flex flex-col gap-5">
                            <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-3 rounded-lg border border-border bg-honey-soft/50 px-4 py-4 text-sm">
                                <dt className="text-muted-foreground">{t('Customer')}</dt>
                                <dd className="text-foreground">
                                    <p className="font-medium">{customerName}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {customerEmail}
                                    </p>
                                </dd>

                                <dt className="text-muted-foreground">{t('Service')}</dt>
                                <dd className="text-foreground">
                                    <p className="font-medium">{selectedService.name}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {formatDurationShort(
                                            selectedService.duration_minutes,
                                            t,
                                        )}
                                        {selectedService.price !== null &&
                                            selectedService.price > 0 && (
                                                <>
                                                    {' · '}
                                                    {formatPrice(selectedService.price, t)}
                                                </>
                                            )}
                                    </p>
                                </dd>

                                <dt className="text-muted-foreground">{t('With')}</dt>
                                <dd className="text-foreground">
                                    {selectedProvider?.name ?? t('Auto-assign')}
                                </dd>

                                <dt className="text-muted-foreground">{t('When')}</dt>
                                <dd className="font-display tabular-nums text-foreground">
                                    {selectedDate?.toLocaleDateString([], {
                                        dateStyle: 'medium',
                                    })}
                                    {' · '}
                                    {selectedTime}
                                </dd>
                            </dl>

                            <Field>
                                <FieldLabel>{t('Note (optional)')}</FieldLabel>
                                <Textarea
                                    value={notes}
                                    onChange={(e) => setNotes(e.target.value)}
                                    rows={2}
                                    placeholder={t(
                                        'Anything the customer should see on their confirmation…',
                                    )}
                                />
                            </Field>
                        </div>
                    )}
                </DialogPanel>

                <DialogFooter className="gap-2">
                    {stepIndex > 0 && (
                        <Button variant="ghost" onClick={goBack}>
                            ← {t('Back')}
                        </Button>
                    )}
                    {stepIndex === 0 && (
                        <DialogClose render={<Button variant="ghost" />}>
                            {t('Cancel')}
                        </DialogClose>
                    )}
                    {step !== 'confirm' ? (
                        <Button
                            onClick={goNext}
                            disabled={
                                (step === 'customer' && !canProceedCustomer) ||
                                (step === 'service' && !canProceedService) ||
                                (step === 'datetime' && !canProceedDateTime)
                            }
                        >
                            {t('Continue')}
                        </Button>
                    ) : (
                        <Button onClick={handleSubmit}>
                            <Display className="tracking-tight">
                                {t('Create booking')}
                            </Display>
                        </Button>
                    )}
                </DialogFooter>
            </DialogPopup>
        </Dialog>
    );
}
