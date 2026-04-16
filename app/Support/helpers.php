<?php

use App\Support\TenantContext;

if (! function_exists('tenant')) {
    /**
     * Resolve the per-request TenantContext singleton.
     *
     * Populated by App\Http\Middleware\ResolveTenantContext on authenticated web requests.
     * On unauthenticated / customer-only requests the context stays empty; callers must
     * guard with tenant()->has() before reading business() / role() / businessId().
     */
    function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }
}
