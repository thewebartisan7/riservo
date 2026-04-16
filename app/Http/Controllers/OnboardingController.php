<?php

namespace App\Http\Controllers;

use App\Enums\BusinessMemberRole;
use App\Http\Requests\Onboarding\StoreHoursRequest;
use App\Http\Requests\Onboarding\StoreInvitationsRequest;
use App\Http\Requests\Onboarding\StoreProfileRequest;
use App\Http\Requests\Onboarding\StoreServiceRequest;
use App\Models\Business;
use App\Models\BusinessInvitation;
use App\Models\Provider;
use App\Models\Service;
use App\Notifications\InvitationNotification;
use App\Services\SlugService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function show(Request $request, int $step): Response|RedirectResponse
    {
        $business = tenant()->business();

        if ($business->isOnboarded()) {
            return redirect()->route('dashboard');
        }

        if ($step > $business->onboarding_step) {
            return redirect()->route('onboarding.show', ['step' => $business->onboarding_step]);
        }

        return match ($step) {
            1 => $this->showProfile($business),
            2 => $this->showHours($business),
            3 => $this->showService($business, $request),
            4 => $this->showInvitations($business),
            5 => $this->showSummary($business),
            default => abort(404),
        };
    }

    public function store(Request $request, int $step): RedirectResponse
    {
        $business = tenant()->business();

        if ($business->isOnboarded()) {
            return redirect()->route('dashboard');
        }

        return match ($step) {
            1 => $this->storeProfile(app(StoreProfileRequest::class), $business),
            2 => $this->storeHours(app(StoreHoursRequest::class), $business),
            3 => $this->storeService(app(StoreServiceRequest::class), $business),
            4 => $this->storeInvitations(app(StoreInvitationsRequest::class), $business),
            5 => $this->storeLaunch($business),
            default => abort(404),
        };
    }

    public function enableOwnerAsProvider(Request $request): RedirectResponse
    {
        $business = tenant()->business();
        $user = $request->user();

        DB::transaction(function () use ($business, $user) {
            $provider = Provider::withTrashed()
                ->where('business_id', $business->id)
                ->where('user_id', $user->id)
                ->first();

            if ($provider) {
                if ($provider->trashed()) {
                    $provider->restore();
                }
            } else {
                $provider = Provider::create([
                    'business_id' => $business->id,
                    'user_id' => $user->id,
                ]);
            }

            if ($provider->availabilityRules()->doesntExist()) {
                $this->writeScheduleFromBusinessHours($provider, $business);
            }

            $activeServiceIds = $business->services()
                ->where('is_active', true)
                ->pluck('id')
                ->all();

            if (! empty($activeServiceIds)) {
                $provider->services()->syncWithoutDetaching($activeServiceIds);
            }
        });

        return redirect()->route('onboarding.show', ['step' => 5])
            ->with('success', __('You are now bookable — launch when you are ready.'));
    }

    public function checkSlug(Request $request): JsonResponse
    {
        $request->validate([
            'slug' => ['required', 'string', 'max:255'],
        ]);

        $slug = $request->input('slug');
        $business = tenant()->business();
        $slugService = app(SlugService::class);

        $isOwn = $business->slug === $slug;
        $isReserved = $slugService->isReserved($slug);
        $isTaken = $slugService->isTakenExcluding($slug, $business->id);
        $isValidFormat = (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);

        return response()->json([
            'available' => $isValidFormat && ! $isReserved && ($isOwn || ! $isTaken),
        ]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', File::image()->max(2048)->types(['jpg', 'jpeg', 'png', 'webp'])],
        ]);

        $business = tenant()->business();

        // Delete old logo if exists
        if ($business->logo && Storage::disk('public')->exists($business->logo)) {
            Storage::disk('public')->delete($business->logo);
        }

        $path = $request->file('logo')->store('logos', 'public');

        $business->update(['logo' => $path]);

        return response()->json([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ]);
    }

    // --- Show methods ---

    private function showProfile(Business $business): Response
    {
        return Inertia::render('onboarding/step-1', [
            'business' => $business->only('name', 'slug', 'description', 'logo', 'phone', 'email', 'address'),
            'logoUrl' => $business->logo ? Storage::disk('public')->url($business->logo) : null,
        ]);
    }

    private function showHours(Business $business): Response
    {
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

            // Defaults: Mon-Fri open 09:00-18:00, Sat-Sun closed
            return [
                'day_of_week' => $day,
                'enabled' => $day <= 5,
                'windows' => $day <= 5 ? [['open_time' => '09:00', 'close_time' => '18:00']] : [],
            ];
        })->values()->all();

        return Inertia::render('onboarding/step-2', [
            'hours' => $hours,
        ]);
    }

    private function showService(Business $business, Request $request): Response
    {
        $service = $business->services()->first();
        $user = $request->user();

        $adminProviderRow = Provider::withTrashed()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->first();

        $isActiveProvider = $adminProviderRow !== null && ! $adminProviderRow->trashed();

        $hoursFromBusiness = $this->buildScheduleFromBusinessHours($business);
        $schedule = $isActiveProvider
            ? $this->buildScheduleFromProvider($adminProviderRow)
            : $hoursFromBusiness;

        $serviceIds = [];
        if ($isActiveProvider && $service) {
            $serviceIds = $adminProviderRow->services()
                ->where('services.business_id', $business->id)
                ->pluck('services.id')
                ->all();
        }

        $hasOtherProviders = Provider::where('business_id', $business->id)
            ->where('user_id', '!=', $user->id)
            ->exists();

        return Inertia::render('onboarding/step-3', [
            'service' => $service?->only('id', 'name', 'duration_minutes', 'price', 'buffer_before', 'buffer_after', 'slot_interval_minutes'),
            'adminProvider' => [
                'exists' => $isActiveProvider,
                'schedule' => $schedule,
                'serviceIds' => $serviceIds,
            ],
            'businessHoursSchedule' => $hoursFromBusiness,
            'hasOtherProviders' => $hasOtherProviders,
        ]);
    }

    private function showInvitations(Business $business): Response
    {
        return Inertia::render('onboarding/step-4', [
            'services' => $business->services()->select('id', 'name')->get(),
            'pendingInvitations' => $business->invitations()
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->get(['id', 'email', 'service_ids']),
        ]);
    }

    private function showSummary(Business $business): Response
    {
        $hours = $business->businessHours()
            ->orderBy('day_of_week')
            ->orderBy('open_time')
            ->get()
            ->groupBy('day_of_week')
            ->map(function ($group, $day) {
                return [
                    'day_of_week' => $day,
                    'windows' => $group->map(fn ($h) => [
                        'open_time' => Str::substr($h->open_time, 0, 5),
                        'close_time' => Str::substr($h->close_time, 0, 5),
                    ])->values()->all(),
                ];
            })->values()->all();

        $service = $business->services()->first();

        $invitations = $business->invitations()
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->get(['email', 'service_ids']);

        return Inertia::render('onboarding/step-5', [
            'business' => $business->only('name', 'slug', 'description', 'logo', 'phone', 'email', 'address'),
            'logoUrl' => $business->logo ? Storage::disk('public')->url($business->logo) : null,
            'hours' => $hours,
            'service' => $service?->only('name', 'duration_minutes', 'price'),
            'invitations' => $invitations,
            'publicUrl' => url($business->slug),
        ]);
    }

    // --- Store methods ---

    private function storeProfile(StoreProfileRequest $request, Business $business): RedirectResponse
    {
        $business->update($request->validated());

        $this->advanceStep($business, 2);

        return redirect()->route('onboarding.show', ['step' => 2]);
    }

    private function storeHours(StoreHoursRequest $request, Business $business): RedirectResponse
    {
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

        $this->advanceStep($business, 3);

        return redirect()->route('onboarding.show', ['step' => 3]);
    }

    private function storeService(StoreServiceRequest $request, Business $business): RedirectResponse
    {
        $validated = $request->validated();
        $optIn = (bool) ($validated['provider_opt_in'] ?? false);
        $providerSchedule = $validated['provider_schedule'] ?? null;

        unset($validated['provider_opt_in'], $validated['provider_schedule']);

        $validated['slug'] = Str::slug($validated['name']);

        // Ensure unique slug within business
        $baseSlug = $validated['slug'];
        $counter = 2;
        while ($business->services()->where('slug', $validated['slug'])->exists()) {
            $validated['slug'] = $baseSlug.'-'.$counter;
            $counter++;
        }

        $user = $request->user();

        DB::transaction(function () use ($business, $user, $validated, $optIn, $providerSchedule) {
            $existingService = $business->services()->first();

            if ($existingService) {
                $existingService->update($validated);
                $service = $existingService;
            } else {
                $service = $business->services()->create($validated);
            }

            if ($optIn) {
                $provider = Provider::withTrashed()
                    ->where('business_id', $business->id)
                    ->where('user_id', $user->id)
                    ->first();

                if ($provider) {
                    if ($provider->trashed()) {
                        $provider->restore();
                    }
                } else {
                    $provider = Provider::create([
                        'business_id' => $business->id,
                        'user_id' => $user->id,
                    ]);
                }

                if ($providerSchedule !== null) {
                    $this->writeProviderSchedule($provider, $business, $providerSchedule);
                }

                $provider->services()->syncWithoutDetaching([$service->id]);
            } else {
                $provider = Provider::where('business_id', $business->id)
                    ->where('user_id', $user->id)
                    ->first();

                if ($provider) {
                    $provider->services()->detach($service->id);
                }
            }
        });

        $this->advanceStep($business, 4);

        return redirect()->route('onboarding.show', ['step' => 4]);
    }

    private function storeInvitations(StoreInvitationsRequest $request, Business $business): RedirectResponse
    {
        $invitations = $request->validated('invitations');

        foreach ($invitations as $invitationData) {
            // Skip if invitation already exists for this email
            $exists = $business->invitations()
                ->where('email', $invitationData['email'])
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->exists();

            if ($exists) {
                continue;
            }

            // Defense-in-depth: re-filter service_ids through this business's relation
            // before persisting them to the JSON column. Validation already rejects
            // cross-tenant ids via BelongsToCurrentBusiness, but we refuse to trust the
            // payload when writing a blob that downstream code will read later.
            $rawServiceIds = $invitationData['service_ids'] ?? null;
            $scopedServiceIds = null;

            if (! empty($rawServiceIds)) {
                $scopedServiceIds = $business->services()
                    ->whereIn('id', $rawServiceIds)
                    ->pluck('id')
                    ->values()
                    ->all();

                if (empty($scopedServiceIds)) {
                    $scopedServiceIds = null;
                }
            }

            $invitation = BusinessInvitation::create([
                'business_id' => $business->id,
                'email' => $invitationData['email'],
                'role' => BusinessMemberRole::Staff,
                'token' => Str::random(64),
                'service_ids' => $scopedServiceIds,
                'expires_at' => now()->addHours(48),
            ]);

            Notification::route('mail', $invitationData['email'])
                ->notify(new InvitationNotification($invitation, $business->name));
        }

        $this->advanceStep($business, 5);

        return redirect()->route('onboarding.show', ['step' => 5]);
    }

    private function storeLaunch(Business $business): RedirectResponse
    {
        $unstaffedServices = $business->services()
            ->where('is_active', true)
            ->whereDoesntHave('providers')
            ->get(['id', 'name'])
            ->map(fn (Service $s) => ['id' => $s->id, 'name' => $s->name])
            ->values()
            ->all();

        if (! empty($unstaffedServices)) {
            if ($business->onboarding_step < 3) {
                $business->update(['onboarding_step' => 3]);
            }

            return redirect()->route('onboarding.show', ['step' => 3])
                ->with('launchBlocked', ['services' => $unstaffedServices]);
        }

        $business->update([
            'onboarding_completed_at' => now(),
        ]);

        return redirect()->route('dashboard.welcome');
    }

    private function advanceStep(Business $business, int $nextStep): void
    {
        if ($business->onboarding_step < $nextStep) {
            $business->update(['onboarding_step' => $nextStep]);
        }
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
