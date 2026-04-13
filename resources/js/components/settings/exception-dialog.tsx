import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, FieldLabel, FieldError } from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Calendar } from '@/components/ui/calendar';
import {
    Select,
    SelectItem,
    SelectPopup,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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

const typeItems = [
    { label: 'Block (unavailable)', value: 'block' },
    { label: 'Open (extra availability)', value: 'open' },
];

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
        type: 'block',
        reason: '' as string | null,
    });

    useEffect(() => {
        if (open && exception) {
            http.setData({
                start_date: exception.start_date,
                end_date: exception.end_date,
                start_time: exception.start_time,
                end_time: exception.end_time,
                type: exception.type,
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
                        <DialogTitle>{isEditing ? t('Edit Exception') : t('Add Exception')}</DialogTitle>
                        <DialogDescription>{t('Define a closure, absence, or special availability.')}</DialogDescription>
                    </DialogHeader>
                    <DialogPanel>
                        <div className="flex flex-col gap-4">
                            <Field>
                                <FieldLabel>{t('Type')}</FieldLabel>
                                <Select
                                    defaultValue={http.data.type}
                                    onValueChange={(val) => http.setData('type', val as string)}
                                    items={typeItems}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder={t('Select type')} />
                                    </SelectTrigger>
                                    <SelectPopup>
                                        {typeItems.map((item) => (
                                            <SelectItem key={item.value} value={item.value}>
                                                {t(item.label)}
                                            </SelectItem>
                                        ))}
                                    </SelectPopup>
                                </Select>
                                {http.errors.type && <FieldError match>{http.errors.type}</FieldError>}
                            </Field>

                            <div className="grid grid-cols-2 gap-4">
                                <Field>
                                    <FieldLabel>{t('Start date')}</FieldLabel>
                                    <Popover open={startDateOpen} onOpenChange={setStartDateOpen}>
                                        <PopoverTrigger
                                            render={<Button variant="outline" type="button" />}
                                            className="w-full justify-start text-left font-normal"
                                        >
                                            {http.data.start_date
                                                ? formatDateDisplay(http.data.start_date)
                                                : <span className="text-muted-foreground">{t('Pick a date')}</span>}
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
                                            className="w-full justify-start text-left font-normal"
                                        >
                                            {http.data.end_date
                                                ? formatDateDisplay(http.data.end_date)
                                                : <span className="text-muted-foreground">{t('Pick a date')}</span>}
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

                            <Label>
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
                                {t('Specific time range (partial day)')}
                            </Label>

                            {isPartial && (
                                <div className="grid grid-cols-2 gap-4">
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
                                <FieldLabel>{t('Reason (optional)')}</FieldLabel>
                                <Input
                                    value={http.data.reason ?? ''}
                                    onChange={(e) => http.setData('reason', e.target.value || null)}
                                    placeholder={t('e.g. Holiday, Sick day')}
                                />
                                {http.errors.reason && <FieldError match>{http.errors.reason}</FieldError>}
                            </Field>
                        </div>
                    </DialogPanel>
                    <DialogFooter>
                        <DialogClose render={<Button variant="outline" type="button" />}>{t('Cancel')}</DialogClose>
                        <Button type="submit" disabled={http.processing}>{isEditing ? t('Update') : t('Add')}</Button>
                    </DialogFooter>
                </form>
            </DialogPopup>
        </Dialog>
    );
}
