<?php

namespace App\Lhdn\Data;

final class SubmissionStatus
{
    /** @param list<DocumentSummary> $documents */
    public function __construct(public readonly string $overallStatus, public readonly array $documents) {}

    public function isFinal(): bool
    {
        return strtolower($this->overallStatus) !== 'in progress';
    }
}
