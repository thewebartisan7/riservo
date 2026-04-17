<?php

namespace App\Http\Requests\Dashboard\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a 7-day schedule payload for a provider.
 *
 * Authorization is enforced by route middleware: ProviderController routes sit
 * under the admin-only sub-group; AvailabilityController routes sit under the
 * shared admin+staff sub-group. Both controllers re-derive the active provider
 * from auth()->user() + tenant()->business() so a misconfigured route cannot
 * write to another person's data (D-096).
 */
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
