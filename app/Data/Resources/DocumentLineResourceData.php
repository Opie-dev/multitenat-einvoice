<?php

namespace App\Data\Resources;

use App\Models\DocumentLine;
use Spatie\LaravelData\Data;

class DocumentLineResourceData extends Data
{
    /** @param array<string, mixed>|null $metadata */
    public function __construct(
        public int $position,
        public string $classification_code,
        public string $description,
        public string $quantity,
        public string $unit_code,
        public string $unit_price,
        public string $discount_amount,
        public ?string $discount_rate,
        public string $tax_type,
        public ?string $tax_rate,
        public string $tax_amount,
        public ?string $tax_exemption_reason,
        public string $subtotal,
        public string $total,
        public ?array $metadata,
    ) {}

    public static function fromModel(DocumentLine $l): self
    {
        return new self(
            $l->position, $l->classification_code, $l->description, $l->quantity, $l->unit_code, $l->unit_price,
            $l->discount_amount, $l->discount_rate, $l->tax_type, $l->tax_rate, $l->tax_amount, $l->tax_exemption_reason,
            $l->subtotal, $l->total, $l->metadata,
        );
    }
}
