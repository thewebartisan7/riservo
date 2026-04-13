import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { InputError } from '@/components/input-error';
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
import { invite } from '@/actions/App/Http/Controllers/Dashboard/Settings/CollaboratorController';
import { useState } from 'react';

interface Service {
    id: number;
    name: string;
}

interface CollaboratorInviteDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    services: Service[];
}

export function CollaboratorInviteDialog({ open, onOpenChange, services }: CollaboratorInviteDialogProps) {
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
                <Form action={invite()} onSuccess={() => { onOpenChange(false); setSelectedServices([]); }} className="contents">
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>{t('Invite Collaborator')}</DialogTitle>
                                <DialogDescription>{t('Send an invitation email to a new collaborator.')}</DialogDescription>
                            </DialogHeader>
                            <DialogPanel>
                                <div className="flex flex-col gap-4">
                                    <div className="flex flex-col gap-2">
                                        <Label htmlFor="invite-email">{t('Email')}</Label>
                                        <Input id="invite-email" name="email" type="email" required />
                                        <InputError message={errors.email} />
                                    </div>

                                    {services.length > 0 && (
                                        <div className="flex flex-col gap-2">
                                            <Label>{t('Assign to services (optional)')}</Label>
                                            <div className="flex flex-col gap-2">
                                                {services.map((s) => (
                                                    <Label key={s.id}>
                                                        <Checkbox
                                                            checked={selectedServices.includes(s.id)}
                                                            onCheckedChange={() => toggleService(s.id)}
                                                        />
                                                        {s.name}
                                                    </Label>
                                                ))}
                                            </div>
                                            {selectedServices.map((id) => (
                                                <input key={id} type="hidden" name="service_ids[]" value={id} />
                                            ))}
                                        </div>
                                    )}
                                </div>
                            </DialogPanel>
                            <DialogFooter>
                                <DialogClose render={<Button variant="outline" type="button" />}>{t('Cancel')}</DialogClose>
                                <Button type="submit" disabled={processing}>{t('Send Invitation')}</Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogPopup>
        </Dialog>
    );
}
