<?php

namespace App\Http\Requests\Dashboard\Settings;

use App\Enums\BusinessMemberRole;
use App\Models\Service;
use App\Rules\BelongsToCurrentBusiness;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProviderServicesRequest extends FormRequest
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
            'service_ids' => ['present', 'array'],
            'service_ids.*' => ['integer', new BelongsToCurrentBusiness(Service::class)],
        ];
    }
}
