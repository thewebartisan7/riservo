import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Field, FieldLabel, FieldError, FieldDescription } from '@/components/ui/field';
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
import { Switch } from '@/components/ui/switch';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useTrans } from '@/hooks/use-trans';
import { Form } from '@inertiajs/react';
import { update } from '@/actions/App/Http/Controllers/Dashboard/Settings/BookingSettingsController';
import { useState } from 'react';

interface Props {
    settings: {
        confirmation_mode: string;
        allow_collaborator_choice: boolean;
        cancellation_window_hours: number;
        payment_mode: string;
        assignment_strategy: string;
        reminder_hours: number[];
    };
}

export default function BookingSettings({ settings }: Props) {
    const { t } = useTrans();
    const [collaboratorChoice, setCollaboratorChoice] = useState(settings.allow_collaborator_choice);

    return (
        <SettingsLayout title={t('Booking Settings')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Booking Settings')}</CardTitle>
                    <CardDescription>{t('Configure how bookings work for your business')}</CardDescription>
                </CardHeader>
                <Form action={update()}>
                    {({ errors, processing }) => (
                        <>
                            <CardPanel className="flex flex-col gap-6">
                                <Field>
                                    <FieldLabel>{t('Confirmation mode')}</FieldLabel>
                                    <Select name="confirmation_mode" defaultValue={settings.confirmation_mode}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectPopup>
                                            <SelectItem value="auto">{t('Auto-confirm')}</SelectItem>
                                            <SelectItem value="manual">{t('Manual confirmation')}</SelectItem>
                                        </SelectPopup>
                                    </Select>
                                    <FieldDescription>{t('Auto-confirm instantly confirms bookings. Manual requires your approval.')}</FieldDescription>
                                    {errors.confirmation_mode && <FieldError match>{errors.confirmation_mode}</FieldError>}
                                </Field>

                                <div className="flex items-center justify-between">
                                    <div>
                                        <Label htmlFor="allow_collaborator_choice">{t('Allow collaborator selection')}</Label>
                                        <p className="text-xs text-muted-foreground">{t('Let customers choose a specific collaborator when booking')}</p>
                                    </div>
                                    <Switch
                                        id="allow_collaborator_choice"
                                        checked={collaboratorChoice}
                                        onCheckedChange={setCollaboratorChoice}
                                    />
                                    <input type="hidden" name="allow_collaborator_choice" value={collaboratorChoice ? '1' : '0'} />
                                </div>

                                <Field>
                                    <FieldLabel>{t('Assignment strategy')}</FieldLabel>
                                    <Select name="assignment_strategy" defaultValue={settings.assignment_strategy}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectPopup>
                                            <SelectItem value="first_available">{t('First available')}</SelectItem>
                                            <SelectItem value="round_robin">{t('Round robin (least busy)')}</SelectItem>
                                        </SelectPopup>
                                    </Select>
                                    <FieldDescription>{t('How collaborators are assigned when customer does not choose one')}</FieldDescription>
                                    {errors.assignment_strategy && <FieldError match>{errors.assignment_strategy}</FieldError>}
                                </Field>

                                <Field>
                                    <FieldLabel>{t('Cancellation window (hours)')}</FieldLabel>
                                    <NumberField
                                        name="cancellation_window_hours"
                                        defaultValue={settings.cancellation_window_hours}
                                        min={0}
                                        max={168}
                                    >
                                        <NumberFieldGroup>
                                            <NumberFieldDecrement />
                                            <NumberFieldInput />
                                            <NumberFieldIncrement />
                                        </NumberFieldGroup>
                                    </NumberField>
                                    <FieldDescription>{t('Minimum hours before appointment that customers can cancel. 0 = anytime.')}</FieldDescription>
                                    {errors.cancellation_window_hours && <FieldError match>{errors.cancellation_window_hours}</FieldError>}
                                </Field>

                                <Field>
                                    <FieldLabel>{t('Payment mode')}</FieldLabel>
                                    <Select name="payment_mode" defaultValue={settings.payment_mode}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectPopup>
                                            <SelectItem value="offline">{t('Pay on-site')}</SelectItem>
                                            <SelectItem value="online">{t('Pay online')}</SelectItem>
                                            <SelectItem value="customer_choice">{t('Customer choice')}</SelectItem>
                                        </SelectPopup>
                                    </Select>
                                    <FieldDescription>{t('Online payments require Stripe setup (coming soon)')}</FieldDescription>
                                    {errors.payment_mode && <FieldError match>{errors.payment_mode}</FieldError>}
                                </Field>

                                <Field>
                                    <FieldLabel>{t('Booking reminders')}</FieldLabel>
                                    <div className="flex gap-4">
                                        <Label>
                                            <Checkbox
                                                name="reminder_hours[]"
                                                value="24"
                                                defaultChecked={settings.reminder_hours.includes(24)}
                                            />
                                            {t('24 hours before')}
                                        </Label>
                                        <Label>
                                            <Checkbox
                                                name="reminder_hours[]"
                                                value="1"
                                                defaultChecked={settings.reminder_hours.includes(1)}
                                            />
                                            {t('1 hour before')}
                                        </Label>
                                    </div>
                                    {errors.reminder_hours && <FieldError match>{errors.reminder_hours}</FieldError>}
                                </Field>
                            </CardPanel>
                            <CardFooter className="flex justify-end">
                                <Button type="submit" disabled={processing}>{t('Save changes')}</Button>
                            </CardFooter>
                        </>
                    )}
                </Form>
            </Card>
        </SettingsLayout>
    );
}
