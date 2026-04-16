import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, FieldLabel, FieldError, FieldDescription } from '@/components/ui/field';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogClose,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogPanel,
    DialogPopup,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTrans } from '@/hooks/use-trans';
import { Form } from '@inertiajs/react';
import { invite } from '@/actions/App/Http/Controllers/Dashboard/Settings/StaffController';
import { useState } from 'react';

interface Service {
    id: number;
    name: string;
}

interface StaffInviteDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    services: Service[];
    expiryHours: number;
}

export function StaffInviteDialog({ open, onOpenChange, services, expiryHours }: StaffInviteDialogProps) {
    const { t } = useTrans();
    const [selectedServices, setSelectedServices] = useState<number[]>([]);

    function toggleService(id: number) {
        setSelectedServices((prev) =>
            prev.includes(id) ? prev.filter((s) => s !== id) : [...prev, id],
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogPopup>
                <Form
                    action={invite()}
                    onSuccess={() => {
                        onOpenChange(false);
                        setSelectedServices([]);
                    }}
                    className="contents"
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>{t('Invite a team member')}</DialogTitle>
                                <DialogDescription>
                                    {t('They receive an email to set up a password. The invite expires in :hours hours.', { hours: expiryHours })}
                                </DialogDescription>
                            </DialogHeader>
                            <DialogPanel>
                                <div className="flex flex-col gap-5">
                                    <Field>
                                        <FieldLabel>{t('Email')}</FieldLabel>
                                        <Input
                                            name="email"
                                            type="email"
                                            required
                                            placeholder="name@example.ch"
                                        />
                                        {errors.email && <FieldError match>{errors.email}</FieldError>}
                                    </Field>

                                    {services.length > 0 && (
                                        <Field>
                                            <div className="flex items-baseline justify-between gap-3">
                                                <FieldLabel>{t('Assign services')}</FieldLabel>
                                                <span className="font-display text-[11px] tabular-nums text-muted-foreground">
                                                    {t(':n of :total selected', {
                                                        n: selectedServices.length,
                                                        total: services.length,
                                                    })}
                                                </span>
                                            </div>
                                            <div className="grid gap-2 sm:grid-cols-2">
                                                {services.map((s) => {
                                                    const checked = selectedServices.includes(s.id);
                                                    return (
                                                        <Label
                                                            key={s.id}
                                                            className="flex items-center gap-2.5 rounded-lg border border-border/70 bg-background px-3.5 py-2.5 text-sm transition-colors hover:border-border not-has-[:checked]:hover:bg-muted/40 has-[:checked]:border-primary/40 has-[:checked]:bg-honey-soft/60"
                                                        >
                                                            <Checkbox
                                                                checked={checked}
                                                                onCheckedChange={() => toggleService(s.id)}
                                                            />
                                                            <span className="truncate text-foreground">
                                                                {s.name}
                                                            </span>
                                                        </Label>
                                                    );
                                                })}
                                            </div>
                                            <FieldDescription>
                                                {t('Optional. You can change assignments on their profile later.')}
                                            </FieldDescription>
                                            {selectedServices.map((id) => (
                                                <input key={id} type="hidden" name="service_ids[]" value={id} />
                                            ))}
                                        </Field>
                                    )}

                                    {services.length === 0 && (
                                        <p className="text-xs leading-relaxed text-muted-foreground">
                                            {t('Once you have services, you can assign them to this team member from their profile.')}
                                        </p>
                                    )}
                                </div>
                            </DialogPanel>
                            <DialogFooter>
                                <DialogClose render={<Button variant="outline" type="button" />}>
                                    {t('Cancel')}
                                </DialogClose>
                                <Button type="submit" loading={processing}>
                                    {t('Send invitation')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogPopup>
        </Dialog>
    );
}
