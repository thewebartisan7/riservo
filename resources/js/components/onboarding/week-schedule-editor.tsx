import { DayRow, type DaySchedule } from './day-row';

interface WeekScheduleEditorProps {
    hours: DaySchedule[];
    onChange: (hours: DaySchedule[]) => void;
}

export function WeekScheduleEditor({ hours, onChange }: WeekScheduleEditorProps) {
    function handleDayChange(index: number, day: DaySchedule) {
        const newHours = [...hours];
        newHours[index] = day;
        onChange(newHours);
    }

    return (
        <div className="divide-y">
            {hours.map((day, index) => (
                <DayRow
                    key={day.day_of_week}
                    day={day}
                    onChange={(updated) => handleDayChange(index, updated)}
                />
            ))}
        </div>
    );
}
