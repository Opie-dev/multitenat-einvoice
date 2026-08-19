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

    /**
     * What the document actually costs on the wire: MyInvois takes it base64
     * encoded, which inflates it by 4/3, and LHDN's size limits apply to that
     * encoded form — so every size budget must be measured here, not on the raw JSON.
     */
    public function wireSizeBytes(): int
    {
        return (int) (4 * ceil(strlen($this->json) / 3));
    }
}
