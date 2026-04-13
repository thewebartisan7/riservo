<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Models\BusinessInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    public function show(string $token): Response
    {
        $invitation = $this->findPendingInvitation($token);

        return Inertia::render('auth/accept-invitation', [
            'invitation' => [
                'token' => $invitation->token,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'business_name' => $invitation->business->name,
            ],
        ]);
    }

    public function accept(AcceptInvitationRequest $request, string $token): RedirectResponse
    {
        $invitation = $this->findPendingInvitation($token);

        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $invitation->email,
            'password' => $request->validated('password'),
        ]);

        $user->markEmailAsVerified();

        $invitation->business->users()->attach($user->id, [
            'role' => $invitation->role->value,
        ]);

        $invitation->update(['accepted_at' => now()]);

        Auth::login($user);

        return redirect()->route('dashboard')->with('success', __('Welcome! You have joined :business.', [
            'business' => $invitation->business->name,
        ]));
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
