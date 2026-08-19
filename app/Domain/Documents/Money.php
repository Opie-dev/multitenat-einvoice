<?php

namespace App\Domain\Documents;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class Money
{
    public static function of(int|float|string $value): BigDecimal
    {
        // Floats go through a fixed-precision string to avoid binary drift (19.99 -> "19.99").
        return BigDecimal::of(is_float($value) ? number_format($value, 6, '.', '') : (string) $value)->strippedOfTrailingZeros();
    }

    public static function round2(BigDecimal $value): BigDecimal
    {
        return $value->toScale(2, RoundingMode::HALF_UP);
    }

    public static function str(BigDecimal $value, int $scale = 2): string
    {
        return (string) $value->toScale($scale, RoundingMode::HALF_UP);
    }

    public static function withinTolerance(BigDecimal $a, BigDecimal $b, string $tolerance = '0.01'): bool
    {
        return $a->minus($b)->abs()->isLessThanOrEqualTo(BigDecimal::of($tolerance));
    }
}
