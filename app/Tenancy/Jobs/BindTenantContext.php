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
     * @param  object{tenantId: string, tenantEnvironment: string}  $job
     */
    public function handle(object $job, Closure $next): mixed
    {
        /** @var TenantContext $context */
        $context = app(TenantContext::class);
        $tenant = Tenant::query()->findOrFail($job->tenantId);
        $context->bind(
            $tenant,
            new Actor('system', $job::class, class_basename($job), ['*']),
            Environment::from($job->tenantEnvironment),
        );

        try {
            return $next($job);
        } finally {
            $context->clear();
        }
    }
}
