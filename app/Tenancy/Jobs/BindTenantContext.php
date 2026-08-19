<?php

namespace App\Tenancy\Jobs;

use App\Auth\Actor;
use App\Enums\Environment;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;

class BindTenantContext
{
    /**
     * The job is any class using the TenantAwareJob trait; PHPStan cannot
     * analyse that trait as a type unless a class in the analysed `app`
     * path uses it, so the shape it guarantees is spelled out here instead.
     *
     * If the tenant named by $job->tenantId was deleted between dispatch and
     * execution, findOrFail() throws ModelNotFoundException and the job
     * fails loudly rather than silently running with no/wrong tenant bound.
     *
     * On the sync queue connection (or via dispatch_sync()), the job runs
     * inline inside whatever call dispatched it — often a request that
     * already has its own tenant context bound (e.g. a controller action
     * that triggers an event whose listener dispatches this job). Blindly
     * clearing the context in `finally` would wipe out that caller's
     * context out from under it once the job finishes. So the previous
     * context is snapshotted before rebinding and restored afterwards
     * (restoring "no context" as a clear() when nothing was bound before);
     * on the real queue-worker path there is no ambient context to restore,
     * so this degrades to the old "always clear" behaviour.
     *
     * @param  object{tenantId: string, tenantEnvironment: string}  $job
     */
    public function handle(object $job, Closure $next): mixed
    {
        /** @var TenantContext $context */
        $context = app(TenantContext::class);
        $previousTenant = $context->tenantOrNull();
        $previousActor = $context->actor();
        $previousEnvironment = $context->environment();

        $tenant = Tenant::query()->findOrFail($job->tenantId);
        $context->bind(
            $tenant,
            new Actor('system', $job::class, class_basename($job), ['*']),
            Environment::from($job->tenantEnvironment),
        );

        try {
            return $next($job);
        } finally {
            if ($previousTenant !== null) {
                $context->bind($previousTenant, $previousActor, $previousEnvironment);
            } else {
                $context->clear();
            }
        }
    }
}
