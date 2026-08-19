<?php

namespace App\Data\Requests\Documents;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class DocumentTotalsInputData extends Data
{
    public function __construct(public int|float|string|null $total_payable = null) {}

    /** @return array<string, mixed> */
    public static function rules(ValidationContext $context): array
    {
        return ['total_payable' => ['nullable', 'numeric']];
    }
}
