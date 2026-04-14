import { useState, useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import BookingLayout from '@/layouts/booking-layout';
import ServiceList from '@/components/booking/service-list';
import CollaboratorPicker from '@/components/booking/collaborator-picker';
import DateTimePicker from '@/components/booking/date-time-picker';
import CustomerForm, { type CustomerData } from '@/components/booking/customer-form';
import BookingSummary from '@/components/booking/booking-summary';
import BookingConfirmation from '@/components/booking/booking-confirmation';
import StepIndicator from '@/components/booking/step-indicator';
import { useTrans } from '@/hooks/use-trans';
import type { PageProps, PublicBusiness, PublicCollaborator, PublicService } from '@/types';

type BookingStep = 'service' | 'collaborator' | 'datetime' | 'details' | 'summary' | 'confirmation';

interface BookingPageProps extends PageProps {
    business: PublicBusiness;
    services: PublicService[];
    preSelectedServiceSlug: string | null;
    customerPrefill: { name: string; email: string; phone: string | null } | null;
}

export default function BookingShow() {
    const { t } = useTrans();
    const { business, services, preSelectedServiceSlug, customerPrefill, embed } =
        usePage<BookingPageProps & { embed: boolean }>().props;

    // Auto-select service if pre-filtered via URL
    const preSelectedService = useMemo(() => {
        if (preSelectedServiceSlug) {
            return services.find((s) => s.slug === preSelectedServiceSlug) ?? null;
        }
        return null;
    }, [preSelectedServiceSlug, services]);

    const [step, setStep] = useState<BookingStep>(preSelectedService ? 'collaborator' : 'service');
    const [selectedService, setSelectedService] = useState<PublicService | null>(preSelectedService);
    const [selectedCollaborator, setSelectedCollaborator] = useState<PublicCollaborator | null>(null);
    const [selectedDate, setSelectedDate] = useState<string | null>(null);
    const [selectedTime, setSelectedTime] = useState<string | null>(null);
    const [customerData, setCustomerData] = useState<CustomerData>({
        name: customerPrefill?.name ?? '',
        email: customerPrefill?.email ?? '',
        phone: customerPrefill?.phone ?? '',
        notes: '',
    });
    const [bookingResult, setBookingResult] = useState<{ token: string; status: string } | null>(null);

    function handleServiceSelect(service: PublicService) {
        setSelectedService(service);
        if (business.allow_collaborator_choice) {
            setStep('collaborator');
        } else {
            setStep('datetime');
        }
    }

    function handleCollaboratorSelect(collaborator: PublicCollaborator | null) {
        setSelectedCollaborator(collaborator);
        setStep('datetime');
    }

    function handleDateTimeSelect(date: string, time: string) {
        setSelectedDate(date);
        setSelectedTime(time);
        setStep('details');
    }

    function handleCustomerSubmit(data: CustomerData) {
        setCustomerData(data);
        setStep('summary');
    }

    function handleBookingSuccess(result: { token: string; status: string }) {
        setBookingResult(result);
        setStep('confirmation');
    }

    function handleBookAnother() {
        setSelectedService(null);
        setSelectedCollaborator(null);
        setSelectedDate(null);
        setSelectedTime(null);
        setBookingResult(null);
        setStep('service');
    }

    function goBack() {
        switch (step) {
            case 'collaborator':
                setStep('service');
                break;
            case 'datetime':
                setStep(business.allow_collaborator_choice ? 'collaborator' : 'service');
                break;
            case 'details':
                setStep('datetime');
                break;
            case 'summary':
                setStep('details');
                break;
        }
    }

    const totalSteps = business.allow_collaborator_choice ? 5 : 4;
    const stepOrder: BookingStep[] = business.allow_collaborator_choice
        ? ['service', 'collaborator', 'datetime', 'details', 'summary', 'confirmation']
        : ['service', 'datetime', 'details', 'summary', 'confirmation'];
    const stepIndex = stepOrder.indexOf(step);
    const stepLabels: Record<BookingStep, string> = {
        service: t('Service'),
        collaborator: t('Specialist'),
        datetime: t('Date & time'),
        details: t('Your details'),
        summary: t('Review'),
        confirmation: t('Done'),
    };

    const showIndicator = step !== 'confirmation';
    const indicator = showIndicator ? (
        <StepIndicator
            current={Math.min(stepIndex + 1, totalSteps)}
            total={totalSteps}
            stepLabel={stepLabels[step]}
            onBack={step !== 'service' ? goBack : undefined}
        />
    ) : null;

    return (
        <BookingLayout
            title={`${t('Book')} · ${business.name}`}
            businessName={business.name}
            businessLogoUrl={business.logo_url}
            businessDescription={business.description}
            businessAddress={business.address}
            businessPhone={business.phone}
            businessTimezone={business.timezone}
            stepIndicator={indicator}
            embed={embed}
        >
            {step === 'service' && (
                <ServiceList services={services} onSelect={handleServiceSelect} />
            )}

            {step === 'collaborator' && selectedService && (
                <CollaboratorPicker
                    slug={business.slug}
                    serviceId={selectedService.id}
                    onSelect={handleCollaboratorSelect}
                />
            )}

            {step === 'datetime' && selectedService && (
                <DateTimePicker
                    slug={business.slug}
                    serviceId={selectedService.id}
                    collaboratorId={selectedCollaborator?.id ?? null}
                    onSelect={handleDateTimeSelect}
                />
            )}

            {step === 'details' && (
                <CustomerForm
                    initialData={customerData}
                    onSubmit={handleCustomerSubmit}
                    onBack={goBack}
                />
            )}

            {step === 'summary' && selectedService && selectedDate && selectedTime && (
                <BookingSummary
                    slug={business.slug}
                    service={selectedService}
                    collaborator={selectedCollaborator}
                    date={selectedDate}
                    time={selectedTime}
                    customer={customerData}
                    onBack={goBack}
                    onSuccess={handleBookingSuccess}
                />
            )}

            {step === 'confirmation' && bookingResult && selectedService && selectedDate && selectedTime && (
                <BookingConfirmation
                    status={bookingResult.status}
                    token={bookingResult.token}
                    service={selectedService}
                    collaborator={selectedCollaborator}
                    date={selectedDate}
                    time={selectedTime}
                    businessName={business.name}
                    onBookAnother={handleBookAnother}
                />
            )}
        </BookingLayout>
    );
}
