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
const DAY_SHORT = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

interface DayRowProps {
    day: DaySchedule;
    onChange: (day: DaySchedule) => void;
}

export function DayRow({ day, onChange }: DayRowProps) {
    const { t } = useTrans();
    const dayName = DAY_NAMES[day.day_of_week - 1];
    const dayShort = DAY_SHORT[day.day_of_week - 1];

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
        <div className="grid grid-cols-[auto_1fr] items-start gap-x-3 gap-y-3 py-4 sm:grid-cols-[9rem_1fr] sm:gap-x-6">
            <label className="flex items-center gap-3 pt-[0.1875rem] select-none">
                <Switch checked={day.enabled} onCheckedChange={handleToggle} />
                <span className="flex items-baseline gap-2">
                    <span
                        className={`text-sm font-medium transition-colors ${
                            day.enabled ? 'text-foreground' : 'text-muted-foreground'
                        }`}
                    >
                        <span className="hidden sm:inline">{t(dayName)}</span>
                        <span className="sm:hidden">{t(dayShort)}</span>
                    </span>
                </span>
            </label>

            <div className="col-start-2 flex flex-col gap-2">
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
                            size="xs"
                            className="w-fit -ml-1.5 text-muted-foreground hover:text-foreground"
                            onClick={addWindow}
                        >
                            <PlusIcon />
                            {t('Add window')}
                        </Button>
                    </>
                ) : (
                    <span className="pt-[0.4375rem] text-sm text-muted-foreground/80">
                        {t('Closed')}
                    </span>
                )}
            </div>
        </div>
    );
}
