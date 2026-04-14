import { useState } from 'react';
import { useTrans } from '@/hooks/use-trans';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Display } from '@/components/ui/display';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

export interface CustomerData {
    name: string;
    email: string;
    phone: string;
    notes: string;
}

interface CustomerFormProps {
    initialData?: Partial<CustomerData>;
    onSubmit: (data: CustomerData) => void;
    onBack: () => void;
}

export default function CustomerForm({
    initialData,
    onSubmit,
}: CustomerFormProps) {
    const { t } = useTrans();
    const [data, setData] = useState<CustomerData>({
        name: initialData?.name ?? '',
        email: initialData?.email ?? '',
        phone: initialData?.phone ?? '',
        notes: initialData?.notes ?? '',
    });
    const [errors, setErrors] = useState<Partial<Record<keyof CustomerData, string>>>({});

    function validate(): boolean {
        const newErrors: typeof errors = {};
        if (!data.name.trim()) newErrors.name = t('Please share your name.');
        if (!data.email.trim()) newErrors.email = t('We need an email to send confirmation.');
        if (!data.phone.trim()) newErrors.phone = t('A phone number helps in case plans change.');
        setErrors(newErrors);
        return Object.keys(newErrors).length === 0;
    }

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        if (validate()) onSubmit(data);
    }

    return (
        <form onSubmit={handleSubmit} className="flex flex-col gap-7">
            <div>
                <Display
                    render={<h2 />}
                    className="text-2xl font-semibold leading-tight text-foreground"
                >
                    {t('Just a few details')}
                </Display>
                <p className="mt-1.5 text-sm text-muted-foreground">
                    {t('So we know who to expect — and where to send the confirmation.')}
                </p>
            </div>

            <div className="flex flex-col gap-5">
                <Field>
                    <FieldLabel>{t('Your name')}</FieldLabel>
                    <Input
                        type="text"
                        name="name"
                        autoComplete="name"
                        placeholder={t('Full name')}
                        value={data.name}
                        onChange={(e) => setData({ ...data, name: e.target.value })}
                        aria-invalid={!!errors.name}
                        className="has-aria-invalid:border-primary has-focus-visible:has-aria-invalid:border-primary has-aria-invalid:ring-ring/24 has-focus-visible:has-aria-invalid:ring-ring/24"
                    />
                    {errors.name && <FieldError match className="text-primary">{errors.name}</FieldError>}
                </Field>

                <Field>
                    <FieldLabel>{t('Email')}</FieldLabel>
                    <Input
                        type="email"
                        name="email"
                        autoComplete="email"
                        placeholder="name@example.com"
                        value={data.email}
                        onChange={(e) => setData({ ...data, email: e.target.value })}
                        aria-invalid={!!errors.email}
                        className="has-aria-invalid:border-primary has-focus-visible:has-aria-invalid:border-primary has-aria-invalid:ring-ring/24 has-focus-visible:has-aria-invalid:ring-ring/24"
                    />
                    {errors.email && <FieldError match className="text-primary">{errors.email}</FieldError>}
                </Field>

                <Field>
                    <FieldLabel>{t('Phone')}</FieldLabel>
                    <Input
                        type="tel"
                        name="phone"
                        autoComplete="tel"
                        placeholder="+41 79 123 45 67"
                        value={data.phone}
                        onChange={(e) => setData({ ...data, phone: e.target.value })}
                        aria-invalid={!!errors.phone}
                        className="has-aria-invalid:border-primary has-focus-visible:has-aria-invalid:border-primary has-aria-invalid:ring-ring/24 has-focus-visible:has-aria-invalid:ring-ring/24"
                    />
                    {errors.phone && <FieldError match className="text-primary">{errors.phone}</FieldError>}
                </Field>

                <Field>
                    <FieldLabel>
                        {t('Notes')}{' '}
                        <span className="font-normal text-muted-foreground">
                            ({t('optional')})
                        </span>
                    </FieldLabel>
                    <Textarea
                        rows={3}
                        value={data.notes}
                        onChange={(e) => setData({ ...data, notes: e.target.value })}
                        placeholder={t('Anything we should know? Allergies, preferences…')}
                    />
                </Field>
            </div>

            {/* Honeypot — hidden from real users, bots fill it */}
            <div
                aria-hidden="true"
                className="pointer-events-none absolute -left-[9999px] opacity-0"
            >
                <input
                    type="text"
                    name="website"
                    tabIndex={-1}
                    autoComplete="off"
                    id="booking-hp"
                />
            </div>

            <Button
                type="submit"
                variant="default"
                size="xl"
                className="h-12 sm:h-12 text-sm"
            >
                <Display className="tracking-tight">{t('Continue to review')} →</Display>
            </Button>
        </form>
    );
}
