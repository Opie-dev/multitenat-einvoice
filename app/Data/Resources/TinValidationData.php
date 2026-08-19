<?php

namespace App\Data\Resources;

use Spatie\LaravelData\Data;

class TinValidationData extends Data
{
    public function __construct(
        public string $tin,
        public string $id_type,
        public string $id_number,
        public bool $valid,
        public string $checked_at,
        public bool $cached,
    ) {}
}
