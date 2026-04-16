<?php

namespace App\Http\Requests\Dashboard\Settings;

use App\Enums\BusinessMemberRole;
use App\Models\Provider;
use App\Rules\BelongsToCurrentBusiness;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsServiceRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'buffer_before' => ['integer', 'min:0', 'max:120'],
            'buffer_after' => ['integer', 'min:0', 'max:120'],
            'slot_interval_minutes' => ['required', 'integer', Rule::in([5, 10, 15, 20, 30, 60])],
            'is_active' => ['boolean'],
            'provider_ids' => ['present', 'array'],
            'provider_ids.*' => ['integer', new BelongsToCurrentBusiness(Provider::class)],
        ];
    }
}
