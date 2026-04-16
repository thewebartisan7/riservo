<?php

namespace App\Http\Controllers\Auth;

use App\Enums\BusinessMemberRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Models\BusinessInvitation;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $invitation = $this->findPendingInvitation($token);

        $isExistingUser = User::where('email', $invitation->email)->exists();
        $sessionUser = $request->user();

        return Inertia::render('auth/accept-invitation', [
            'invitation' => [
                'token' => $invitation->token,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'business_name' => $invitation->business->name,
            ],
            'isExistingUser' => $isExistingUser,
            'authUserEmail' => $sessionUser?->email,
        ]);
    }

    public function accept(AcceptInvitationRequest $request, string $token): RedirectResponse
    {
        $invitation = $this->findPendingInvitation($token);

        if ($request->isExistingUser()) {
            return $this->acceptAsExistingUser($request, $invitation);
        }

        return $this->acceptAsNewUser($request, $invitation);
    }

    private function acceptAsNewUser(AcceptInvitationRequest $request, BusinessInvitation $invitation): RedirectResponse
    {
        $user = DB::transaction(function () use ($request, $invitation): User {
            $user = User::create([
                'name' => $request->validated('name'),
                'email' => $invitation->email,
                'password' => $request->validated('password'),
            ]);

            $user->markEmailAsVerified();

            $invitation->business->attachOrRestoreMember($user, BusinessMemberRole::Staff);

            $this->createProviderAndAttachServices($user, $invitation);

            $invitation->update(['accepted_at' => now()]);

            return $user;
        });

        Auth::login($user);

        $request->session()->put('current_business_id', $invitation->business_id);

        return redirect()->route('dashboard')->with('success', __('Welcome! You have joined :business.', [
            'business' => $invitation->business->name,
        ]));
    }

    private function acceptAsExistingUser(AcceptInvitationRequest $request, BusinessInvitation $invitation): RedirectResponse
    {
        $sessionUser = $request->user();

        if ($sessionUser !== null && $sessionUser->email !== $invitation->email) {
            return redirect()->route('invitation.show', ['token' => $invitation->token])
                ->with('error', __('You are signed in as :current. Please sign out to accept this invitation as :target.', [
                    'current' => $sessionUser->email,
                    'target' => $invitation->email,
                ]));
        }

        if ($sessionUser === null) {
            $authenticated = Auth::attempt([
                'email' => $invitation->email,
                'password' => $request->input('password'),
            ]);

            if (! $authenticated) {
                throw ValidationException::withMessages([
                    'password' => __('The provided password is incorrect.'),
                ]);
            }

            $request->session()->regenerate();
        }

        /** @var User $user */
        $user = Auth::user();

        DB::transaction(function () use ($user, $invitation): void {
            $invitation->business->attachOrRestoreMember($user, BusinessMemberRole::Staff);

            $this->createProviderAndAttachServices($user, $invitation);

            $invitation->update(['accepted_at' => now()]);
        });

        $request->session()->put('current_business_id', $invitation->business_id);

        return redirect()->route('dashboard')->with('success', __('Welcome! You have joined :business.', [
            'business' => $invitation->business->name,
        ]));
    }

    private function createProviderAndAttachServices(User $user, BusinessInvitation $invitation): void
    {
        $provider = Provider::create([
            'business_id' => $invitation->business_id,
            'user_id' => $user->id,
        ]);

        if ($invitation->service_ids) {
            $validServiceIds = Service::where('business_id', $invitation->business_id)
                ->whereIn('id', $invitation->service_ids)
                ->pluck('id');
            $provider->services()->attach($validServiceIds);
        }
    }

    private function findPendingInvitation(string $token): BusinessInvitation
    {
        $invitation = BusinessInvitation::with('business')->where('token', $token)->first();

        if (! $invitation) {
            abort(404);
        }

        if ($invitation->isAccepted()) {
            abort(410, __('This invitation has already been accepted.'));
        }

        if ($invitation->isExpired()) {
            abort(410, __('This invitation has expired.'));
        }

        return $invitation;
    }
}
