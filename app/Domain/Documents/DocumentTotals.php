<?php

namespace App\Domain\Documents;

use Brick\Math\BigDecimal;

final class DocumentTotals
{
    /** @param list<LineTotals> $lines */
    public function __construct(
        public readonly BigDecimal $subtotal,
        public readonly BigDecimal $discountTotal,
        public readonly BigDecimal $totalExcludingTax,
        public readonly BigDecimal $taxTotal,
        public readonly BigDecimal $totalIncludingTax,
        public readonly BigDecimal $totalPayable,
        public readonly array $lines,
    ) {}

    /** @return array{subtotal: string, discount_total: string, total_excluding_tax: string, tax_total: string, total_including_tax: string, total_payable: string} */
    public function toStrings(): array
    {
        return [
            'subtotal' => Money::str($this->subtotal),
            'discount_total' => Money::str($this->discountTotal),
            'total_excluding_tax' => Money::str($this->totalExcludingTax),
            'tax_total' => Money::str($this->taxTotal),
            'total_including_tax' => Money::str($this->totalIncludingTax),
            'total_payable' => Money::str($this->totalPayable),
        ];
    }
}
