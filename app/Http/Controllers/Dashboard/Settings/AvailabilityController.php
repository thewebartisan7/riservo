<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Enums\BookingStatus;
use App\Enums\BusinessMemberRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Settings\StoreProviderExceptionRequest;
use App\Http\Requests\Dashboard\Settings\UpdateProviderExceptionRequest;
use App\Http\Requests\Dashboard\Settings\UpdateProviderScheduleRequest;
use App\Http\Requests\Dashboard\Settings\UpdateProviderServicesRequest;
use App\Models\AvailabilityException;
use App\Models\Business;
use App\Models\Provider;
use App\Models\User;
use App\Services\ProviderScheduleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-service availability surface for admin + staff with an active Provider
 * row in the current business (D-096).
 *
 * Every action derives the active provider from auth()->user() + tenant() so
 * the controller cannot be coerced into writing another person's data via a
 * route binding. Service attachments (updateServices) are admin-only via route
 * middleware — staff see services read-only.
 */
class AvailabilityController extends Controller
{
    public function __construct(private readonly ProviderScheduleService $schedules) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $business = tenant()->business();
        $provider = $this->activeProviderOrFail($user, $business);

        $canEditServices = tenant()->role() === BusinessMemberRole::Admin;

        return Inertia::render('dashboard/settings/availability', [
            'schedule' => $this->schedules->buildScheduleFromProvider($provider),
            'exceptions' => $provider->availabilityExceptions()
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
                ->all(),
            'services' => $business->services()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'assigned' => $provider->services()->where('services.id', $s->id)->exists(),
                ])
                ->values()
                ->all(),
            'canEditServices' => $canEditServices,
            'upcomingBookingsCount' => $provider->bookings()
                ->where('starts_at', '>=', now())
                ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Confirmed->value])
                ->count(),
        ]);
    }

    public function updateSchedule(UpdateProviderScheduleRequest $request): RedirectResponse
    {
        $user = $request->user();
        $business = tenant()->business();
        $provider = $this->activeProviderOrFail($user, $business);

        $this->schedules->writeProviderSchedule($provider, $business, $request->validated('rules'));

        return redirect()->route('settings.availability')->with('success', __('Schedule updated.'));
    }

    public function storeException(StoreProviderExceptionRequest $request): RedirectResponse
    {
        $user = $request->user();
        $business = tenant()->business();
        $provider = $this->activeProviderOrFail($user, $business);

        $provider->availabilityExceptions()->create(
            array_merge($request->validated(), ['business_id' => $business->id])
        );

        return redirect()->route('settings.availability')->with('success', __('Exception added.'));
    }

    public function updateException(UpdateProviderExceptionRequest $request, AvailabilityException $exception): RedirectResponse
    {
        $user = $request->user();
        $business = tenant()->business();
        $provider = $this->activeProviderOrFail($user, $business);

        $this->ensureExceptionBelongsToActor($exception, $business, $provider);

        $exception->update($request->validated());

        return redirect()->route('settings.availability')->with('success', __('Exception updated.'));
    }

    public function destroyException(Request $request, AvailabilityException $exception): RedirectResponse
    {
        $user = $request->user();
        $business = tenant()->business();
        $provider = $this->activeProviderOrFail($user, $business);

        $this->ensureExceptionBelongsToActor($exception, $business, $provider);

        $exception->delete();

        return redirect()->route('settings.availability')->with('success', __('Exception deleted.'));
    }

    public function updateServices(UpdateProviderServicesRequest $request): RedirectResponse
    {
        $user = $request->user();
        $business = tenant()->business();
        $provider = $this->activeProviderOrFail($user, $business);

        $provider->services()->sync($request->validated('service_ids'));

        return redirect()->route('settings.availability')->with('success', __('Services updated.'));
    }

    private function activeProviderOrFail(User $user, Business $business): Provider
    {
        $provider = Provider::where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $provider) {
            abort(404);
        }

        return $provider;
    }

    private function ensureExceptionBelongsToActor(AvailabilityException $exception, Business $business, Provider $provider): void
    {
        if ($exception->business_id !== $business->id || $exception->provider_id !== $provider->id) {
            abort(403);
        }
    }
}
