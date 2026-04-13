import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/hooks/use-trans';
import type { FormEvent } from 'react';

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
    onBack,
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
        if (!data.name.trim()) newErrors.name = t('Name is required.');
        if (!data.email.trim()) newErrors.email = t('Email is required.');
        if (!data.phone.trim()) newErrors.phone = t('Phone is required.');
        setErrors(newErrors);
        return Object.keys(newErrors).length === 0;
    }

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        if (validate()) {
            onSubmit(data);
        }
    }

    return (
        <form onSubmit={handleSubmit} className="flex flex-col gap-4">
            <h2 className="text-lg font-semibold">{t('Your details')}</h2>

            <div>
                <label className="mb-1 block text-sm font-medium">{t('Name')} *</label>
                <Input
                    value={data.name}
                    onChange={(e) => setData({ ...data, name: e.target.value })}
                    placeholder={t('Full name')}
                />
                {errors.name && <p className="mt-1 text-xs text-destructive">{errors.name}</p>}
            </div>

            <div>
                <label className="mb-1 block text-sm font-medium">{t('Email')} *</label>
                <Input
                    type="email"
                    value={data.email}
                    onChange={(e) => setData({ ...data, email: e.target.value })}
                    placeholder="email@example.com"
                />
                {errors.email && <p className="mt-1 text-xs text-destructive">{errors.email}</p>}
            </div>

            <div>
                <label className="mb-1 block text-sm font-medium">{t('Phone')} *</label>
                <Input
                    type="tel"
                    value={data.phone}
                    onChange={(e) => setData({ ...data, phone: e.target.value })}
                    placeholder="+41 79 123 45 67"
                />
                {errors.phone && <p className="mt-1 text-xs text-destructive">{errors.phone}</p>}
            </div>

            <div>
                <label className="mb-1 block text-sm font-medium">{t('Notes')}</label>
                <Textarea
                    value={data.notes}
                    onChange={(e) => setData({ ...data, notes: e.target.value })}
                    placeholder={t('Any additional information...')}
                    rows={3}
                />
            </div>

            {/* Honeypot — hidden from real users, bots fill it */}
            <div
                aria-hidden="true"
                style={{ position: 'absolute', left: '-9999px', opacity: 0, pointerEvents: 'none' }}
            >
                <input
                    type="text"
                    name="website"
                    tabIndex={-1}
                    autoComplete="off"
                    id="booking-hp"
                />
            </div>

            <div className="flex gap-3">
                <Button type="button" variant="outline" onClick={onBack}>
                    {t('Back')}
                </Button>
                <Button type="submit" className="flex-1">
                    {t('Continue')}
                </Button>
            </div>
        </form>
    );
}
