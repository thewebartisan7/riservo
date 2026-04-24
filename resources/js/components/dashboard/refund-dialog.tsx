import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/Dashboard/BookingRefundController';
import { useTrans } from '@/hooks/use-trans';
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
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, FieldLabel, FieldError, FieldDescription } from '@/components/ui/field';
import { RadioGroup, Radio } from '@/components/ui/radio-group';
import { Label } from '@/components/ui/label';

interface RefundDialogProps {
    bookingId: number;
    currency: string;
    remainingCents: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess?: () => void;
}

/**
 * PAYMENTS Session 3 — admin-manual refund dialog.
 *
 * Admin selects Full or Partial, enters an optional reason, submits to
 * `Dashboard\BookingRefundController::store` which dispatches
 * `RefundService::refund(..., reason='admin-manual', initiatedByUserId=auth)`.
 *
 * Partial-refund overflow (request > remaining refundable) is rejected
 * server-side with 422 per D-169; the error is surfaced under the amount
 * field.
 */
export default function RefundDialog({
    bookingId,
    currency,
    remainingCents,
    open,
    onOpenChange,
    onSuccess,
}: RefundDialogProps) {
    const { t } = useTrans();
    const [kind, setKind] = useState<'full' | 'partial'>('full');
    const [amountInput, setAmountInput] = useState<string>('');
    const form = useForm({
        kind: 'full' as 'full' | 'partial',
        amount_cents: 0,
        reason: '',
    });

    const remainingFormatted = `${currency.toUpperCase()} ${(remainingCents / 100).toFixed(2)}`;

    function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const parsed = kind === 'partial' ? parseAmount(amountInput) : null;
        form.setData({
            kind,
            amount_cents: parsed ?? 0,
            reason: form.data.reason,
        });

        form.transform((data) => ({
            kind,
            amount_cents: kind === 'partial' ? parsed : null,
            reason: data.reason,
        }));

        form.post(store(bookingId).url, {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                form.reset();
                setKind('full');
                setAmountInput('');
                onSuccess?.();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogPopup>
                <form onSubmit={handleSubmit} className="contents">
                    <DialogHeader>
                        <DialogTitle>{t('Issue a refund')}</DialogTitle>
                        <DialogDescription>
                            {t('Up to :amount refundable.', { amount: remainingFormatted })}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogPanel>
                        <div className="flex flex-col gap-5">
                            <Field>
                                <FieldLabel>{t('Refund amount')}</FieldLabel>
                                <RadioGroup
                                    value={kind}
                                    onValueChange={(v) => setKind(v === 'partial' ? 'partial' : 'full')}
                                >
                                    <Label className="flex items-center gap-3">
                                        <Radio value="full" />
                                        <span className="text-sm">
                                            {t('Full refund (:amount)', { amount: remainingFormatted })}
                                        </span>
                                    </Label>
                                    <Label className="flex items-center gap-3">
                                        <Radio value="partial" />
                                        <span className="text-sm">
                                            {t('Partial refund')}
                                        </span>
                                    </Label>
                                </RadioGroup>
                            </Field>

                            {kind === 'partial' && (
                                <Field>
                                    <FieldLabel>{t('Amount (:currency)', { currency: currency.toUpperCase() })}</FieldLabel>
                                    <Input
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        max={(remainingCents / 100).toFixed(2)}
                                        value={amountInput}
                                        onChange={(e) => setAmountInput(e.target.value)}
                                        placeholder="0.00"
                                    />
                                    <FieldDescription>
                                        {t('Maximum: :amount', { amount: remainingFormatted })}
                                    </FieldDescription>
                                    {form.errors.amount_cents && (
                                        <FieldError match>{form.errors.amount_cents}</FieldError>
                                    )}
                                </Field>
                            )}

                            <Field>
                                <FieldLabel>{t('Reason (optional)')}</FieldLabel>
                                <Textarea
                                    value={form.data.reason}
                                    onChange={(e) => form.setData('reason', e.target.value)}
                                    rows={3}
                                    placeholder={t('Internal note — not shared with the customer.')}
                                />
                                {form.errors.reason && (
                                    <FieldError match>{form.errors.reason}</FieldError>
                                )}
                            </Field>
                        </div>
                    </DialogPanel>
                    <DialogFooter>
                        <DialogClose render={<Button type="button" variant="ghost" />}>
                            {t('Cancel')}
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={
                                form.processing
                                || (kind === 'partial' && !isValidPartial(amountInput, remainingCents))
                            }
                        >
                            {t('Issue refund')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogPopup>
        </Dialog>
    );
}

function parseAmount(input: string): number | null {
    const trimmed = input.trim();
    if (trimmed === '') return null;
    const value = Number(trimmed);
    if (!Number.isFinite(value) || value <= 0) return null;
    return Math.round(value * 100);
}

function isValidPartial(input: string, remainingCents: number): boolean {
    const parsed = parseAmount(input);
    return parsed !== null && parsed > 0 && parsed <= remainingCents;
}
