<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        $column = $model->qualifyColumn('tenant_id');

        if ($context->has()) {
            $builder->where($column, $context->tenant()->getKey());
        } else {
            // Fail closed: no context → no rows.
            $builder->whereRaw('1 = 0');
        }
    }
}
