<?php

namespace App\Data\Requests\Tin;

use App\Enums\IdType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

class ValidateTinData extends Data
{
    public function __construct(
        #[Max(20)] public string $tin,
        public IdType $id_type,
        #[Max(30)] public string $id_number,
        #[Max(26)] public ?string $issuer_id = null,
    ) {}
}
