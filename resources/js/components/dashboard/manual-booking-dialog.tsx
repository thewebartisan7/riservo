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
    DialogDescription,
    DialogFooter,
    DialogClose,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Calendar } from '@/components/ui/calendar';
import { Spinner } from '@/components/ui/spinner';
import type { FilterOption, ServiceWithCollaborators } from '@/types';

interface ManualBookingDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    services: ServiceWithCollaborators[];
    timezone: string;
}

interface CustomerResult {
    id: number;
    name: string;
    email: string;
    phone: string | null;
}

type Step = 'customer' | 'service' | 'collaborator' | 'datetime' | 'confirm';

export default function ManualBookingDialog({
    open,
    onOpenChange,
    services,
    timezone,
}: ManualBookingDialogProps) {
    const { t } = useTrans();
    const [step, setStep] = useState<Step>('customer');

    // Customer step
    const [customerName, setCustomerName] = useState('');
    const [customerEmail, setCustomerEmail] = useState('');
    const [customerPhone, setCustomerPhone] = useState('');
    const [searchQuery, setSearchQuery] = useState('');
    const [searchResults, setSearchResults] = useState<CustomerResult[]>([]);
    const [isNewCustomer, setIsNewCustomer] = useState(false);
    const customerSearch = useHttp({});

    // Service step
    const [selectedService, setSelectedService] = useState<ServiceWithCollaborators | null>(null);

    // Collaborator step
    const [selectedCollaborator, setSelectedCollaborator] = useState<FilterOption | null>(null);

    // DateTime step
    const [selectedDate, setSelectedDate] = useState<Date | undefined>();
    const [selectedTime, setSelectedTime] = useState<string | null>(null);
    const [availableDates, setAvailableDates] = useState<Record<string, boolean>>({});
    const [availableSlots, setAvailableSlots] = useState<string[]>([]);
    const datesHttp = useHttp({});
    const slotsHttp = useHttp({});

    // Notes
    const [notes, setNotes] = useState('');

    // Reset when dialog opens/closes
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
            setSelectedCollaborator(null);
            setSelectedDate(undefined);
            setSelectedTime(null);
            setAvailableDates({});
            setAvailableSlots([]);
            setNotes('');
        }
    }, [open]);

    // Customer search
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

    // Load available dates when service/collaborator changes
    function loadDates(month: string) {
        if (!selectedService) return;
        const params: Record<string, string> = {
            service_id: String(selectedService.id),
            month,
        };
        if (selectedCollaborator) {
            params.collaborator_id = String(selectedCollaborator.id);
        }
        datesHttp.get(availableDatesAction.url({ query: params }), {
            onSuccess: (resp: unknown) => {
                const data = resp as AvailableDatesResponse;
                setAvailableDates(data.dates);
            },
        });
    }

    // Load slots when date changes
    useEffect(() => {
        if (!selectedDate || !selectedService) return;
        setSelectedTime(null);
        const dateStr = selectedDate.toLocaleDateString('sv'); // YYYY-MM-DD
        const params: Record<string, string> = {
            service_id: String(selectedService.id),
            date: dateStr,
        };
        if (selectedCollaborator) {
            params.collaborator_id = String(selectedCollaborator.id);
        }
        slotsHttp.get(slotsAction.url({ query: params }), {
            onSuccess: (resp: unknown) => {
                const data = resp as AvailableSlotsResponse;
                setAvailableSlots(data.slots);
            },
        });
    }, [selectedDate, selectedService, selectedCollaborator]);

    // Submit
    function handleSubmit() {
        if (!selectedService || !selectedDate || !selectedTime) return;
        const dateStr = selectedDate.toLocaleDateString('sv');
        router.post(storeBooking.url(), {
            customer_name: customerName,
            customer_email: customerEmail,
            customer_phone: customerPhone || null,
            service_id: selectedService.id,
            collaborator_id: selectedCollaborator?.id ?? null,
            date: dateStr,
            time: selectedTime,
            notes: notes || null,
        }, {
            onSuccess: () => onOpenChange(false),
        });
    }

    const canProceedCustomer = customerName.trim() && customerEmail.trim();
    const canProceedService = !!selectedService;
    const canProceedDateTime = !!selectedDate && !!selectedTime;

    const steps: Step[] = ['customer', 'service', 'collaborator', 'datetime', 'confirm'];
    const stepIndex = steps.indexOf(step);

    function goBack() {
        if (stepIndex > 0) setStep(steps[stepIndex - 1]);
    }

    function goNext() {
        if (step === 'customer' && canProceedCustomer) setStep('service');
        else if (step === 'service' && canProceedService) {
            // If the service has only one collaborator, auto-select
            const serviceCollaborators = selectedService!.collaborators;
            if (serviceCollaborators.length === 1) {
                setSelectedCollaborator(serviceCollaborators[0]);
                setStep('datetime');
            } else {
                setStep('collaborator');
            }
        }
        else if (step === 'collaborator') {
            setStep('datetime');
        }
        else if (step === 'datetime' && canProceedDateTime) setStep('confirm');
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogPopup className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('New Booking')}</DialogTitle>
                    <DialogDescription>
                        {t('Step :current of :total', {
                            current: String(stepIndex + 1),
                            total: String(steps.length),
                        })}
                    </DialogDescription>
                </DialogHeader>

                <DialogPanel className="min-h-[300px]">
                    {/* Step 1: Customer */}
                    {step === 'customer' && (
                        <div className="space-y-3">
                            <h3 className="text-sm font-medium">{t('Customer')}</h3>
                            {!isNewCustomer && !customerEmail && (
                                <>
                                    <Input
                                        placeholder={t('Search by name, email, or phone...')}
                                        value={searchQuery}
                                        onChange={(e) => handleSearch(e.target.value)}
                                    />
                                    {searchResults.length > 0 && (
                                        <div className="max-h-40 space-y-1 overflow-y-auto rounded-md border p-2">
                                            {searchResults.map((c) => (
                                                <button
                                                    key={c.id}
                                                    className="hover:bg-muted w-full rounded px-2 py-1.5 text-left text-sm"
                                                    onClick={() => selectCustomer(c)}
                                                >
                                                    <span className="font-medium">{c.name}</span>
                                                    <span className="text-muted-foreground ml-2">
                                                        {c.email}
                                                    </span>
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                    {customerSearch.processing && <Spinner className="size-4" />}
                                    <Button variant="outline" size="sm" onClick={startNewCustomer}>
                                        {t('New Customer')}
                                    </Button>
                                </>
                            )}
                            {(isNewCustomer || customerEmail) && (
                                <div className="space-y-2">
                                    <Input
                                        placeholder={t('Name')}
                                        value={customerName}
                                        onChange={(e) => setCustomerName(e.target.value)}
                                    />
                                    <Input
                                        type="email"
                                        placeholder={t('Email')}
                                        value={customerEmail}
                                        onChange={(e) => setCustomerEmail(e.target.value)}
                                    />
                                    <Input
                                        placeholder={t('Phone (optional)')}
                                        value={customerPhone}
                                        onChange={(e) => setCustomerPhone(e.target.value)}
                                    />
                                    {customerEmail && !isNewCustomer && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => {
                                                setCustomerName('');
                                                setCustomerEmail('');
                                                setCustomerPhone('');
                                                setIsNewCustomer(false);
                                            }}
                                        >
                                            {t('Search again')}
                                        </Button>
                                    )}
                                </div>
                            )}
                        </div>
                    )}

                    {/* Step 2: Service */}
                    {step === 'service' && (
                        <div className="space-y-3">
                            <h3 className="text-sm font-medium">{t('Select Service')}</h3>
                            <div className="space-y-2">
                                {services.map((service) => (
                                    <button
                                        key={service.id}
                                        className={`w-full rounded-md border p-3 text-left transition ${
                                            selectedService?.id === service.id
                                                ? 'border-primary bg-primary/5'
                                                : 'hover:bg-muted'
                                        }`}
                                        onClick={() => setSelectedService(service)}
                                    >
                                        <span className="text-sm font-medium">
                                            {service.name}
                                        </span>
                                        <span className="text-muted-foreground ml-2 text-xs">
                                            {service.duration_minutes} {t('min')}
                                            {service.price !== null && service.price > 0 && (
                                                <> &middot; CHF {service.price}</>
                                            )}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Step 3: Collaborator */}
                    {step === 'collaborator' && selectedService && (
                        <div className="space-y-3">
                            <h3 className="text-sm font-medium">{t('Select Collaborator')}</h3>
                            <div className="space-y-2">
                                <button
                                    className={`w-full rounded-md border p-3 text-left transition ${
                                        !selectedCollaborator
                                            ? 'border-primary bg-primary/5'
                                            : 'hover:bg-muted'
                                    }`}
                                    onClick={() => setSelectedCollaborator(null)}
                                >
                                    <span className="text-sm font-medium">
                                        {t('Auto-assign')}
                                    </span>
                                </button>
                                {selectedService.collaborators.map((collab) => (
                                    <button
                                        key={collab.id}
                                        className={`w-full rounded-md border p-3 text-left transition ${
                                            selectedCollaborator?.id === collab.id
                                                ? 'border-primary bg-primary/5'
                                                : 'hover:bg-muted'
                                        }`}
                                        onClick={() => setSelectedCollaborator(collab)}
                                    >
                                        <span className="text-sm font-medium">
                                            {collab.name}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Step 4: Date & Time */}
                    {step === 'datetime' && selectedService && (
                        <div className="space-y-3">
                            <h3 className="text-sm font-medium">{t('Select Date & Time')}</h3>
                            <div className="flex flex-col gap-4 sm:flex-row">
                                <Calendar
                                    mode="single"
                                    selected={selectedDate}
                                    onSelect={(date) => setSelectedDate(date ?? undefined)}
                                    disabled={(date) => {
                                        const key = date.toLocaleDateString('sv');
                                        return date < new Date(new Date().setHours(0, 0, 0, 0)) ||
                                            availableDates[key] === false;
                                    }}
                                    onMonthChange={(month) => {
                                        const m = `${month.getFullYear()}-${String(month.getMonth() + 1).padStart(2, '0')}`;
                                        loadDates(m);
                                    }}
                                    defaultMonth={new Date()}
                                    className="rounded-md border"
                                />
                                <div className="flex-1">
                                    {!selectedDate && (
                                        <p className="text-muted-foreground text-sm">
                                            {t('Select a date to see available times.')}
                                        </p>
                                    )}
                                    {selectedDate && slotsHttp.processing && (
                                        <div className="flex justify-center py-4">
                                            <Spinner className="size-5" />
                                        </div>
                                    )}
                                    {selectedDate && !slotsHttp.processing && availableSlots.length === 0 && (
                                        <p className="text-muted-foreground text-sm">
                                            {t('No available times for this date.')}
                                        </p>
                                    )}
                                    {selectedDate && !slotsHttp.processing && availableSlots.length > 0 && (
                                        <div className="grid max-h-48 grid-cols-3 gap-1.5 overflow-y-auto">
                                            {availableSlots.map((slot) => (
                                                <button
                                                    key={slot}
                                                    className={`rounded border px-2 py-1.5 text-center text-sm transition ${
                                                        selectedTime === slot
                                                            ? 'border-primary bg-primary text-primary-foreground'
                                                            : 'hover:bg-muted'
                                                    }`}
                                                    onClick={() => setSelectedTime(slot)}
                                                >
                                                    {slot}
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Step 5: Confirm */}
                    {step === 'confirm' && selectedService && (
                        <div className="space-y-4">
                            <h3 className="text-sm font-medium">{t('Confirm Booking')}</h3>
                            <div className="space-y-2 rounded-md border p-3 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">{t('Customer')}</span>
                                    <span>{customerName} ({customerEmail})</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">{t('Service')}</span>
                                    <span>{selectedService.name}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">{t('Collaborator')}</span>
                                    <span>
                                        {selectedCollaborator?.name ?? t('Auto-assign')}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">{t('Date & Time')}</span>
                                    <span>
                                        {selectedDate?.toLocaleDateString([], {
                                            dateStyle: 'medium',
                                        })}{' '}
                                        {selectedTime}
                                    </span>
                                </div>
                            </div>
                            <Textarea
                                placeholder={t('Notes (optional)')}
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                rows={2}
                            />
                        </div>
                    )}
                </DialogPanel>

                <DialogFooter className="gap-2">
                    {stepIndex > 0 && (
                        <Button variant="outline" onClick={goBack}>
                            {t('Back')}
                        </Button>
                    )}
                    {stepIndex === 0 && (
                        <DialogClose render={<Button variant="outline" />}>
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
                            {t('Next')}
                        </Button>
                    ) : (
                        <Button onClick={handleSubmit}>
                            {t('Create Booking')}
                        </Button>
                    )}
                </DialogFooter>
            </DialogPopup>
        </Dialog>
    );
}
