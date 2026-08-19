<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Requests\CreateTenantData;
use App\Data\Resources\TenantData;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;

class TenantController extends Controller
{
    public function store(CreateTenantData $data, AuditLogger $audit): TenantData
    {
        // refresh() so the DB-generated `status` default is reflected on the
        // in-memory model before it is serialised.
        $tenant = Tenant::create($data->toArray())->refresh();

        $audit->record('tenant.created', $tenant);

        return TenantData::fromModel($tenant)->wrap('data');
    }
}
