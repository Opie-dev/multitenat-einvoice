<?php

namespace App\Actions\Consolidation;

use App\Models\Document;

/**
 * What one issuer's consolidation run actually did. `childrenConsolidated` counts
 * only the receipts linked by *this* run, so a replay that found nothing eligible
 * reports zero rather than the parent's stored total.
 */
final class ConsolidationOutcome
{
    /** @param list<Document> $parents */
    public function __construct(
        public readonly array $parents,
        public readonly int $childrenConsolidated,
    ) {}
}
