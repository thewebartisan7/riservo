<?php

namespace App\Http\Requests\Dashboard\Settings;

use App\Enums\AssignmentStrategy;
use App\Enums\ConfirmationMode;
use App\Enums\PaymentMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookingSettingsRequest extends FormRequest
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
            'confirmation_mode' => ['required', Rule::enum(ConfirmationMode::class)],
            'allow_collaborator_choice' => ['required', 'boolean'],
            'cancellation_window_hours' => ['required', 'integer', 'min:0', 'max:168'],
            'payment_mode' => ['required', Rule::enum(PaymentMode::class)],
            'assignment_strategy' => ['required', Rule::enum(AssignmentStrategy::class)],
            'reminder_hours' => ['sometimes', 'array'],
            'reminder_hours.*' => ['integer', Rule::in([1, 24])],
        ];
    }
}
