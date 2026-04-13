import { Button } from '@/components/ui/button';
import { Select, SelectItem, SelectPopup, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useTrans } from '@/hooks/use-trans';
import { Trash2Icon } from 'lucide-react';

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
            <Select value={openTime} onValueChange={(val) => onChange('open_time', val)}>
                <SelectTrigger size="sm" className="w-24">
                    <SelectValue />
                </SelectTrigger>
                <SelectPopup>
                    {TIME_OPTIONS.map((time) => (
                        <SelectItem key={`open-${time}`} value={time}>
                            {time}
                        </SelectItem>
                    ))}
                </SelectPopup>
            </Select>
            <span className="text-sm text-muted-foreground">{t('to')}</span>
            <Select value={closeTime} onValueChange={(val) => onChange('close_time', val)}>
                <SelectTrigger size="sm" className="w-24">
                    <SelectValue />
                </SelectTrigger>
                <SelectPopup>
                    {TIME_OPTIONS.map((time) => (
                        <SelectItem key={`close-${time}`} value={time}>
                            {time}
                        </SelectItem>
                    ))}
                </SelectPopup>
            </Select>
            {canRemove && (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-8 w-8 text-muted-foreground hover:text-destructive-foreground"
                    onClick={onRemove}
                >
                    <Trash2Icon className="h-4 w-4" />
                    <span className="sr-only">{t('Remove')}</span>
                </Button>
            )}
        </div>
    );
}
