<?php

namespace App\Lhdn\Data;

final class SubmissionDocument
{
    public function __construct(public readonly string $internalId, public readonly string $json, public readonly string $hashHex) {}

    public static function fromJson(string $internalId, string $json): self
    {
        return new self($internalId, $json, hash('sha256', $json));
    }

    public function sizeBytes(): int
    {
        return strlen($this->json);
    }
}
