<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreManualBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $business = $this->user()?->currentBusiness();

        return [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'provider_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($business) {
                    if ($value === null) {
                        return;
                    }

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
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
