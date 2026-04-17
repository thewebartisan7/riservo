<?php

namespace App\Http\Requests\Dashboard\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validates a password set OR change for the actor's own account.
 *
 * Branches on the actor's persisted password column (D-098):
 * - magic-link-only users (password === null) set a first password without
 *   supplying current_password
 * - users with an existing password must supply the matching current_password
 *
 * The branch reads server-side state, not a request flag, so a magic-link-only
 * user cannot bypass the current-password check by lying.
 */
class UpdateAccountPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'password' => ['required', 'confirmed', Password::defaults()],
        ];

        if ($this->user()->password !== null) {
            $rules['current_password'] = ['required', 'current_password'];
        }

        return $rules;
    }
}
