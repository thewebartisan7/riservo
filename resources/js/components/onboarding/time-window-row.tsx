import { Button } from '@/components/ui/button';
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
            <select
                value={openTime}
                onChange={(e) => onChange('open_time', e.target.value)}
                className="h-8 rounded-md border border-input bg-background px-2 text-sm shadow-xs"
            >
                {TIME_OPTIONS.map((time) => (
                    <option key={`open-${time}`} value={time}>
                        {time}
                    </option>
                ))}
            </select>
            <span className="text-sm text-muted-foreground">{t('to')}</span>
            <select
                value={closeTime}
                onChange={(e) => onChange('close_time', e.target.value)}
                className="h-8 rounded-md border border-input bg-background px-2 text-sm shadow-xs"
            >
                {TIME_OPTIONS.map((time) => (
                    <option key={`close-${time}`} value={time}>
                        {time}
                    </option>
                ))}
            </select>
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
