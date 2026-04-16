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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        $user = DB::transaction(function () use ($request, $invitation): User {
            $user = User::create([
                'name' => $request->validated('name'),
                'email' => $invitation->email,
                'password' => $request->validated('password'),
            ]);

            $user->markEmailAsVerified();

            $invitation->business->members()->attach($user->id, [
                'role' => BusinessMemberRole::Staff->value,
            ]);

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

            $invitation->update(['accepted_at' => now()]);

            return $user;
        });

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
