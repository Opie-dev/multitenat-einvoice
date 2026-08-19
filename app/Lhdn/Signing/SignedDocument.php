<?php

namespace App\Lhdn\Signing;

final class SignedDocument
{
    /** @param array<string, mixed> $document */
    public function __construct(public readonly array $document, public readonly string $json, public readonly string $hashHex) {}
}
