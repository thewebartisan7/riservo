<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Provider;
use Illuminate\Support\Str;

/**
 * Shared schedule build/write helpers for the provider availability surface.
 *
 * Extracted from the legacy AccountController so the admin-as-self-provider flow
 * (Settings → Account → "Bookable provider" toggle) and the new self-service
 * Availability page (admin + staff with an active Provider row) share one
 * implementation.
 */
class ProviderScheduleService
{
    /**
     * Build a 7-day schedule payload from the business's working hours.
     *
     * @return array<int, array{day_of_week: int, enabled: bool, windows: array<int, array{open_time: string, close_time: string}>}>
     */
    public function buildScheduleFromBusinessHours(Business $business): array
    {
        $hours = $business->businessHours()
            ->orderBy('day_of_week')
            ->orderBy('open_time')
            ->get()
            ->groupBy('day_of_week');

        return collect(range(1, 7))->map(function (int $day) use ($hours) {
            $dayHours = $hours->get($day);

            if ($dayHours) {
                return [
                    'day_of_week' => $day,
                    'enabled' => true,
                    'windows' => $dayHours->map(fn ($h) => [
                        'open_time' => Str::substr($h->open_time, 0, 5),
                        'close_time' => Str::substr($h->close_time, 0, 5),
                    ])->values()->all(),
                ];
            }

            return [
                'day_of_week' => $day,
                'enabled' => false,
                'windows' => [],
            ];
        })->values()->all();
    }

    /**
     * Build a 7-day schedule payload from the provider's persisted availability rules.
     *
     * @return array<int, array{day_of_week: int, enabled: bool, windows: array<int, array{open_time: string, close_time: string}>}>
     */
    public function buildScheduleFromProvider(Provider $provider): array
    {
        $rules = $provider->availabilityRules()
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get()
            ->groupBy('day_of_week');

        return collect(range(1, 7))->map(function (int $day) use ($rules) {
            $dayRules = $rules->get($day);

            if ($dayRules) {
                return [
                    'day_of_week' => $day,
                    'enabled' => true,
                    'windows' => $dayRules->map(fn ($r) => [
                        'open_time' => Str::substr($r->start_time, 0, 5),
                        'close_time' => Str::substr($r->end_time, 0, 5),
                    ])->values()->all(),
                ];
            }

            return [
                'day_of_week' => $day,
                'enabled' => false,
                'windows' => [],
            ];
        })->values()->all();
    }

    /**
     * Replace the provider's availability rules with the given schedule.
     *
     * @param  array<int, array{day_of_week: int, enabled: bool, windows: array<int, array{open_time: string, close_time: string}>}>  $schedule
     */
    public function writeProviderSchedule(Provider $provider, Business $business, array $schedule): void
    {
        $provider->availabilityRules()->delete();

        $rules = collect($schedule)
            ->filter(fn (array $day) => ! empty($day['enabled']) && ! empty($day['windows']))
            ->flatMap(fn (array $day) => collect($day['windows'])->map(fn (array $window) => [
                'provider_id' => $provider->id,
                'business_id' => $business->id,
                'day_of_week' => $day['day_of_week'],
                'start_time' => $window['open_time'],
                'end_time' => $window['close_time'],
                'created_at' => now(),
                'updated_at' => now(),
            ]));

        if ($rules->isNotEmpty()) {
            $provider->availabilityRules()->insert($rules->all());
        }
    }

    /**
     * Seed the provider's schedule from the business's current working hours.
     */
    public function writeFromBusinessHours(Provider $provider, Business $business): void
    {
        $schedule = $this->buildScheduleFromBusinessHours($business);

        $this->writeProviderSchedule($provider, $business, $schedule);
    }
}
