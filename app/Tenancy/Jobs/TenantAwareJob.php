<?php

namespace App\Tenancy\Jobs;

use App\Tenancy\TenantContext;

/**
 * Give a queued job the tenant + environment it was dispatched under, and
 * re-bind them (via BindTenantContext middleware) when the job runs.
 * Call captureTenantContext() in the job constructor.
 *
 * Consuming jobs must also use Illuminate\Bus\Queueable (in addition to
 * Dispatchable/InteractsWithQueue/SerializesModels). Without it,
 * Bus\Dispatcher::dispatchSync() has no onConnection() to detect and falls
 * back to dispatchNow(), which never runs the job's middleware() — so on the
 * sync connection (and via dispatch_sync()) BindTenantContext would silently
 * be skipped and the tenant context would never be rebound.
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
        return $this->tenantMiddleware();
    }

    /**
     * Jobs that need middleware of their own compose it on top of this, so the
     * tenant is always bound before anything else runs.
     *
     * @return array<int, object>
     */
    protected function tenantMiddleware(): array
    {
        return [new BindTenantContext];
    }
}
