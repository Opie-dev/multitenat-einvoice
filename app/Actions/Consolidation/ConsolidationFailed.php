<?php

namespace App\Actions\Consolidation;

use RuntimeException;
use Throwable;

/** Names the issuer and currency whose group failed, so the operator alarm can point at it. */
final class ConsolidationFailed extends RuntimeException
{
    public function __construct(
        public readonly string $issuerId,
        public readonly string $currency,
        Throwable $previous,
    ) {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
