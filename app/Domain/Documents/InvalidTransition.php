<?php

namespace App\Domain\Documents;

use App\Enums\DocumentStatus;
use RuntimeException;

final class InvalidTransition extends RuntimeException
{
    public function __construct(public readonly DocumentStatus $from, public readonly DocumentStatus $to)
    {
        parent::__construct("Cannot transition document from {$from->value} to {$to->value}.");
    }
}
