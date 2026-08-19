<?php

namespace App\Lhdn\Data;

final class SubmissionResult
{
    /**
     * @param  array<string, string>  $acceptedUuidsByInternalId
     * @param  array<string, array{code: string, message: string}>  $rejectedByInternalId
     */
    public function __construct(public readonly string $submissionUid, public readonly array $acceptedUuidsByInternalId, public readonly array $rejectedByInternalId) {}
}
