<?php

namespace App\Tenancy;

use App\Enums\Environment;
use App\Models\Tenant;
use App\Tenancy\Exceptions\NoTenantContext;

class TenantContext
{
    private ?Tenant $tenant = null;

    private ?object $actor = null;

    private Environment $environment = Environment::Production;

    public function bind(?Tenant $tenant, ?object $actor, Environment $environment): void
    {
        $this->tenant = $tenant;
        $this->actor = $actor;
        $this->environment = $environment;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->actor = null;
        $this->environment = Environment::Production;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): Tenant
    {
        return $this->tenant ?? throw new NoTenantContext;
    }

    public function tenantOrNull(): ?Tenant
    {
        return $this->tenant;
    }

    public function actor(): ?object
    {
        return $this->actor;
    }

    public function environment(): Environment
    {
        return $this->environment;
    }
}
