<?php

namespace App\Lhdn\Data;

final class DocumentDetails
{
    /** @param list<array{code: string, message: string, target?: string}> $validationErrors */
    public function __construct(public readonly string $uuid, public readonly string $status, public readonly ?string $longId, public readonly array $validationErrors = []) {}
}
