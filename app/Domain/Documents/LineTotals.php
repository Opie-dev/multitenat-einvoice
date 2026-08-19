<?php

namespace App\Domain\Documents;

use Brick\Math\BigDecimal;

final class LineTotals
{
    public function __construct(
        public readonly BigDecimal $quantity,
        public readonly BigDecimal $unitPrice,
        public readonly BigDecimal $gross,
        public readonly BigDecimal $discount,
        public readonly BigDecimal $subtotal,
        public readonly ?BigDecimal $taxRate,
        public readonly BigDecimal $taxAmount,
        public readonly BigDecimal $total,
    ) {}
}
