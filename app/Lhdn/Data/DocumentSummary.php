<?php

namespace App\Lhdn\Data;

final class DocumentSummary
{
    /** @param list<array{code: string, message: string}> $errors */
    public function __construct(public readonly string $uuid, public readonly string $internalId, public readonly ?string $longId, public readonly string $status, public readonly array $errors = []) {}
}
