<?php

namespace App\Support;

use App\Enums\BusinessMemberRole;
use App\Models\Business;

/**
 * Authoritative per-request source for the active business and the user's role in it.
 *
 * Populated by App\Http\Middleware\ResolveTenantContext after authentication. Consumed by
 * role middleware, onboarding middleware, Inertia shared props, controllers, Form Requests,
 * and the BelongsToCurrentBusiness validation rule.
 *
 * Bound as a scoped singleton in AppServiceProvider — each HTTP request (and each queued
 * job) receives a fresh instance. The tenant() global helper returns the bound instance.
 */
class TenantContext
{
    private ?Business $business = null;

    private ?BusinessMemberRole $role = null;

    public function business(): ?Business
    {
        return $this->business;
    }

    public function role(): ?BusinessMemberRole
    {
        return $this->role;
    }

    public function businessId(): ?int
    {
        return $this->business?->id;
    }

    public function has(): bool
    {
        return $this->business !== null && $this->role !== null;
    }

    public function set(Business $business, BusinessMemberRole $role): void
    {
        $this->business = $business;
        $this->role = $role;
    }

    public function clear(): void
    {
        $this->business = null;
        $this->role = null;
    }
}
