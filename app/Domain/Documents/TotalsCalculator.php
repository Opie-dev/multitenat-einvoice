<?php

namespace App\Domain\Documents;

use App\Data\Requests\Documents\DocumentLineData;
use App\Data\Requests\Documents\DocumentTotalsInputData;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class TotalsCalculator
{
    /** @param iterable<int, DocumentLineData> $lines */
    public function calculate(iterable $lines, ?DocumentTotalsInputData $totals = null): DocumentTotals
    {
        $zero = BigDecimal::zero();
        $subtotal = $zero;
        $discountTotal = $zero;
        $taxTotal = $zero;
        $lineTotals = [];
        $i = 0;
        foreach ($lines as $line) {
            $lt = $this->line($line, $i);
            $lineTotals[] = $lt;
            $subtotal = $subtotal->plus($lt->gross);
            $discountTotal = $discountTotal->plus($lt->discount);
            $taxTotal = $taxTotal->plus($lt->taxAmount);
            $i++;
        }
        $excl = $subtotal->minus($discountTotal);
        $incl = $excl->plus($taxTotal);
        $payable = $incl;
        if ($totals?->total_payable !== null) {
            $this->assertClose('/totals/total_payable', $payable, Money::of($totals->total_payable));
        }

        return new DocumentTotals(
            Money::round2($subtotal), Money::round2($discountTotal), Money::round2($excl),
            Money::round2($taxTotal), Money::round2($incl), Money::round2($payable), $lineTotals,
        );
    }

    private function line(DocumentLineData $line, int $index): LineTotals
    {
        $qty = Money::of($line->quantity);
        $price = Money::of($line->unit_price);
        $gross = Money::round2($qty->multipliedBy($price));

        $discount = Money::round2(BigDecimal::zero());
        if ($line->discount_amount !== null) {
            $discount = Money::round2(Money::of($line->discount_amount));
        } elseif ($line->discount_rate !== null) {
            $discount = Money::round2($gross->multipliedBy(Money::of($line->discount_rate))->dividedBy(100, 6, RoundingMode::HALF_UP));
        }
        $sub = $gross->minus($discount);
        if ($line->subtotal !== null) {
            $this->assertClose("/lines/{$index}/subtotal", $sub, Money::of($line->subtotal));
        }

        $rate = $line->tax_rate !== null ? Money::of($line->tax_rate) : null;
        $computedTax = $rate !== null ? Money::round2($sub->multipliedBy($rate)->dividedBy(100, 6, RoundingMode::HALF_UP)) : Money::round2(BigDecimal::zero());
        $tax = $computedTax;
        if ($line->tax_amount !== null) {
            $given = Money::round2(Money::of($line->tax_amount));
            if ($rate !== null) {
                $this->assertClose("/lines/{$index}/tax_amount", $computedTax, $given);
            }
            $tax = $given;
        }
        $total = $sub->plus($tax);
        if ($line->total !== null) {
            $this->assertClose("/lines/{$index}/total", $total, Money::of($line->total));
        }

        return new LineTotals($qty, $price, $gross, $discount, Money::round2($sub), $rate, $tax, Money::round2($total));
    }

    private function assertClose(string $pointer, BigDecimal $expected, BigDecimal $given): void
    {
        if (! Money::withinTolerance($expected, $given)) {
            throw new TotalsMismatch($pointer, Money::str($expected), Money::str($given));
        }
    }
}
