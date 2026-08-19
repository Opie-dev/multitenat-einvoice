<?php

namespace App\Data\Requests\Documents;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class DocumentLineData extends Data
{
    /** @param array<string, mixed>|null $metadata */
    public function __construct(
        #[Regex('/^\d{3}$/')] public string $classification_code,
        #[Max(300)] public string $description,
        public int|float|string $quantity,
        #[Max(10)] public string $unit_code,
        public int|float|string $unit_price,
        #[Regex('/^(0[1-6]|E)$/')] public string $tax_type,
        public int|float|string|null $tax_rate = null,
        public int|float|string|null $tax_amount = null,
        public int|float|string|null $discount_amount = null,
        public int|float|string|null $discount_rate = null,
        #[Max(300)] public ?string $tax_exemption_reason = null,
        public int|float|string|null $subtotal = null,
        public int|float|string|null $total = null,
        public ?array $metadata = null,
    ) {}

    /**
     * Numeric bounds + the conditional exemption reason. Defaulted properties are
     * skipped by the inferrer when absent, so every conditional rule is stated here.
     *
     * When this class validates as an item of a DataCollection (lines.*), Laravel's
     * string-based `required_if:tax_type,E` resolves `tax_type` against the *root*
     * payload, not the current line, because the wildcard index substitution only
     * rewrites parameters that themselves contain `*`. Use Rule::requiredIf() with
     * a closure over $context->payload (the current line's own data, scoped
     * correctly for both the top-level and collection-item cases) instead.
     *
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['required', 'numeric', 'gte:0'],
            'tax_rate' => ['nullable', 'numeric', 'between:0,100'],
            'tax_amount' => ['nullable', 'numeric', 'gte:0'],
            'discount_amount' => ['nullable', 'numeric', 'gte:0'],
            'discount_rate' => ['nullable', 'numeric', 'between:0,100'],
            'tax_exemption_reason' => [
                'nullable', 'string', 'max:300',
                Rule::requiredIf(fn () => data_get($context->payload, 'tax_type') === 'E'),
            ],
            'subtotal' => ['nullable', 'numeric'],
            'total' => ['nullable', 'numeric'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
