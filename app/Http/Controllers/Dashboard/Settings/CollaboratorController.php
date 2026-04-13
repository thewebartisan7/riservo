<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Enums\BusinessUserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Settings\StoreCollaboratorExceptionRequest;
use App\Http\Requests\Dashboard\Settings\StoreCollaboratorInvitationRequest;
use App\Http\Requests\Dashboard\Settings\UpdateCollaboratorExceptionRequest;
use App\Http\Requests\Dashboard\Settings\UpdateCollaboratorScheduleRequest;
use App\Models\AvailabilityException;
use App\Models\BusinessInvitation;
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

class CollaboratorController extends Controller
{
    public function index(Request $request): Response
    {
        $business = $request->user()->currentBusiness();

        $collaborators = $business->collaborators()
            ->withCount(['services' => fn ($q) => $q->where('services.business_id', $business->id)])
            ->get(['users.id', 'users.name', 'users.email', 'users.avatar'])
            ->map(fn (User $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email,
                'avatar_url' => $c->avatar ? Storage::disk('public')->url($c->avatar) : null,
                'is_active' => $c->pivot->is_active,
                'services_count' => $c->services_count,
            ]);

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

        return Inertia::render('dashboard/settings/collaborators/index', [
            'collaborators' => $collaborators,
            'invitations' => $invitations,
            'services' => $services,
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        $business = $request->user()->currentBusiness();
        $this->ensureCollaboratorBelongsToBusiness($user, $business);
        $collaboratorWithPivot = $this->getCollaboratorWithPivot($user, $business);

        $rules = $user->availabilityRules()
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get()
            ->groupBy('day_of_week');

        $schedule = collect(range(1, 7))->map(function (int $day) use ($rules) {
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

        $exceptions = $business->availabilityExceptions()
            ->where('collaborator_id', $user->id)
            ->orderByDesc('start_date')
            ->get()
            ->map(fn (AvailabilityException $e) => [
                'id' => $e->id,
                'start_date' => $e->start_date->format('Y-m-d'),
                'end_date' => $e->end_date->format('Y-m-d'),
                'start_time' => $e->start_time ? substr($e->start_time, 0, 5) : null,
                'end_time' => $e->end_time ? substr($e->end_time, 0, 5) : null,
                'type' => $e->type->value,
                'reason' => $e->reason,
            ]);

        $services = $business->services()
            ->where('is_active', true)
            ->with(['collaborators' => fn ($q) => $q->where('users.id', $user->id)->select('users.id')])
            ->get(['id', 'name'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'assigned' => $s->collaborators->isNotEmpty(),
            ]);

        return Inertia::render('dashboard/settings/collaborators/show', [
            'collaborator' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar ? Storage::disk('public')->url($user->avatar) : null,
                'is_active' => $collaboratorWithPivot->pivot->is_active,
            ],
            'schedule' => $schedule,
            'exceptions' => $exceptions,
            'services' => $services,
        ]);
    }

    public function updateSchedule(UpdateCollaboratorScheduleRequest $request, User $user): RedirectResponse
    {
        $business = $request->user()->currentBusiness();
        $this->ensureCollaboratorBelongsToBusiness($user, $business);

        $user->availabilityRules()->delete();

        $rules = collect($request->validated('rules'))
            ->filter(fn (array $day) => $day['enabled'] && ! empty($day['windows']))
            ->flatMap(fn (array $day) => collect($day['windows'])->map(fn (array $window) => [
                'collaborator_id' => $user->id,
                'business_id' => $business->id,
                'day_of_week' => $day['day_of_week'],
                'start_time' => $window['open_time'],
                'end_time' => $window['close_time'],
                'created_at' => now(),
                'updated_at' => now(),
            ]));

        if ($rules->isNotEmpty()) {
            $user->availabilityRules()->insert($rules->all());
        }

        return redirect()->route('settings.collaborators.show', $user)->with('success', __('Schedule updated.'));
    }

    public function storeException(StoreCollaboratorExceptionRequest $request, User $user): RedirectResponse
    {
        $business = $request->user()->currentBusiness();
        $this->ensureCollaboratorBelongsToBusiness($user, $business);

        $business->availabilityExceptions()->create(
            array_merge($request->validated(), ['collaborator_id' => $user->id])
        );

        return redirect()->route('settings.collaborators.show', $user)->with('success', __('Exception added.'));
    }

    public function updateException(UpdateCollaboratorExceptionRequest $request, User $user, AvailabilityException $exception): RedirectResponse
    {
        $business = $request->user()->currentBusiness();
        $this->ensureCollaboratorBelongsToBusiness($user, $business);

        if ($exception->business_id !== $business->id || $exception->collaborator_id !== $user->id) {
            abort(403);
        }

        $exception->update($request->validated());

        return redirect()->route('settings.collaborators.show', $user)->with('success', __('Exception updated.'));
    }

    public function destroyException(Request $request, User $user, AvailabilityException $exception): RedirectResponse
    {
        $business = $request->user()->currentBusiness();
        $this->ensureCollaboratorBelongsToBusiness($user, $business);

        if ($exception->business_id !== $business->id || $exception->collaborator_id !== $user->id) {
            abort(403);
        }

        $exception->delete();

        return redirect()->route('settings.collaborators.show', $user)->with('success', __('Exception deleted.'));
    }

    public function invite(StoreCollaboratorInvitationRequest $request): RedirectResponse
    {
        $business = $request->user()->currentBusiness();
        $validated = $request->validated();

        $exists = $business->invitations()
            ->where('email', $validated['email'])
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists();

        if ($exists) {
            return redirect()->route('settings.collaborators')
                ->with('error', __('An invitation has already been sent to this email.'));
        }

        $alreadyMember = $business->users()->where('email', $validated['email'])->exists();
        if ($alreadyMember) {
            return redirect()->route('settings.collaborators')
                ->with('error', __('This email is already a member of your business.'));
        }

        $invitation = BusinessInvitation::create([
            'business_id' => $business->id,
            'email' => $validated['email'],
            'role' => BusinessUserRole::Collaborator,
            'token' => Str::random(64),
            'service_ids' => $validated['service_ids'] ?? null,
            'expires_at' => now()->addHours(48),
        ]);

        Notification::route('mail', $validated['email'])
            ->notify(new InvitationNotification($invitation, $business->name));

        return redirect()->route('settings.collaborators')->with('success', __('Invitation sent.'));
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

        return redirect()->route('settings.collaborators')->with('success', __('Invitation resent.'));
    }

    public function cancelInvitation(Request $request, BusinessInvitation $invitation): RedirectResponse
    {
        $business = $request->user()->currentBusiness();

        if ($invitation->business_id !== $business->id) {
            abort(403);
        }

        $invitation->delete();

        return redirect()->route('settings.collaborators')->with('success', __('Invitation cancelled.'));
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        $business = $request->user()->currentBusiness();
        $this->ensureCollaboratorBelongsToBusiness($user, $business);

        $collaboratorWithPivot = $this->getCollaboratorWithPivot($user, $business);
        $isActive = $collaboratorWithPivot->pivot->is_active;
        $business->collaborators()->updateExistingPivot($user->id, ['is_active' => ! $isActive]);

        $message = $isActive ? __('Collaborator deactivated.') : __('Collaborator activated.');

        return redirect()->route('settings.collaborators')->with('success', $message);
    }

    public function uploadAvatar(Request $request, User $user): JsonResponse
    {
        $business = $request->user()->currentBusiness();
        $this->ensureCollaboratorBelongsToBusiness($user, $business);

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

    private function ensureCollaboratorBelongsToBusiness(User $user, mixed $business): void
    {
        $belongs = $business->collaborators()->where('users.id', $user->id)->exists();

        if (! $belongs) {
            abort(403);
        }
    }

    private function getCollaboratorWithPivot(User $user, mixed $business): User
    {
        return $business->collaborators()->where('users.id', $user->id)->firstOrFail();
    }
}
