<?php

namespace App\Data\Resources;

use App\Models\Tenant;
use Spatie\LaravelData\Data;

class TenantData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $billplz_account_id,
        public string $status,
        public ?string $created_at,
    ) {}

    public static function fromModel(Tenant $tenant): self
    {
        return new self(
            id: $tenant->id,
            name: $tenant->name,
            billplz_account_id: $tenant->billplz_account_id,
            status: $tenant->status,
            created_at: $tenant->created_at->toIso8601String(),
        );
    }
}
