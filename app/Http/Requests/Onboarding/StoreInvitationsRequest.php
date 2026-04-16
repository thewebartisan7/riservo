<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\BusinessMemberRole;
use App\Models\Service;
use App\Rules\BelongsToCurrentBusiness;
use Illuminate\Foundation\Http\FormRequest;

class StoreInvitationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return tenant()->role() === BusinessMemberRole::Admin;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'invitations' => ['present', 'array'],
            'invitations.*.email' => ['required', 'email', 'max:255', 'distinct'],
            'invitations.*.service_ids' => ['nullable', 'array'],
            'invitations.*.service_ids.*' => [
                'integer',
                new BelongsToCurrentBusiness(Service::class),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'invitations.*.email.distinct' => __('Each staff member must have a unique email address.'),
        ];
    }
}
