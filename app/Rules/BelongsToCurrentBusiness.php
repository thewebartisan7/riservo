<?php

namespace App\Rules;

use App\Models\Provider;
use App\Models\Service;
use App\Support\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Validates that a foreign-key value names a record owned by the currently-resolved
 * business. Replaces 8 near-identical closures that were scattered across Form Requests.
 *
 * Usage:
 *   'provider_id' => ['integer', new BelongsToCurrentBusiness(Provider::class)]
 *
 * Soft-deleted rows are invisible to the default Eloquent query, so a soft-deleted
 * provider cannot be attached to a new booking / service — matching the product intent.
 * Override the column when the payload names something other than `id`:
 *   new BelongsToCurrentBusiness(Service::class, 'slug')
 *
 * Depends on TenantContext being populated by ResolveTenantContext middleware. When the
 * context is empty (unauthenticated context, stray dev invocation), the rule fails with
 * a distinct "invalid tenant" message rather than leaking rows across tenants.
 */
class BelongsToCurrentBusiness implements ValidationRule
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    public function __construct(
        private readonly string $modelClass,
        private readonly string $column = 'id',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $tenant = app(TenantContext::class);

        if (! $tenant->has()) {
            $fail(__('Invalid business context.'));

            return;
        }

        $exists = $this->modelClass::query()
            ->where($this->column, $value)
            ->where('business_id', $tenant->businessId())
            ->exists();

        if (! $exists) {
            $fail($this->resolveFailureMessage());
        }
    }

    private function resolveFailureMessage(): string
    {
        return match ($this->modelClass) {
            Provider::class => __('The selected provider is invalid.'),
            Service::class => __('The selected service is invalid.'),
            default => __('The selected :attribute is invalid.'),
        };
    }
}
