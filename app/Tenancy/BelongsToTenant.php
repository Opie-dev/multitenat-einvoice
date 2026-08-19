<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Tenancy\Exceptions\NoTenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @mixin Model */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }
            $context = app(TenantContext::class);
            if (! $context->has()) {
                throw new NoTenantContext;
            }
            $model->setAttribute('tenant_id', $context->tenant()->getKey());
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
