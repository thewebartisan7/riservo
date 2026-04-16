<?php

namespace App\Http\Requests\Dashboard\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProviderServicesRequest extends FormRequest
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
            'service_ids' => ['present', 'array'],
            'service_ids.*' => [
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($business) {
                    if (! $business) {
                        $fail(__('Invalid business context.'));

                        return;
                    }

                    $exists = $business->services()->where('id', $value)->exists();

                    if (! $exists) {
                        $fail(__('The selected service is invalid.'));
                    }
                },
            ],
        ];
    }
}
