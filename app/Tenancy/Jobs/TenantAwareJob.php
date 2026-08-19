<?php

namespace App\Tenancy\Jobs;

use App\Tenancy\TenantContext;

/**
 * Give a queued job the tenant + environment it was dispatched under, and
 * re-bind them (via BindTenantContext middleware) when the job runs.
 * Call captureTenantContext() in the job constructor.
 */
trait TenantAwareJob
{
    public string $tenantId;

    public string $tenantEnvironment;

    protected function captureTenantContext(): void
    {
        $context = app(TenantContext::class);
        $this->tenantId = $context->tenant()->getKey(); // throws NoTenantContext when unbound
        $this->tenantEnvironment = $context->environment()->value;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new BindTenantContext];
    }
}
