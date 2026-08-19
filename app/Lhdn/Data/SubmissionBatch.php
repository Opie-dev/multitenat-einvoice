<?php

namespace App\Lhdn\Data;

final class SubmissionBatch
{
    /** @param list<SubmissionDocument> $documents */
    public function __construct(public readonly array $documents) {}

    public function count(): int
    {
        return count($this->documents);
    }

    public function sizeBytes(): int
    {
        return (int) array_sum(array_map(fn (SubmissionDocument $d) => $d->sizeBytes(), $this->documents));
    }

    /** Total base64-encoded size; this is what LHDN's per-submission limit applies to. */
    public function wireSizeBytes(): int
    {
        return (int) array_sum(array_map(fn (SubmissionDocument $d) => $d->wireSizeBytes(), $this->documents));
    }

    /** @return array{documents: list<array{format: string, documentHash: string, codeNumber: string, document: string}>} */
    public function toPayload(): array
    {
        return ['documents' => array_map(fn (SubmissionDocument $d) => [
            'format' => 'JSON', 'documentHash' => $d->hashHex, 'codeNumber' => $d->internalId, 'document' => base64_encode($d->json),
        ], $this->documents)];
    }
}
