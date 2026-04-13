<?php

namespace App\Http\Controllers;

use App\Enums\BusinessUserRole;
use App\Http\Requests\Onboarding\StoreHoursRequest;
use App\Http\Requests\Onboarding\StoreInvitationsRequest;
use App\Http\Requests\Onboarding\StoreProfileRequest;
use App\Http\Requests\Onboarding\StoreServiceRequest;
use App\Models\Business;
use App\Models\BusinessInvitation;
use App\Notifications\InvitationNotification;
use App\Services\SlugService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $business = $request->user()->currentBusiness();

        if ($business->isOnboarded()) {
            return redirect()->route('dashboard');
        }

        if ($step > $business->onboarding_step) {
            return redirect()->route('onboarding.show', ['step' => $business->onboarding_step]);
        }

        return match ($step) {
            1 => $this->showProfile($business),
            2 => $this->showHours($business),
            3 => $this->showService($business),
            4 => $this->showInvitations($business),
            5 => $this->showSummary($business),
            default => abort(404),
        };
    }

    public function store(Request $request, int $step): RedirectResponse
    {
        $business = $request->user()->currentBusiness();

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

    public function checkSlug(Request $request): JsonResponse
    {
        $request->validate([
            'slug' => ['required', 'string', 'max:255'],
        ]);

        $slug = $request->input('slug');
        $business = $request->user()->currentBusiness();
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

        $business = $request->user()->currentBusiness();

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

    private function showService(Business $business): Response
    {
        $service = $business->services()->first();

        return Inertia::render('onboarding/step-3', [
            'service' => $service?->only('id', 'name', 'duration_minutes', 'price', 'buffer_before', 'buffer_after', 'slot_interval_minutes'),
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
        $validated['slug'] = Str::slug($validated['name']);

        // Ensure unique slug within business
        $baseSlug = $validated['slug'];
        $counter = 2;
        while ($business->services()->where('slug', $validated['slug'])->exists()) {
            $validated['slug'] = $baseSlug.'-'.$counter;
            $counter++;
        }

        $existingService = $business->services()->first();

        if ($existingService) {
            $existingService->update($validated);
        } else {
            $business->services()->create($validated);
        }

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

            $invitation = BusinessInvitation::create([
                'business_id' => $business->id,
                'email' => $invitationData['email'],
                'role' => BusinessUserRole::Collaborator,
                'token' => Str::random(64),
                'service_ids' => $invitationData['service_ids'] ?? null,
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
}
