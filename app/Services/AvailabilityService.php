<?php

namespace App\Services;

use App\DTOs\TimeWindow;
use App\Enums\DayOfWeek;
use App\Enums\ExceptionType;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AvailabilityService
{
    /**
     * Get available time windows for a collaborator on a given date.
     *
     * @return array<TimeWindow>
     */
    public function getAvailableWindows(
        Business $business,
        User $collaborator,
        CarbonImmutable $date,
    ): array {
        $timezone = $business->timezone;
        $dayOfWeek = DayOfWeek::from($date->dayOfWeekIso);

        // Step 1: Compute effective business hours
        $businessHourWindows = $this->getBusinessHourWindows($business, $dayOfWeek, $date, $timezone);
        $businessExceptions = $this->getExceptionsForDate($business, null, $date);
        $effectiveBusinessHours = $this->applyExceptions($businessHourWindows, $businessExceptions, $date, $timezone);

        if (empty($effectiveBusinessHours)) {
            return [];
        }

        // Step 2: Compute effective collaborator availability
        $collaboratorWindows = $this->getCollaboratorWindows($collaborator, $business, $dayOfWeek, $date, $timezone);
        $collaboratorExceptions = $this->getExceptionsForDate($business, $collaborator, $date);
        $effectiveCollaborator = $this->applyExceptions($collaboratorWindows, $collaboratorExceptions, $date, $timezone);

        if (empty($effectiveCollaborator)) {
            return [];
        }

        // Step 3: Intersect — collaborator bounded by business hours
        return TimeWindow::intersect($effectiveBusinessHours, $effectiveCollaborator);
    }

    /**
     * @return array<TimeWindow>
     */
    private function getBusinessHourWindows(
        Business $business,
        DayOfWeek $dayOfWeek,
        CarbonImmutable $date,
        string $timezone,
    ): array {
        return BusinessHour::where('business_id', $business->id)
            ->where('day_of_week', $dayOfWeek->value)
            ->orderBy('open_time')
            ->get()
            ->map(fn (BusinessHour $hour) => new TimeWindow(
                $date->setTimezone($timezone)->setTimeFromTimeString($hour->open_time),
                $date->setTimezone($timezone)->setTimeFromTimeString($hour->close_time),
            ))
            ->all();
    }

    /**
     * @return array<TimeWindow>
     */
    private function getCollaboratorWindows(
        User $collaborator,
        Business $business,
        DayOfWeek $dayOfWeek,
        CarbonImmutable $date,
        string $timezone,
    ): array {
        return AvailabilityRule::where('collaborator_id', $collaborator->id)
            ->where('business_id', $business->id)
            ->where('day_of_week', $dayOfWeek->value)
            ->orderBy('start_time')
            ->get()
            ->map(fn (AvailabilityRule $rule) => new TimeWindow(
                $date->setTimezone($timezone)->setTimeFromTimeString($rule->start_time),
                $date->setTimezone($timezone)->setTimeFromTimeString($rule->end_time),
            ))
            ->all();
    }

    /**
     * Get exceptions for a specific date. Pass null collaborator for business-level exceptions.
     *
     * @return Collection<int, AvailabilityException>
     */
    private function getExceptionsForDate(
        Business $business,
        ?User $collaborator,
        CarbonImmutable $date,
    ): Collection {
        return AvailabilityException::where('business_id', $business->id)
            ->when(
                $collaborator === null,
                fn ($q) => $q->whereNull('collaborator_id'),
                fn ($q) => $q->where('collaborator_id', $collaborator->id),
            )
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->get();
    }

    /**
     * Apply exceptions (blocks and opens) to a set of time windows.
     *
     * Order: full-day blocks wipe all, then partial blocks subtract, then opens add back.
     *
     * @param  array<TimeWindow>  $windows
     * @param  Collection<int, AvailabilityException>  $exceptions
     * @return array<TimeWindow>
     */
    private function applyExceptions(
        array $windows,
        Collection $exceptions,
        CarbonImmutable $date,
        string $timezone,
    ): array {
        // Full-day blocks wipe everything
        $hasFullDayBlock = $exceptions->contains(
            fn (AvailabilityException $e) => $e->type === ExceptionType::Block
                && $e->start_time === null
                && $e->end_time === null,
        );

        if ($hasFullDayBlock) {
            $windows = [];
        }

        // Partial blocks subtract
        $partialBlocks = $exceptions->filter(
            fn (AvailabilityException $e) => $e->type === ExceptionType::Block
                && $e->start_time !== null
                && $e->end_time !== null,
        );

        foreach ($partialBlocks as $block) {
            $blockWindow = new TimeWindow(
                $date->setTimezone($timezone)->setTimeFromTimeString($block->start_time),
                $date->setTimezone($timezone)->setTimeFromTimeString($block->end_time),
            );
            $windows = TimeWindow::subtract($windows, [$blockWindow]);
        }

        // Opens add availability
        $opens = $exceptions->filter(
            fn (AvailabilityException $e) => $e->type === ExceptionType::Open
                && $e->start_time !== null
                && $e->end_time !== null,
        );

        foreach ($opens as $open) {
            $openWindow = new TimeWindow(
                $date->setTimezone($timezone)->setTimeFromTimeString($open->start_time),
                $date->setTimezone($timezone)->setTimeFromTimeString($open->end_time),
            );
            $windows = TimeWindow::union($windows, [$openWindow]);
        }

        return $windows;
    }
}
