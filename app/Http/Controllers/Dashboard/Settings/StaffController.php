<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Enums\BusinessMemberRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Settings\StoreStaffInvitationRequest;
use App\Models\Business;
use App\Models\BusinessInvitation;
use App\Models\Provider;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function index(Request $request): Response
    {
        $business = $request->user()->currentBusiness();
        $currentUserId = $request->user()->id;

        $members = $business->members()
            ->orderByRaw("CASE business_members.role WHEN 'admin' THEN 0 ELSE 1 END")
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email', 'users.avatar'])
            ->map(function (User $u) use ($business, $currentUserId) {
                $provider = Provider::withTrashed()
                    ->where('business_id', $business->id)
                    ->where('user_id', $u->id)
                    ->first();

                $isProvider = $provider !== null && $provider->deleted_at === null;

                $servicesCount = 0;
                if ($isProvider) {
                    $servicesCount = $provider->services()
                        ->where('services.business_id', $business->id)
                        ->count();
                }

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'avatar_url' => $u->avatar ? Storage::disk('public')->url($u->avatar) : null,
                    'role' => $u->pivot->role->value,
                    'is_provider' => $isProvider,
                    'is_self' => $u->id === $currentUserId,
                    'is_active' => $isProvider,
                    'provider_id' => $provider?->id,
                    'services_count' => $servicesCount,
                ];
            });

        $invitations = $business->invitations()
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->get(['id', 'email', 'service_ids', 'created_at', 'expires_at'])
            ->map(fn (BusinessInvitation $i) => [
                'id' => $i->id,
                'email' => $i->email,
                'service_ids' => $i->service_ids,
                'created_at' => $i->created_at->toISOString(),
                'expires_at' => $i->expires_at->toISOString(),
            ]);

        $services = $business->services()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]);

        return Inertia::render('dashboard/settings/staff/index', [
            'staff' => $members,
            'invitations' => $invitations,
            'services' => $services,
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        $business = $request->user()->currentBusiness();
        $this->ensureUserBelongsToBusiness($user, $business);

        $provider = Provider::withTrashed()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->first();

        $schedule = $this->buildSchedule($provider);

        $exceptions = $provider
            ? $provider->availabilityExceptions()
                ->where('business_id', $business->id)
                ->orderByDesc('start_date')
                ->get()
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'start_date' => $e->start_date->format('Y-m-d'),
                    'end_date' => $e->end_date->format('Y-m-d'),
                    'start_time' => $e->start_time ? substr($e->start_time, 0, 5) : null,
                    'end_time' => $e->end_time ? substr($e->end_time, 0, 5) : null,
                    'type' => $e->type->value,
                    'reason' => $e->reason,
                ])
            : collect();

        $services = $business->services()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->map(function ($s) use ($provider) {
                $assigned = false;
                if ($provider && $provider->deleted_at === null) {
                    $assigned = $provider->services()->where('services.id', $s->id)->exists();
                }

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'assigned' => $assigned,
                ];
            });

        return Inertia::render('dashboard/settings/staff/show', [
            'member' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar ? Storage::disk('public')->url($user->avatar) : null,
                'is_active' => $provider !== null && $provider->deleted_at === null,
                'provider_id' => $provider?->id,
            ],
            'schedule' => $schedule,
            'exceptions' => $exceptions,
            'services' => $services,
        ]);
    }

    public function invite(StoreStaffInvitationRequest $request): RedirectResponse
    {
        $business = $request->user()->currentBusiness();
        $validated = $request->validated();

        $exists = $business->invitations()
            ->where('email', $validated['email'])
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists();

        if ($exists) {
            return redirect()->route('settings.staff')
                ->with('error', __('An invitation has already been sent to this email.'));
        }

        $alreadyMember = $business->members()->where('email', $validated['email'])->exists();
        if ($alreadyMember) {
            return redirect()->route('settings.staff')
                ->with('error', __('This email is already a member of your business.'));
        }

        $invitation = BusinessInvitation::create([
            'business_id' => $business->id,
            'email' => $validated['email'],
            'role' => BusinessMemberRole::Staff,
            'token' => Str::random(64),
            'service_ids' => $validated['service_ids'] ?? null,
            'expires_at' => now()->addHours(48),
        ]);

        Notification::route('mail', $validated['email'])
            ->notify(new InvitationNotification($invitation, $business->name));

        return redirect()->route('settings.staff')->with('success', __('Invitation sent.'));
    }

    public function resendInvitation(Request $request, BusinessInvitation $invitation): RedirectResponse
    {
        $business = $request->user()->currentBusiness();

        if ($invitation->business_id !== $business->id) {
            abort(403);
        }

        $invitation->update([
            'token' => Str::random(64),
            'expires_at' => now()->addHours(48),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new InvitationNotification($invitation, $business->name));

        return redirect()->route('settings.staff')->with('success', __('Invitation resent.'));
    }

    public function cancelInvitation(Request $request, BusinessInvitation $invitation): RedirectResponse
    {
        $business = $request->user()->currentBusiness();

        if ($invitation->business_id !== $business->id) {
            abort(403);
        }

        $invitation->delete();

        return redirect()->route('settings.staff')->with('success', __('Invitation cancelled.'));
    }

    public function uploadAvatar(Request $request, User $user): JsonResponse
    {
        $business = $request->user()->currentBusiness();
        $this->ensureUserBelongsToBusiness($user, $business);

        $request->validate([
            'avatar' => ['required', File::image()->max(2048)->types(['jpg', 'jpeg', 'png', 'webp'])],
        ]);

        if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar' => $path]);

        return response()->json([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ]);
    }

    private function ensureUserBelongsToBusiness(User $user, Business $business): void
    {
        $belongs = $business->members()->where('users.id', $user->id)->exists();

        if (! $belongs) {
            abort(403);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildSchedule(?Provider $provider): array
    {
        $rules = $provider
            ? $provider->availabilityRules()
                ->orderBy('day_of_week')
                ->orderBy('start_time')
                ->get()
                ->groupBy('day_of_week')
            : collect();

        return collect(range(1, 7))->map(function (int $day) use ($rules) {
            $dayRules = $rules->get($day);

            if ($dayRules) {
                return [
                    'day_of_week' => $day,
                    'enabled' => true,
                    'windows' => $dayRules->map(fn ($r) => [
                        'open_time' => substr($r->start_time, 0, 5),
                        'close_time' => substr($r->end_time, 0, 5),
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
}
