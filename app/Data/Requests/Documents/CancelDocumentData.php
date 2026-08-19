<?php

namespace App\Data\Requests\Documents;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

class CancelDocumentData extends Data
{
    public function __construct(#[Max(300)] public string $reason) {}
}
