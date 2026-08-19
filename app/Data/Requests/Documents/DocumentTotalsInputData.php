<?php

namespace App\Data\Requests\Documents;

use Spatie\LaravelData\Data;

class DocumentTotalsInputData extends Data
{
    public function __construct(public int|float|string|null $total_payable = null) {}

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'total_payable' => ['nullable', 'numeric'],
        ];
    }
}
