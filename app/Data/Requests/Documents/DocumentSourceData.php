<?php

namespace App\Data\Requests\Documents;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

class DocumentSourceData extends Data
{
    public function __construct(
        #[Max(50)] public string $system,
        #[Max(191)] public string $ref,
    ) {}
}
