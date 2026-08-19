<?php

namespace App\Data\Requests;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Unique;
use Spatie\LaravelData\Data;

class CreateTenantData extends Data
{
    public function __construct(
        #[Max(255)]
        public string $name,
        #[Max(64), Unique('tenants', 'billplz_account_id')]
        public ?string $billplz_account_id = null,
    ) {}
}
