<?php

use App\Domain\Documents\Money;
use Brick\Math\BigDecimal;

it('parses ints, floats and numeric strings without float drift', function () {
    expect(Money::of('0.1')->plus(Money::of('0.2'))->isEqualTo(BigDecimal::of('0.3')))->toBeTrue()
        ->and(Money::of(3)->toScale(2)->__toString())->toBe('3.00')
        ->and(Money::of(19.99)->__toString())->toBe('19.99');
});

it('rounds half-up to 2 dp and formats fixed scale', function () {
    expect(Money::str(Money::round2(Money::of('2.345'))))->toBe('2.35')
        ->and(Money::str(Money::round2(Money::of('2.344'))))->toBe('2.34')
        ->and(Money::str(Money::of('7'), 4))->toBe('7.0000');
});

it('compares within tolerance', function () {
    expect(Money::withinTolerance(Money::of('10.00'), Money::of('10.01')))->toBeTrue()
        ->and(Money::withinTolerance(Money::of('10.00'), Money::of('10.02')))->toBeFalse();
});
