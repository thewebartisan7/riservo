<?php

namespace App\Http\Requests\Dashboard\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProviderScheduleRequest extends FormRequest
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
        return [
            'rules' => ['required', 'array', 'size:7'],
            'rules.*.day_of_week' => ['required', 'integer', 'between:1,7'],
            'rules.*.enabled' => ['required', 'boolean'],
            'rules.*.windows' => ['present', 'array'],
            'rules.*.windows.*.open_time' => ['required', 'date_format:H:i'],
            'rules.*.windows.*.close_time' => ['required', 'date_format:H:i', 'after:rules.*.windows.*.open_time'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rules.*.windows.*.close_time.after' => __('The closing time must be after the opening time.'),
        ];
    }
}
