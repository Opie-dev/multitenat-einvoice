<?php

use App\Data\Requests\Documents\DocumentLineData;
use App\Data\Requests\Documents\DocumentTotalsInputData;
use App\Domain\Documents\TotalsCalculator;
use App\Domain\Documents\TotalsMismatch;

function line(array $o = []): DocumentLineData
{
    return DocumentLineData::from(array_replace([
        'classification_code' => '022', 'description' => 'x', 'quantity' => 1, 'unit_code' => 'C62',
        'unit_price' => '10.00', 'tax_type' => '02',
    ], $o));
}

it('computes line and document totals with half-up rounding', function () {
    $totals = (new TotalsCalculator)->calculate([
        line(['quantity' => 3, 'unit_price' => '3.333', 'tax_rate' => 6]),      // gross 10.00 (9.999→10.00), tax 0.60
        line(['quantity' => 1, 'unit_price' => '100', 'discount_rate' => 10, 'tax_rate' => 8]), // gross 100, disc 10, sub 90, tax 7.20
        line(['quantity' => 2, 'unit_price' => '5', 'tax_type' => '06']),        // no tax
    ]);
    $s = $totals->toStrings();
    expect($s)->toBe([
        'subtotal' => '120.00', 'discount_total' => '10.00', 'total_excluding_tax' => '110.00',
        'tax_total' => '7.80', 'total_including_tax' => '117.80', 'total_payable' => '117.80',
    ]);
    expect((string) $totals->lines[0]->gross)->toBe('10.00')->and((string) $totals->lines[1]->total)->toBe('97.20');
});

it('accepts caller figures within tolerance and rejects mismatches with a pointer', function () {
    $ok = (new TotalsCalculator)->calculate([line(['quantity' => 3, 'unit_price' => '3.333', 'tax_rate' => 6, 'subtotal' => '9.99', 'total' => '10.60'])]);
    expect($ok->toStrings()['total_payable'])->toBe('10.60');

    try {
        (new TotalsCalculator)->calculate([line(['quantity' => 1, 'unit_price' => '10', 'total' => '12.00'])]);
        $this->fail('expected mismatch');
    } catch (TotalsMismatch $e) {
        expect($e->pointer)->toBe('/lines/0/total')->and($e->expected)->toBe('10.00')->and($e->given)->toBe('12.00');
    }
});

it('prefers a supplied tax_amount but checks it against the rate', function () {
    $t = (new TotalsCalculator)->calculate([line(['quantity' => 1, 'unit_price' => '100', 'tax_rate' => 6, 'tax_amount' => '6.01'])]);
    expect((string) $t->lines[0]->taxAmount)->toBe('6.01');
    expect(fn () => (new TotalsCalculator)->calculate([line(['quantity' => 1, 'unit_price' => '100', 'tax_rate' => 6, 'tax_amount' => '9.00'])]))
        ->toThrow(TotalsMismatch::class);
});

it('validates document-level total_payable', function () {
    expect(fn () => (new TotalsCalculator)->calculate([line()], new DocumentTotalsInputData(total_payable: '99')))
        ->toThrow(TotalsMismatch::class);
    $ok = (new TotalsCalculator)->calculate([line()], new DocumentTotalsInputData(total_payable: '10.00'));
    expect($ok->toStrings()['total_payable'])->toBe('10.00');
});
