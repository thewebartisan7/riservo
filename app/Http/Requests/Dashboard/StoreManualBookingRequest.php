<?php

namespace App\Http\Requests\Dashboard;

use App\Models\Provider;
use App\Models\Service;
use App\Rules\BelongsToCurrentBusiness;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreManualBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return tenant()->has();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'service_id' => ['required', 'integer', new BelongsToCurrentBusiness(Service::class)],
            'provider_id' => ['nullable', 'integer', new BelongsToCurrentBusiness(Provider::class)],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
