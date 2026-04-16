<?php

namespace App\Http\Requests\Dashboard\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSettingsServiceRequest extends FormRequest
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
        $business = $this->user()?->currentBusiness();

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
            'provider_ids.*' => [
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($business) {
                    if (! $business) {
                        $fail(__('Invalid business context.'));

                        return;
                    }

                    $exists = $business->providers()->where('id', $value)->exists();

                    if (! $exists) {
                        $fail(__('The selected provider is invalid.'));
                    }
                },
            ],
        ];
    }
}
