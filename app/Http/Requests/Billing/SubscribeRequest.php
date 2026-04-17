<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class SubscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', 'in:monthly,annual'],
        ];
    }
}
