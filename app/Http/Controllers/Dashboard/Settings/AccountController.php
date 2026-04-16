<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Settings\StoreProviderExceptionRequest;
use App\Http\Requests\Dashboard\Settings\UpdateProviderExceptionRequest;
use App\Http\Requests\Dashboard\Settings\UpdateProviderScheduleRequest;
use App\Http\Requests\Dashboard\Settings\UpdateProviderServicesRequest;
use App\Models\AvailabilityException;
use App\Models\Business;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $business = $user->currentBusiness();

        $provider = Provider::withTrashed()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->first();

        $isProvider = $provider !== null && ! $provider->trashed();

        $schedule = $provider
            ? $this->buildScheduleFromProvider($provider)
            : $this->buildScheduleFromBusinessHours($business);

        $exceptions = $provider
            ? $provider->availabilityExceptions()
                ->orderByDesc('start_date')
                ->get()
                ->map(fn (AvailabilityException $e) => [
                    'id' => $e->id,
                    'start_date' => $e->start_date->format('Y-m-d'),
                    'end_date' => $e->end_date->format('Y-m-d'),
                    'start_time' => $e->start_time ? Str::substr($e->start_time, 0, 5) : null,
                    'end_time' => $e->end_time ? Str::substr($e->end_time, 0, 5) : null,
                    'type' => $e->type->value,
                    'reason' => $e->reason,
                ])
                ->values()
                ->all()
            : [];

        $services = $business->services()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function ($s) use ($provider, $isProvider) {
                $assigned = false;
                if ($isProvider) {
                    $assigned = $provider->services()->where('services.id', $s->id)->exists();
                }

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'assigned' => $assigned,
                ];
            })
            ->values()
            ->all();

        $upcomingBookingsCount = 0;
        if ($provider) {
            $upcomingBookingsCount = $provider->bookings()
                ->where('starts_at', '>=', now())
                ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Confirmed->value])
                ->count();
        }

        return Inertia::render('dashboard/settings/account', [
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar ? Storage::disk('public')->url($user->avatar) : null,
            ],
            'isProvider' => $isProvider,
            'hasProviderRow' => $provider !== null,
            'schedule' => $schedule,
            'exceptions' => $exceptions,
            'services' => $services,
            'upcomingBookingsCount' => $upcomingBookingsCount,
        ]);
    }

    public function toggleProvider(Request $request): RedirectResponse
    {
        $user = $request->user();
        $business = $user->currentBusiness();

        $provider = Provider::withTrashed()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->first();

        DB::transaction(function () use ($business, $user, $provider) {
            if ($provider === null) {
                $newProvider = Provider::create([
                    'business_id' => $business->id,
                    'user_id' => $user->id,
                ]);

                $this->writeScheduleFromBusinessHours($newProvider, $business);

                $activeServiceIds = $business->services()
                    ->where('is_active', true)
                    ->pluck('id')
                    ->all();

                if (! empty($activeServiceIds)) {
                    $newProvider->services()->syncWithoutDetaching($activeServiceIds);
                }

                return;
            }

            if ($provider->trashed()) {
                $provider->restore();

                return;
            }

            $provider->delete();
        });

        $flashKey = 'success';
        $flashMessage = __('Account updated.');

        $fresh = Provider::withTrashed()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->first();

        if ($fresh && $fresh->trashed()) {
            $unstaffed = $business->services()
                ->where('is_active', true)
                ->whereDoesntHave('providers')
                ->count();

            if ($unstaffed > 0) {
                $flashKey = 'warning';
                $flashMessage = __('You are no longer bookable. :count service(s) now have no provider — customers will not see them.', [
                    'count' => $unstaffed,
                ]);
            } else {
                $flashMessage = __('You are no longer bookable.');
            }
        } elseif ($fresh && ! $fresh->trashed() && $provider === null) {
            $flashMessage = __('You are now bookable.');
        } elseif ($fresh && ! $fresh->trashed()) {
            $flashMessage = __('You are bookable again.');
        }

        return redirect()->route('settings.account')->with($flashKey, $flashMessage);
    }

    public function updateSchedule(UpdateProviderScheduleRequest $request): RedirectResponse
    {
        $user = $request->user();
        $business = $user->currentBusiness();

        $provider = $this->activeProviderOrFail($user, $business);

        $this->writeProviderSchedule($provider, $business, $request->validated('rules'));

        return redirect()->route('settings.account')->with('success', __('Schedule updated.'));
    }

    public function storeException(StoreProviderExceptionRequest $request): RedirectResponse
    {
        $user = $request->user();
        $business = $user->currentBusiness();

        $provider = $this->activeProviderOrFail($user, $business);

        $provider->availabilityExceptions()->create(
            array_merge($request->validated(), ['business_id' => $business->id])
        );

        return redirect()->route('settings.account')->with('success', __('Exception added.'));
    }

    public function updateException(UpdateProviderExceptionRequest $request, AvailabilityException $exception): RedirectResponse
    {
        $user = $request->user();
        $business = $user->currentBusiness();

        $provider = $this->activeProviderOrFail($user, $business);

        if ($exception->business_id !== $business->id || $exception->provider_id !== $provider->id) {
            abort(403);
        }

        $exception->update($request->validated());

        return redirect()->route('settings.account')->with('success', __('Exception updated.'));
    }

    public function destroyException(Request $request, AvailabilityException $exception): RedirectResponse
    {
        $user = $request->user();
        $business = $user->currentBusiness();

        $provider = $this->activeProviderOrFail($user, $business);

        if ($exception->business_id !== $business->id || $exception->provider_id !== $provider->id) {
            abort(403);
        }

        $exception->delete();

        return redirect()->route('settings.account')->with('success', __('Exception deleted.'));
    }

    public function updateServices(UpdateProviderServicesRequest $request): RedirectResponse
    {
        $user = $request->user();
        $business = $user->currentBusiness();

        $provider = $this->activeProviderOrFail($user, $business);

        $provider->services()->sync($request->validated('service_ids'));

        return redirect()->route('settings.account')->with('success', __('Services updated.'));
    }

    private function activeProviderOrFail(User $user, Business $business): Provider
    {
        $provider = Provider::where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $provider) {
            abort(409, __('Turn on "Bookable provider" before editing your schedule.'));
        }

        return $provider;
    }

    /**
     * @return array<int, array{day_of_week: int, enabled: bool, windows: array<int, array{open_time: string, close_time: string}>}>
     */
    private function buildScheduleFromBusinessHours(Business $business): array
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
     * @return array<int, array{day_of_week: int, enabled: bool, windows: array<int, array{open_time: string, close_time: string}>}>
     */
    private function buildScheduleFromProvider(Provider $provider): array
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
     * @param  array<int, array{day_of_week: int, enabled: bool, windows: array<int, array{open_time: string, close_time: string}>}>  $schedule
     */
    private function writeProviderSchedule(Provider $provider, Business $business, array $schedule): void
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

    private function writeScheduleFromBusinessHours(Provider $provider, Business $business): void
    {
        $schedule = $this->buildScheduleFromBusinessHours($business);

        $this->writeProviderSchedule($provider, $business, $schedule);
    }
}
