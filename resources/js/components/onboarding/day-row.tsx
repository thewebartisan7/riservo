import { Switch } from '@/components/ui/switch';
import { Button } from '@/components/ui/button';
import { TimeWindowRow } from './time-window-row';
import { useTrans } from '@/hooks/use-trans';
import { PlusIcon } from 'lucide-react';

export interface TimeWindow {
    open_time: string;
    close_time: string;
}

export interface DaySchedule {
    day_of_week: number;
    enabled: boolean;
    windows: TimeWindow[];
}

const DAY_NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

interface DayRowProps {
    day: DaySchedule;
    onChange: (day: DaySchedule) => void;
}

export function DayRow({ day, onChange }: DayRowProps) {
    const { t } = useTrans();
    const dayName = DAY_NAMES[day.day_of_week - 1];

    function handleToggle(checked: boolean) {
        onChange({
            ...day,
            enabled: checked,
            windows: checked && day.windows.length === 0
                ? [{ open_time: '09:00', close_time: '18:00' }]
                : day.windows,
        });
    }

    function handleWindowChange(index: number, field: 'open_time' | 'close_time', value: string) {
        const newWindows = [...day.windows];
        newWindows[index] = { ...newWindows[index], [field]: value };
        onChange({ ...day, windows: newWindows });
    }

    function addWindow() {
        const lastWindow = day.windows[day.windows.length - 1];
        onChange({
            ...day,
            windows: [...day.windows, { open_time: lastWindow?.close_time ?? '13:00', close_time: '18:00' }],
        });
    }

    function removeWindow(index: number) {
        onChange({
            ...day,
            windows: day.windows.filter((_, i) => i !== index),
        });
    }

    return (
        <div className="flex items-start gap-4 py-3">
            <div className="flex w-28 shrink-0 items-center gap-3 pt-1">
                <Switch checked={day.enabled} onCheckedChange={handleToggle} />
                <span className={`text-sm font-medium ${!day.enabled ? 'text-muted-foreground' : ''}`}>
                    {t(dayName)}
                </span>
            </div>

            <div className="flex flex-1 flex-col gap-2">
                {day.enabled ? (
                    <>
                        {day.windows.map((window, index) => (
                            <TimeWindowRow
                                key={index}
                                openTime={window.open_time}
                                closeTime={window.close_time}
                                onChange={(field, value) => handleWindowChange(index, field, value)}
                                onRemove={() => removeWindow(index)}
                                canRemove={day.windows.length > 1}
                            />
                        ))}
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="w-fit text-xs"
                            onClick={addWindow}
                        >
                            <PlusIcon className="mr-1 h-3 w-3" />
                            {t('Add time window')}
                        </Button>
                    </>
                ) : (
                    <span className="pt-1 text-sm text-muted-foreground">{t('Closed')}</span>
                )}
            </div>
        </div>
    );
}
