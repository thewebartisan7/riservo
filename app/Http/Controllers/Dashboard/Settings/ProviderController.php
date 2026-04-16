<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Settings\StoreProviderExceptionRequest;
use App\Http\Requests\Dashboard\Settings\UpdateProviderExceptionRequest;
use App\Http\Requests\Dashboard\Settings\UpdateProviderScheduleRequest;
use App\Http\Requests\Dashboard\Settings\UpdateProviderServicesRequest;
use App\Models\AvailabilityException;
use App\Models\Business;
use App\Models\Provider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProviderController extends Controller
{
    public function toggle(Request $request, Provider $provider): RedirectResponse
    {
        $business = $request->user()->currentBusiness();
        $this->ensureProviderBelongsToBusiness($provider, $business);

        if ($provider->trashed()) {
            $provider->restore();
            $message = __('Provider activated.');
        } else {
            $provider->delete();
            $message = __('Provider deactivated.');
        }

        return redirect()->route('settings.staff.show', $provider->user)->with('success', $message);
    }

    public function updateSchedule(UpdateProviderScheduleRequest $request, Provider $provider): RedirectResponse
    {
        $business = $request->user()->currentBusiness();
        $this->ensureProviderBelongsToBusiness($provider, $business);

        $provider->availabilityRules()->delete();

        $rules = collect($request->validated('rules'))
            ->filter(fn (array $day) => $day['enabled'] && ! empty($day['windows']))
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

        return redirect()->route('settings.staff.show', $provider->user)->with('success', __('Schedule updated.'));
    }

    public function storeException(StoreProviderExceptionRequest $request, Provider $provider): RedirectResponse
    {
        $business = $request->user()->currentBusiness();
        $this->ensureProviderBelongsToBusiness($provider, $business);

        $provider->availabilityExceptions()->create(
            array_merge($request->validated(), ['business_id' => $business->id])
        );

        return redirect()->route('settings.staff.show', $provider->user)->with('success', __('Exception added.'));
    }

    public function updateException(UpdateProviderExceptionRequest $request, Provider $provider, AvailabilityException $exception): RedirectResponse
    {
        $business = $request->user()->currentBusiness();
        $this->ensureProviderBelongsToBusiness($provider, $business);

        if ($exception->business_id !== $business->id || $exception->provider_id !== $provider->id) {
            abort(403);
        }

        $exception->update($request->validated());

        return redirect()->route('settings.staff.show', $provider->user)->with('success', __('Exception updated.'));
    }

    public function destroyException(Request $request, Provider $provider, AvailabilityException $exception): RedirectResponse
    {
        $business = $request->user()->currentBusiness();
        $this->ensureProviderBelongsToBusiness($provider, $business);

        if ($exception->business_id !== $business->id || $exception->provider_id !== $provider->id) {
            abort(403);
        }

        $exception->delete();

        return redirect()->route('settings.staff.show', $provider->user)->with('success', __('Exception deleted.'));
    }

    public function syncServices(UpdateProviderServicesRequest $request, Provider $provider): RedirectResponse
    {
        $business = $request->user()->currentBusiness();
        $this->ensureProviderBelongsToBusiness($provider, $business);

        $provider->services()->sync($request->validated('service_ids'));

        return redirect()->route('settings.staff.show', $provider->user)->with('success', __('Services updated.'));
    }

    private function ensureProviderBelongsToBusiness(Provider $provider, Business $business): void
    {
        if ($provider->business_id !== $business->id) {
            abort(403);
        }
    }
}
