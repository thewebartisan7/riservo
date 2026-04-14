import { Button } from '@/components/ui/button';
import { Select, SelectItem, SelectPopup, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useTrans } from '@/hooks/use-trans';
import { XIcon } from 'lucide-react';

interface TimeWindowRowProps {
    openTime: string;
    closeTime: string;
    onChange: (field: 'open_time' | 'close_time', value: string) => void;
    onRemove: () => void;
    canRemove: boolean;
}

const TIME_OPTIONS: string[] = [];
for (let h = 0; h < 24; h++) {
    for (let m = 0; m < 60; m += 15) {
        TIME_OPTIONS.push(`${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`);
    }
}

export function TimeWindowRow({ openTime, closeTime, onChange, onRemove, canRemove }: TimeWindowRowProps) {
    const { t } = useTrans();

    return (
        <div className="flex items-center gap-2">
            <Select value={openTime} onValueChange={(val) => onChange('open_time', val ?? openTime)}>
                <SelectTrigger size="sm" className="min-w-0 w-[5.25rem] shrink tabular-nums">
                    <SelectValue />
                </SelectTrigger>
                <SelectPopup>
                    {TIME_OPTIONS.map((time) => (
                        <SelectItem key={`open-${time}`} value={time} className="tabular-nums">
                            {time}
                        </SelectItem>
                    ))}
                </SelectPopup>
            </Select>
            <span aria-hidden="true" className="text-xs text-muted-foreground">
                —
            </span>
            <Select value={closeTime} onValueChange={(val) => onChange('close_time', val ?? closeTime)}>
                <SelectTrigger size="sm" className="min-w-0 w-[5.25rem] shrink tabular-nums">
                    <SelectValue />
                </SelectTrigger>
                <SelectPopup>
                    {TIME_OPTIONS.map((time) => (
                        <SelectItem key={`close-${time}`} value={time} className="tabular-nums">
                            {time}
                        </SelectItem>
                    ))}
                </SelectPopup>
            </Select>
            {canRemove && (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-xs"
                    className="ml-auto text-muted-foreground hover:text-foreground"
                    onClick={onRemove}
                >
                    <XIcon />
                    <span className="sr-only">{t('Remove')}</span>
                </Button>
            )}
        </div>
    );
}
