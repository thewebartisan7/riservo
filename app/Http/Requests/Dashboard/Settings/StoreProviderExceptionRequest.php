<?php

namespace App\Http\Requests\Dashboard\Settings;

use App\Enums\ExceptionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an availability exception create payload.
 *
 * Authorization is enforced by route middleware: ProviderController routes sit
 * under the admin-only sub-group; AvailabilityController routes sit under the
 * shared admin+staff sub-group (D-096).
 */
class StoreProviderExceptionRequest extends FormRequest
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
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'start_time' => ['nullable', 'date_format:H:i', 'required_with:end_time'],
            'end_time' => ['nullable', 'date_format:H:i', 'required_with:start_time', 'after:start_time'],
            'type' => ['required', Rule::enum(ExceptionType::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
