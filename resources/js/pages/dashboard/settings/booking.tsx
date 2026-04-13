import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardHeader, CardTitle, CardDescription, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { InputError } from '@/components/input-error';
import {
    NumberField,
    NumberFieldDecrement,
    NumberFieldGroup,
    NumberFieldIncrement,
    NumberFieldInput,
} from '@/components/ui/number-field';
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
                                <div className="flex flex-col gap-2">
                                    <label htmlFor="confirmation_mode" className="text-sm font-medium">{t('Confirmation mode')}</label>
                                    <select
                                        id="confirmation_mode"
                                        name="confirmation_mode"
                                        defaultValue={settings.confirmation_mode}
                                        className="flex h-9 w-full rounded-lg border bg-background px-3 py-1 text-sm shadow-xs sm:h-8"
                                    >
                                        <option value="auto">{t('Auto-confirm')}</option>
                                        <option value="manual">{t('Manual confirmation')}</option>
                                    </select>
                                    <p className="text-xs text-muted-foreground">{t('Auto-confirm instantly confirms bookings. Manual requires your approval.')}</p>
                                    <InputError message={errors.confirmation_mode} />
                                </div>

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

                                <div className="flex flex-col gap-2">
                                    <label htmlFor="assignment_strategy" className="text-sm font-medium">{t('Assignment strategy')}</label>
                                    <select
                                        id="assignment_strategy"
                                        name="assignment_strategy"
                                        defaultValue={settings.assignment_strategy}
                                        className="flex h-9 w-full rounded-lg border bg-background px-3 py-1 text-sm shadow-xs sm:h-8"
                                    >
                                        <option value="first_available">{t('First available')}</option>
                                        <option value="round_robin">{t('Round robin (least busy)')}</option>
                                    </select>
                                    <p className="text-xs text-muted-foreground">{t('How collaborators are assigned when customer does not choose one')}</p>
                                    <InputError message={errors.assignment_strategy} />
                                </div>

                                <div className="flex flex-col gap-2">
                                    <label htmlFor="cancellation_window_hours" className="text-sm font-medium">{t('Cancellation window (hours)')}</label>
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
                                    <p className="text-xs text-muted-foreground">{t('Minimum hours before appointment that customers can cancel. 0 = anytime.')}</p>
                                    <InputError message={errors.cancellation_window_hours} />
                                </div>

                                <div className="flex flex-col gap-2">
                                    <label htmlFor="payment_mode" className="text-sm font-medium">{t('Payment mode')}</label>
                                    <select
                                        id="payment_mode"
                                        name="payment_mode"
                                        defaultValue={settings.payment_mode}
                                        className="flex h-9 w-full rounded-lg border bg-background px-3 py-1 text-sm shadow-xs sm:h-8"
                                    >
                                        <option value="offline">{t('Pay on-site')}</option>
                                        <option value="online">{t('Pay online')}</option>
                                        <option value="customer_choice">{t('Customer choice')}</option>
                                    </select>
                                    <p className="text-xs text-muted-foreground">{t('Online payments require Stripe setup (coming soon)')}</p>
                                    <InputError message={errors.payment_mode} />
                                </div>

                                <div className="flex flex-col gap-2">
                                    <span className="text-sm font-medium">{t('Booking reminders')}</span>
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
                                    <InputError message={errors.reminder_hours} />
                                </div>
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
