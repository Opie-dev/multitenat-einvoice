<?php

namespace App\Data\Resources;

use Spatie\LaravelData\Data;

class MeData extends Data
{
    public function __construct(
        public ?ActorData $actor,
        public TenantData $tenant,
        public string $environment,
    ) {}
}
