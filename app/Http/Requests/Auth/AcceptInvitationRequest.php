<?php

namespace App\Http\Requests\Auth;

use App\Models\BusinessInvitation;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Per D-079, invitation acceptance splits on whether the invitee's email
 * already belongs to a User. The new-user branch requires name + password.
 * The existing-user branch requires only the existing account's password
 * (skipped entirely when the session user already IS the invitee).
 */
class AcceptInvitationRequest extends FormRequest
{
    private ?BusinessInvitation $invitation = null;

    private ?bool $isExistingUser = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->isExistingUser()) {
            if ($this->sessionUserMatchesInvitation()) {
                return [];
            }

            return [
                'password' => ['required', 'string'],
            ];
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    public function isExistingUser(): bool
    {
        if ($this->isExistingUser !== null) {
            return $this->isExistingUser;
        }

        $invitation = $this->invitation();

        return $this->isExistingUser = $invitation !== null
            && User::where('email', $invitation->email)->exists();
    }

    public function invitation(): ?BusinessInvitation
    {
        if ($this->invitation !== null) {
            return $this->invitation;
        }

        $token = $this->route('token');

        if (! is_string($token) || $token === '') {
            return null;
        }

        return $this->invitation = BusinessInvitation::where('token', $token)->first();
    }

    public function sessionUserMatchesInvitation(): bool
    {
        $user = $this->user();
        $invitation = $this->invitation();

        return $user !== null
            && $invitation !== null
            && $user->email === $invitation->email;
    }
}
