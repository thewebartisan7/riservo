import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { InputError } from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import {
    NumberField,
    NumberFieldDecrement,
    NumberFieldGroup,
    NumberFieldIncrement,
    NumberFieldInput,
} from '@/components/ui/number-field';
import {
    Select,
    SelectItem,
    SelectPopup,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTrans } from '@/hooks/use-trans';
import { Form } from '@inertiajs/react';
import { useState } from 'react';

interface Collaborator {
    id: number;
    name: string;
}

interface ServiceFormProps {
    action: { url: string; method: 'get' | 'post' | 'put' | 'patch' | 'delete' };
    service?: {
        name: string;
        description: string | null;
        duration_minutes: number;
        price: number | null;
        buffer_before: number;
        buffer_after: number;
        slot_interval_minutes: number;
        is_active: boolean;
        collaborator_ids: number[];
    };
    collaborators: Collaborator[];
    submitLabel: string;
}

const slotIntervalItems = [
    { label: '5 min', value: '5' },
    { label: '10 min', value: '10' },
    { label: '15 min', value: '15' },
    { label: '20 min', value: '20' },
    { label: '30 min', value: '30' },
    { label: '60 min', value: '60' },
];

export function ServiceForm({ action, service, collaborators, submitLabel }: ServiceFormProps) {
    const { t } = useTrans();
    const [selectedCollaborators, setSelectedCollaborators] = useState<number[]>(service?.collaborator_ids ?? []);
    const [isActive, setIsActive] = useState(service?.is_active ?? true);

    function toggleCollaborator(id: number) {
        setSelectedCollaborators((prev) =>
            prev.includes(id) ? prev.filter((c) => c !== id) : [...prev, id],
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>{service ? t('Edit Service') : t('New Service')}</CardTitle>
                <CardDescription>{service ? t('Update service details') : t('Add a new service to your business')}</CardDescription>
            </CardHeader>
            <Form action={action}>
                {({ errors, processing }) => (
                    <>
                        <CardPanel className="flex flex-col gap-4">
                            <div className="flex flex-col gap-2">
                                <Label htmlFor="name">{t('Service name')}</Label>
                                <Input id="name" name="name" defaultValue={service?.name ?? ''} required />
                                <InputError message={errors.name} />
                            </div>

                            <div className="flex flex-col gap-2">
                                <Label htmlFor="description">{t('Description')}</Label>
                                <Textarea
                                    id="description"
                                    name="description"
                                    defaultValue={service?.description ?? ''}
                                    rows={3}
                                />
                                <InputError message={errors.description} />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="flex flex-col gap-2">
                                    <Label>{t('Duration (minutes)')}</Label>
                                    <NumberField name="duration_minutes" defaultValue={service?.duration_minutes ?? 60} min={5} max={480}>
                                        <NumberFieldGroup>
                                            <NumberFieldDecrement />
                                            <NumberFieldInput />
                                            <NumberFieldIncrement />
                                        </NumberFieldGroup>
                                    </NumberField>
                                    <InputError message={errors.duration_minutes} />
                                </div>
                                <div className="flex flex-col gap-2">
                                    <Label htmlFor="price">{t('Price')}</Label>
                                    <Input
                                        id="price"
                                        name="price"
                                        type="number"
                                        min={0}
                                        step="0.01"
                                        defaultValue={service?.price ?? ''}
                                        placeholder={t('Leave empty for "on request"')}
                                    />
                                    <InputError message={errors.price} />
                                </div>
                            </div>

                            <div className="grid grid-cols-3 gap-4">
                                <div className="flex flex-col gap-2">
                                    <Label>{t('Buffer before (min)')}</Label>
                                    <NumberField name="buffer_before" defaultValue={service?.buffer_before ?? 0} min={0} max={120}>
                                        <NumberFieldGroup>
                                            <NumberFieldDecrement />
                                            <NumberFieldInput />
                                            <NumberFieldIncrement />
                                        </NumberFieldGroup>
                                    </NumberField>
                                    <InputError message={errors.buffer_before} />
                                </div>
                                <div className="flex flex-col gap-2">
                                    <Label>{t('Buffer after (min)')}</Label>
                                    <NumberField name="buffer_after" defaultValue={service?.buffer_after ?? 0} min={0} max={120}>
                                        <NumberFieldGroup>
                                            <NumberFieldDecrement />
                                            <NumberFieldInput />
                                            <NumberFieldIncrement />
                                        </NumberFieldGroup>
                                    </NumberField>
                                    <InputError message={errors.buffer_after} />
                                </div>
                                <div className="flex flex-col gap-2">
                                    <Label>{t('Slot interval')}</Label>
                                    <Select
                                        name="slot_interval_minutes"
                                        defaultValue={String(service?.slot_interval_minutes ?? 30)}
                                        items={slotIntervalItems}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectPopup>
                                            {slotIntervalItems.map((item) => (
                                                <SelectItem key={item.value} value={item.value}>
                                                    {item.label}
                                                </SelectItem>
                                            ))}
                                        </SelectPopup>
                                    </Select>
                                    <InputError message={errors.slot_interval_minutes} />
                                </div>
                            </div>

                            <div className="flex items-center justify-between">
                                <div>
                                    <Label htmlFor="is_active">{t('Active')}</Label>
                                    <p className="text-xs text-muted-foreground">{t('Inactive services are hidden from customers')}</p>
                                </div>
                                <Switch id="is_active" checked={isActive} onCheckedChange={setIsActive} />
                                <input type="hidden" name="is_active" value={isActive ? '1' : '0'} />
                            </div>

                            {collaborators.length > 0 && (
                                <div className="flex flex-col gap-2">
                                    <Label>{t('Assigned collaborators')}</Label>
                                    <div className="flex flex-col gap-2">
                                        {collaborators.map((c) => (
                                            <Label key={c.id}>
                                                <Checkbox
                                                    checked={selectedCollaborators.includes(c.id)}
                                                    onCheckedChange={() => toggleCollaborator(c.id)}
                                                />
                                                {c.name}
                                            </Label>
                                        ))}
                                    </div>
                                    {selectedCollaborators.map((id) => (
                                        <input key={id} type="hidden" name="collaborator_ids[]" value={id} />
                                    ))}
                                    {selectedCollaborators.length === 0 && (
                                        <input type="hidden" name="collaborator_ids" value="" />
                                    )}
                                </div>
                            )}
                        </CardPanel>
                        <CardFooter className="flex justify-end">
                            <Button type="submit" disabled={processing}>{submitLabel}</Button>
                        </CardFooter>
                    </>
                )}
            </Form>
        </Card>
    );
}
