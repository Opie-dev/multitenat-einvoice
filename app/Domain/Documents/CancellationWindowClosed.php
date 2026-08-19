<?php

namespace App\Domain\Documents;

use RuntimeException;

final class CancellationWindowClosed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The 72-hour cancellation window has closed; issue a credit or refund note instead.');
    }
}
