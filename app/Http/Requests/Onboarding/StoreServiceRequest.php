<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\BusinessMemberRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRequest extends FormRequest
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
        $optIn = $this->boolean('provider_opt_in');

        return [
            'name' => ['required', 'string', 'max:255'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'buffer_before' => ['integer', 'min:0', 'max:120'],
            'buffer_after' => ['integer', 'min:0', 'max:120'],
            'slot_interval_minutes' => ['required', 'integer', Rule::in([5, 10, 15, 20, 30, 60])],
            'provider_opt_in' => ['sometimes', 'boolean'],
            'provider_schedule' => [$optIn ? 'required' : 'nullable', 'array', 'size:7'],
            'provider_schedule.*.day_of_week' => [$optIn ? 'required' : 'nullable', 'integer', 'between:1,7'],
            'provider_schedule.*.enabled' => [$optIn ? 'required' : 'nullable', 'boolean'],
            'provider_schedule.*.windows' => [$optIn ? 'present' : 'nullable', 'array'],
            'provider_schedule.*.windows.*.open_time' => [$optIn ? 'required' : 'nullable', 'date_format:H:i'],
            'provider_schedule.*.windows.*.close_time' => [$optIn ? 'required' : 'nullable', 'date_format:H:i', 'after:provider_schedule.*.windows.*.open_time'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider_schedule.*.windows.*.close_time.after' => __('The closing time must be after the opening time.'),
        ];
    }
}
