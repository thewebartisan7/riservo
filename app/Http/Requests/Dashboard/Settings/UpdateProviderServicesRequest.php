<?php

namespace App\Http\Requests\Dashboard\Settings;

use App\Models\Service;
use App\Rules\BelongsToCurrentBusiness;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a provider service-attachment payload.
 *
 * Authorization is enforced by route middleware: both ProviderController
 * (admin managing others) and AvailabilityController.updateServices (admin
 * managing self) live under the admin-only sub-group. Staff cannot edit which
 * services they perform (D-096 carve-out).
 */
class UpdateProviderServicesRequest extends FormRequest
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
            'service_ids' => ['present', 'array'],
            'service_ids.*' => ['integer', new BelongsToCurrentBusiness(Service::class)],
        ];
    }
}
