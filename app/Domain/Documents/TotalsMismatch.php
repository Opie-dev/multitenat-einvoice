<?php

namespace App\Domain\Documents;

use RuntimeException;

final class TotalsMismatch extends RuntimeException
{
    public function __construct(public readonly string $pointer, public readonly string $expected, public readonly string $given)
    {
        parent::__construct("Value at {$pointer} was {$given}, expected {$expected}.");
    }
}
