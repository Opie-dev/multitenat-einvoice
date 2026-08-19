<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Resources\ActorData;
use App\Data\Resources\MeData;
use App\Data\Resources\TenantData;
use App\Http\Controllers\Controller;
use App\Tenancy\TenantContext;

class MeController extends Controller
{
    public function __invoke(TenantContext $context): MeData
    {
        $actor = $context->actor();

        return (new MeData(
            actor: $actor === null ? null : ActorData::fromActor($actor),
            tenant: TenantData::fromModel($context->tenant()),
            environment: $context->environment()->value,
        ))->wrap('data');
    }
}
