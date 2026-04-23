<?php

namespace App\Http\Requests\Booking;

use App\Models\Business;
use App\Models\Provider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePublicBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        // Public route — no auth, no session pin, no TenantContext. Business is resolved
        // from the URL slug, so the shared BelongsToCurrentBusiness rule (which reads
        // TenantContext) does not apply here. The inline closure stays.
        $slug = $this->route('slug');
        $businessId = $slug ? Business::where('slug', $slug)->value('id') : null;

        return [
            'service_id' => ['required', 'integer'],
            'provider_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($businessId) {
                    if ($value === null) {
                        return;
                    }

                    if (! $businessId) {
                        $fail(__('Invalid business context.'));

                        return;
                    }

                    $exists = Provider::where('id', $value)
                        ->where('business_id', $businessId)
                        ->exists();

                    if (! $exists) {
                        $fail(__('The selected provider is invalid.'));
                    }
                },
            ],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'website' => ['nullable', 'string'],
            // PAYMENTS Session 2a: only meaningful when the Business's
            // payment_mode = 'customer_choice'. Absent or null means the
            // Business's own payment_mode decides the branch (the online /
            // offline fork happens server-side in PublicBookingController).
            'payment_choice' => ['sometimes', 'nullable', Rule::in(['online', 'offline'])],
        ];
    }
}
