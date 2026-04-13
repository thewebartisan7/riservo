<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Settings\UpdateWorkingHoursRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WorkingHoursController extends Controller
{
    public function edit(Request $request): Response
    {
        $business = $request->user()->currentBusiness();

        $existingHours = $business->businessHours()
            ->orderBy('day_of_week')
            ->orderBy('open_time')
            ->get()
            ->groupBy('day_of_week');

        $hours = collect(range(1, 7))->map(function (int $day) use ($existingHours) {
            $dayHours = $existingHours->get($day);

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

        return Inertia::render('dashboard/settings/hours', [
            'hours' => $hours,
        ]);
    }

    public function update(UpdateWorkingHoursRequest $request): RedirectResponse
    {
        $business = $request->user()->currentBusiness();

        $business->businessHours()->delete();

        $hours = collect($request->validated('hours'))
            ->filter(fn (array $day) => $day['enabled'] && ! empty($day['windows']))
            ->flatMap(fn (array $day) => collect($day['windows'])->map(fn (array $window) => [
                'business_id' => $business->id,
                'day_of_week' => $day['day_of_week'],
                'open_time' => $window['open_time'],
                'close_time' => $window['close_time'],
                'created_at' => now(),
                'updated_at' => now(),
            ]));

        if ($hours->isNotEmpty()) {
            $business->businessHours()->insert($hours->all());
        }

        return redirect()->route('settings.hours')->with('success', __('Working hours updated.'));
    }
}
