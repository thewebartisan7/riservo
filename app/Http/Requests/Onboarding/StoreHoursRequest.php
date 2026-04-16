<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\BusinessMemberRole;
use Illuminate\Foundation\Http\FormRequest;

class StoreHoursRequest extends FormRequest
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
            'hours' => ['required', 'array', 'size:7'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:1,7'],
            'hours.*.enabled' => ['required', 'boolean'],
            'hours.*.windows' => ['present', 'array'],
            'hours.*.windows.*.open_time' => ['required', 'date_format:H:i'],
            'hours.*.windows.*.close_time' => ['required', 'date_format:H:i', 'after:hours.*.windows.*.open_time'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hours.*.windows.*.close_time.after' => __('The closing time must be after the opening time.'),
        ];
    }
}
