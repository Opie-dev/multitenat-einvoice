<?php

namespace App\Data\Requests\Documents;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class DocumentLineData extends Data
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        #[Regex('/^\d{3}$/')]
        public string $classification_code,
        #[Max(300)]
        public string $description,
        public int|float|string $quantity,
        #[Max(10)]
        public string $unit_code,
        public int|float|string $unit_price,
        #[Regex('/^(0[1-6]|E)$/')]
        public string $tax_type,
        public int|float|string|null $tax_rate = null,
        public int|float|string|null $tax_amount = null,
        public int|float|string|null $discount_amount = null,
        public int|float|string|null $discount_rate = null,
        #[Max(300)]
        public ?string $tax_exemption_reason = null,
        public int|float|string|null $subtotal = null,
        public int|float|string|null $total = null,
        public ?array $metadata = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(ValidationContext $context): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['required', 'numeric', 'gte:0'],
            'tax_rate' => ['nullable', 'numeric', 'between:0,100'],
            'tax_amount' => ['nullable', 'numeric', 'gte:0'],
            'discount_amount' => ['nullable', 'numeric', 'gte:0'],
            'discount_rate' => ['nullable', 'numeric', 'between:0,100'],
            'tax_exemption_reason' => ['nullable', 'string', 'max:300', 'required_if:tax_type,E'],
            'subtotal' => ['nullable', 'numeric'],
            'total' => ['nullable', 'numeric'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
