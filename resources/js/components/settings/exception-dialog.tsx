import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, FieldLabel, FieldError } from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverPopup,
    PopoverTrigger,
} from '@/components/ui/popover';
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
import { useHttp } from '@inertiajs/react';
import { type FormEvent, useEffect, useState } from 'react';
import { format } from 'date-fns';
import { CalendarIcon } from 'lucide-react';

export interface ExceptionData {
    id?: number;
    start_date: string;
    end_date: string;
    start_time: string | null;
    end_time: string | null;
    type: string;
    reason: string | null;
}

interface ExceptionDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    exception?: ExceptionData | null;
    storeUrl: string;
    updateUrl?: string;
}

interface TypeChoice {
    value: 'block' | 'open';
    label: string;
    hint: string;
}

function formatDateDisplay(dateStr: string): string {
    if (!dateStr) return '';
    const d = new Date(dateStr + 'T00:00:00');
    return format(d, 'PPP');
}

function parseDate(dateStr: string): Date | undefined {
    if (!dateStr) return undefined;
    return new Date(dateStr + 'T00:00:00');
}

export function ExceptionDialog({ open, onOpenChange, exception, storeUrl, updateUrl }: ExceptionDialogProps) {
    const { t } = useTrans();
    const isEditing = !!exception?.id;
    const [isPartial, setIsPartial] = useState(!!exception?.start_time);
    const [startDateOpen, setStartDateOpen] = useState(false);
    const [endDateOpen, setEndDateOpen] = useState(false);

    const http = useHttp({
        start_date: '',
        end_date: '',
        start_time: '' as string | null,
        end_time: '' as string | null,
        type: 'block' as 'block' | 'open',
        reason: '' as string | null,
    });

    const typeChoices: TypeChoice[] = [
        { value: 'block', label: t('Closed'), hint: t('Unavailable for bookings') },
        { value: 'open', label: t('Open'), hint: t('Extra availability') },
    ];

    useEffect(() => {
        if (open && exception) {
            http.setData({
                start_date: exception.start_date,
                end_date: exception.end_date,
                start_time: exception.start_time,
                end_time: exception.end_time,
                type: exception.type as 'block' | 'open',
                reason: exception.reason,
            });
            setIsPartial(!!exception.start_time);
        } else if (open) {
            http.setData({
                start_date: '',
                end_date: '',
                start_time: null,
                end_time: null,
                type: 'block',
                reason: null,
            });
            setIsPartial(false);
        }
    }, [open, exception]);

    function submit(e: FormEvent) {
        e.preventDefault();
        const url = isEditing ? updateUrl! : storeUrl;
        const method = isEditing ? 'put' : 'post';
        http[method](url, {
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogPopup>
                <form onSubmit={submit} className="contents">
                    <DialogHeader>
                        <DialogTitle>{isEditing ? t('Edit exception') : t('New exception')}</DialogTitle>
                        <DialogDescription>
                            {t('Override your default hours for a closure, vacation, or special opening.')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogPanel>
                        <div className="flex flex-col gap-5">
                            <Field>
                                <FieldLabel>{t('Type')}</FieldLabel>
                                <div className="grid grid-cols-2 gap-2">
                                    {typeChoices.map((choice) => {
                                        const selected = http.data.type === choice.value;
                                        return (
                                            <button
                                                key={choice.value}
                                                type="button"
                                                aria-pressed={selected}
                                                onClick={() => http.setData('type', choice.value)}
                                                className="flex flex-col items-start gap-0.5 rounded-lg border border-border/70 bg-background px-3.5 py-2.5 text-left transition-colors hover:border-border hover:bg-muted/40 aria-pressed:border-primary/40 aria-pressed:bg-honey-soft/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/32"
                                            >
                                                <span className="text-sm font-medium text-foreground">
                                                    {choice.label}
                                                </span>
                                                <span className="text-[11px] text-muted-foreground">
                                                    {choice.hint}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                                {http.errors.type && <FieldError match>{http.errors.type}</FieldError>}
                            </Field>

                            <div className="grid grid-cols-2 gap-3">
                                <Field>
                                    <FieldLabel>{t('Start date')}</FieldLabel>
                                    <Popover open={startDateOpen} onOpenChange={setStartDateOpen}>
                                        <PopoverTrigger
                                            render={<Button variant="outline" type="button" />}
                                            className="h-9 w-full justify-start gap-2 px-3 text-left font-normal"
                                        >
                                            <CalendarIcon
                                                aria-hidden="true"
                                                className="size-3.5 text-muted-foreground"
                                                strokeWidth={1.75}
                                            />
                                            {http.data.start_date ? (
                                                <span className="font-display tabular-nums text-foreground">
                                                    {formatDateDisplay(http.data.start_date)}
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">{t('Pick a date')}</span>
                                            )}
                                        </PopoverTrigger>
                                        <PopoverPopup>
                                            <Calendar
                                                mode="single"
                                                selected={parseDate(http.data.start_date)}
                                                onSelect={(date) => {
                                                    http.setData('start_date', date ? format(date, 'yyyy-MM-dd') : '');
                                                    setStartDateOpen(false);
                                                }}
                                            />
                                        </PopoverPopup>
                                    </Popover>
                                    {http.errors.start_date && <FieldError match>{http.errors.start_date}</FieldError>}
                                </Field>
                                <Field>
                                    <FieldLabel>{t('End date')}</FieldLabel>
                                    <Popover open={endDateOpen} onOpenChange={setEndDateOpen}>
                                        <PopoverTrigger
                                            render={<Button variant="outline" type="button" />}
                                            className="h-9 w-full justify-start gap-2 px-3 text-left font-normal"
                                        >
                                            <CalendarIcon
                                                aria-hidden="true"
                                                className="size-3.5 text-muted-foreground"
                                                strokeWidth={1.75}
                                            />
                                            {http.data.end_date ? (
                                                <span className="font-display tabular-nums text-foreground">
                                                    {formatDateDisplay(http.data.end_date)}
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">{t('Pick a date')}</span>
                                            )}
                                        </PopoverTrigger>
                                        <PopoverPopup>
                                            <Calendar
                                                mode="single"
                                                selected={parseDate(http.data.end_date)}
                                                onSelect={(date) => {
                                                    http.setData('end_date', date ? format(date, 'yyyy-MM-dd') : '');
                                                    setEndDateOpen(false);
                                                }}
                                            />
                                        </PopoverPopup>
                                    </Popover>
                                    {http.errors.end_date && <FieldError match>{http.errors.end_date}</FieldError>}
                                </Field>
                            </div>

                            <Label className="flex items-center gap-2.5 rounded-lg border border-border/70 bg-background px-3.5 py-2.5 text-sm transition-colors hover:border-border not-has-[:checked]:hover:bg-muted/40 has-[:checked]:border-primary/40 has-[:checked]:bg-honey-soft/60">
                                <Checkbox
                                    checked={isPartial}
                                    onCheckedChange={(checked) => {
                                        const val = !!checked;
                                        setIsPartial(val);
                                        if (!val) {
                                            http.setData('start_time', null);
                                            http.setData('end_time', null);
                                        }
                                    }}
                                />
                                <span className="text-foreground">
                                    {t('Only part of the day')}
                                </span>
                            </Label>

                            {isPartial && (
                                <div className="grid grid-cols-2 gap-3">
                                    <Field>
                                        <FieldLabel>{t('Start time')}</FieldLabel>
                                        <Input
                                            type="time"
                                            value={http.data.start_time ?? ''}
                                            onChange={(e) => http.setData('start_time', e.target.value)}
                                        />
                                        {http.errors.start_time && <FieldError match>{http.errors.start_time}</FieldError>}
                                    </Field>
                                    <Field>
                                        <FieldLabel>{t('End time')}</FieldLabel>
                                        <Input
                                            type="time"
                                            value={http.data.end_time ?? ''}
                                            onChange={(e) => http.setData('end_time', e.target.value)}
                                        />
                                        {http.errors.end_time && <FieldError match>{http.errors.end_time}</FieldError>}
                                    </Field>
                                </div>
                            )}

                            <Field>
                                <FieldLabel>{t('Reason')}</FieldLabel>
                                <Input
                                    value={http.data.reason ?? ''}
                                    onChange={(e) => http.setData('reason', e.target.value || null)}
                                    placeholder={t('Holiday, sick day, special evening…')}
                                />
                                {http.errors.reason && <FieldError match>{http.errors.reason}</FieldError>}
                            </Field>
                        </div>
                    </DialogPanel>
                    <DialogFooter>
                        <DialogClose render={<Button variant="outline" type="button" />}>{t('Cancel')}</DialogClose>
                        <Button type="submit" loading={http.processing}>
                            {isEditing ? t('Save exception') : t('Add exception')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogPopup>
        </Dialog>
    );
}
