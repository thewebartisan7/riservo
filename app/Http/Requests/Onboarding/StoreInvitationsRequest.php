<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvitationsRequest extends FormRequest
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
        $businessId = $this->user()->currentBusiness()->id;

        return [
            'invitations' => ['present', 'array'],
            'invitations.*.email' => ['required', 'email', 'max:255', 'distinct'],
            'invitations.*.service_ids' => ['nullable', 'array'],
            'invitations.*.service_ids.*' => [
                'integer',
                'exists:services,id',
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
