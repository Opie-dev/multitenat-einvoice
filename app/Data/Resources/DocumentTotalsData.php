<?php

namespace App\Data\Resources;

use App\Models\Document;
use Spatie\LaravelData\Data;

class DocumentTotalsData extends Data
{
    public function __construct(
        public string $subtotal,
        public string $discount_total,
        public string $total_excluding_tax,
        public string $tax_total,
        public string $total_including_tax,
        public string $total_payable,
    ) {}

    public static function fromModel(Document $d): self
    {
        return new self($d->subtotal, $d->discount_total, $d->total_excluding_tax, $d->tax_total, $d->total_including_tax, $d->total_payable);
    }
}
